<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Release;
use App\Models\ReleaseAudioTag;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\AudioProcessing\AudioRouting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

/**
 * releases:requeue-audio-previews pushes existing audio releases back through
 * the dedicated audio post-processing path and clears the legacy/empty preview
 * files as it goes.
 *
 * Fixture ids:
 *  1  MUSIC_MP3, haspreview 0          -> "re-queued from 0"
 *  2  MUSIC_LOSSLESS, haspreview -2    -> "re-queued from -2"
 *  3  MUSIC_MP3, haspreview -1 with the wrong password sentinel -> "pending normalized"
 *  4  MUSIC_MP3, haspreview 0, declined token -> only with --include-declined
 *  5  MOVIE_SD in a plain group, haspreview 0 -> never selected
 *  6  MOVIE_SD in a forced-Music group, haspreview 0 -> selected via the group rule
 *  7  MUSIC_MP3, haspreview 1 -> has a preview, never selected
 *  8  MUSIC_MP3, haspreview -1 with the right sentinel -> already pending, left alone
 *  9  MUSIC_MP3, haspreview -1, declined token -> pending on the video path; only --include-declined
 */
class RequeueAudioPreviewsTest extends TestCase
{
    private const int PLAIN_GROUP = 1;

    private const int FORCED_MUSIC_GROUP = 2;

    private string $audioPath;

    protected function setUp(): void
    {
        parent::setUp();

        $coversRoot = $this->makeTempDirectory('nntmux-covers');
        $this->audioPath = $coversRoot.'/audiosample/';
        mkdir($this->audioPath, 0775, true);

        config([
            'nntmux_settings.covers_path' => $coversRoot,
            // Inspection off: the pending sentinel is PASSWD_NONE (0) and the
            // mismatched one that strands rows is -1.
            'nntmux_settings.check_passworded_rars' => false,
        ]);

        Schema::dropIfExists('release_audio_tags');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('releases_groups');
        Schema::dropIfExists('usenet_groups');
        Schema::dropIfExists('settings');

        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->default('');
            $table->unsignedInteger('forced_root_categories_id')->nullable();
        });
        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('groups_id');
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('guid');
            $table->char('leftguid', 1);
            $table->integer('passwordstatus')->default(0);
            $table->integer('haspreview')->default(-1);
            $table->integer('nzbstatus')->default(1);
            $table->unsignedInteger('categories_id');
            $table->unsignedInteger('groups_id')->default(0);
            $table->unsignedBigInteger('size')->default(1000);
            $table->unsignedInteger('pp_timeout_count')->default(0);
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->string('additional_pp_claim_token', 64)->nullable();
        });
        Schema::create('release_audio_tags', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('releases_id');
            $table->string('album')->nullable();
            $table->string('performer')->nullable();
            $table->unsignedTinyInteger('has_preview')->default(0);
            $table->string('preview_extension', 8)->nullable();
            $table->string('preview_mime', 32)->nullable();
            $table->unsignedSmallInteger('preview_seconds')->nullable();
            $table->unsignedInteger('preview_bytes')->nullable();
            $table->unsignedTinyInteger('has_spectrogram')->default(0);
            $table->timestamps();
        });

        DB::table('usenet_groups')->insert([
            ['id' => self::PLAIN_GROUP, 'name' => 'alt.binaries.sounds.mp3', 'forced_root_categories_id' => null],
            ['id' => self::FORCED_MUSIC_GROUP, 'name' => 'alt.binaries.sounds.lossless', 'forced_root_categories_id' => Category::MUSIC_ROOT],
        ]);

        $this->seedRelease(1, Category::MUSIC_MP3, haspreview: 0);
        $this->seedRelease(2, Category::MUSIC_LOSSLESS, haspreview: -2);
        $this->seedRelease(3, Category::MUSIC_MP3, haspreview: -1, passwordstatus: -1);
        $this->seedRelease(4, Category::MUSIC_MP3, haspreview: 0, claimToken: AudioRouting::DECLINED_TOKEN);
        $this->seedRelease(5, Category::MOVIE_SD, haspreview: 0);
        $this->seedRelease(6, Category::MOVIE_SD, haspreview: 0, groupId: self::FORCED_MUSIC_GROUP);
        $this->seedRelease(7, Category::MUSIC_MP3, haspreview: 1);
        $this->seedRelease(8, Category::MUSIC_MP3, haspreview: -1, passwordstatus: 0);
        $this->seedRelease(9, Category::MUSIC_MP3, haspreview: -1, claimToken: AudioRouting::DECLINED_TOKEN);
        $this->seedRelease(10, Category::MUSIC_MP3, haspreview: -1, ppTimeoutCount: 3);

        $this->resetClaimSupportCache();
    }

    protected function tearDown(): void
    {
        $this->resetClaimSupportCache();

        parent::tearDown();
    }

    #[Test]
    public function dry_run_changes_nothing_and_reports_the_counts(): void
    {
        $before = DB::table('releases')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $this->artisan('releases:requeue-audio-previews')
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('pending normalized: 2')
            ->expectsOutputToContain('re-queued from 0: 2')
            ->expectsOutputToContain('re-queued from -2: 1')
            ->expectsOutputToContain('declined re-queued: 0')
            ->expectsOutputToContain('alt.binaries.sounds.mp3')
            ->expectsOutputToContain('alt.binaries.sounds.lossless')
            ->assertSuccessful();

        $after = DB::table('releases')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->assertSame($before, $after);
    }

    #[Test]
    public function apply_requeues_exactly_the_audio_routed_subset(): void
    {
        $this->artisan('releases:requeue-audio-previews --apply')
            ->expectsOutputToContain('pending normalized: 2')
            ->expectsOutputToContain('re-queued from 0: 2')
            ->expectsOutputToContain('re-queued from -2: 1')
            ->expectsOutputToContain('declined re-queued: 0')
            ->assertSuccessful();

        foreach ([1, 2, 3, 6, 10] as $id) {
            $this->assertPending($id);
        }

        $this->assertSame(0, $this->release(4)->haspreview, 'declined rows need --include-declined');
        $this->assertSame(AudioRouting::DECLINED_TOKEN, $this->release(4)->additional_pp_claim_token);
        $this->assertSame(0, $this->release(5)->haspreview, 'non-audio rows are untouched');
        $this->assertSame(1, $this->release(7)->haspreview);
        $this->assertSame(-1, $this->release(8)->haspreview);
        $this->assertSame(0, $this->release(8)->passwordstatus);
        $this->assertSame(AudioRouting::DECLINED_TOKEN, $this->release(9)->additional_pp_claim_token);
    }

    #[Test]
    public function include_declined_clears_the_decline_marker(): void
    {
        $this->artisan('releases:requeue-audio-previews --apply --include-declined')
            ->expectsOutputToContain('declined re-queued: 2')
            ->expectsOutputToContain('re-queued from 0: 2')
            ->assertSuccessful();

        $this->assertPending(4);
        $this->assertPending(9);
    }

    #[Test]
    public function group_and_category_narrow_and_limit_caps(): void
    {
        $this->artisan('releases:requeue-audio-previews --apply --group='.self::FORCED_MUSIC_GROUP)
            ->assertSuccessful();
        $this->assertPending(6);
        $this->assertSame(0, $this->release(1)->haspreview);

        $this->artisan('releases:requeue-audio-previews --apply --category='.Category::MUSIC_LOSSLESS)
            ->assertSuccessful();
        $this->assertPending(2);
        $this->assertSame(0, $this->release(1)->haspreview);

        $this->artisan('releases:requeue-audio-previews --apply --limit=1')
            ->assertSuccessful();
        $this->assertPending(1);
        $this->assertSame(-1, $this->release(3)->passwordstatus, 'limit stops after the first release');
    }

    #[Test]
    public function apply_deletes_artifacts_and_resets_preview_columns_but_keeps_tags(): void
    {
        file_put_contents($this->audioPath.'guid1.ogg', 'legacy');
        file_put_contents($this->audioPath.'guid1_spectrum.png', 'png');
        file_put_contents($this->audioPath.'guid5.ogg', 'other');

        DB::table('release_audio_tags')->insert([
            'releases_id' => 1,
            'album' => 'Album',
            'performer' => 'Performer',
            'has_preview' => 1,
            'preview_extension' => 'ogg',
            'preview_mime' => 'audio/ogg',
            'preview_seconds' => 30,
            'preview_bytes' => 1234,
            'has_spectrogram' => 1,
        ]);

        $this->artisan('releases:requeue-audio-previews --apply')->assertSuccessful();

        $this->assertFileDoesNotExist($this->audioPath.'guid1.ogg');
        $this->assertFileDoesNotExist($this->audioPath.'guid1_spectrum.png');
        $this->assertFileExists($this->audioPath.'guid5.ogg');

        $tags = ReleaseAudioTag::query()->where('releases_id', 1)->firstOrFail();
        $this->assertSame('Album', $tags->album);
        $this->assertSame('Performer', $tags->performer);
        $this->assertFalse($tags->has_preview);
        $this->assertNull($tags->preview_extension);
        $this->assertNull($tags->preview_mime);
        $this->assertNull($tags->preview_seconds);
        $this->assertNull($tags->preview_bytes);
        $this->assertFalse($tags->has_spectrogram);
    }

    #[Test]
    public function prune_empty_deletes_zero_byte_files_only(): void
    {
        // guid7 has a preview and is never selected, so pruning is the only
        // thing that can touch it; tvguid is not an audio release at all.
        file_put_contents($this->audioPath.'guid7.ogg', '');
        file_put_contents($this->audioPath.'tvguid.ogg', '');
        file_put_contents($this->audioPath.'guid8.mp3', 'kept');

        $this->artisan('releases:requeue-audio-previews --prune-empty')
            ->expectsOutputToContain('files pruned: 2')
            ->assertSuccessful();

        $this->assertFileExists($this->audioPath.'guid7.ogg', 'dry run only reports');
        $this->assertFileExists($this->audioPath.'tvguid.ogg');

        $this->artisan('releases:requeue-audio-previews --apply --prune-empty')
            ->expectsOutputToContain('files pruned: 2')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->audioPath.'guid7.ogg');
        $this->assertFileDoesNotExist($this->audioPath.'tvguid.ogg');
        $this->assertFileExists($this->audioPath.'guid8.mp3');
    }

    #[Test]
    public function dry_run_and_apply_together_fail(): void
    {
        $this->artisan('releases:requeue-audio-previews --dry-run --apply')
            ->expectsOutputToContain('not both')
            ->assertFailed();
    }

    private function seedRelease(
        int $id,
        int $categoryId,
        int $haspreview,
        int $passwordstatus = 0,
        int $groupId = self::PLAIN_GROUP,
        ?string $claimToken = null,
        int $ppTimeoutCount = 2,
    ): void {
        DB::table('releases')->insert([
            'id' => $id,
            'guid' => 'guid'.$id,
            'leftguid' => 'g',
            'passwordstatus' => $passwordstatus,
            'haspreview' => $haspreview,
            'nzbstatus' => 1,
            'categories_id' => $categoryId,
            'groups_id' => $groupId,
            'pp_timeout_count' => $ppTimeoutCount,
            'additional_pp_claim_token' => $claimToken,
        ]);
    }

    private function assertPending(int $id): void
    {
        $release = $this->release($id);

        $this->assertSame(-1, $release->haspreview, "release {$id} should be pending");
        $this->assertSame(0, $release->passwordstatus, "release {$id} should carry the pending sentinel");
        $this->assertSame(0, $release->pp_timeout_count, "release {$id} should reset its attempt counter");
        $this->assertNull($release->additional_pp_claimed_at);
        $this->assertNull($release->additional_pp_claim_token);
    }

    private function release(int $id): Release
    {
        return Release::query()->findOrFail($id);
    }

    private function resetClaimSupportCache(): void
    {
        (new ReflectionProperty(ReleaseClaimant::class, 'supportsClaims'))->setValue(null, null);
    }
}
