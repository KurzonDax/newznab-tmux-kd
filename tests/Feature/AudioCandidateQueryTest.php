<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\AudioProcessing\AudioCandidateQuery;
use App\Services\AudioProcessing\AudioRouting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The two candidate queries have to partition the pending set exactly: a release
 * claimable by neither sits at haspreview = -1 forever, and one claimable by
 * both gets fetched twice.
 */
class AudioCandidateQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            // Deep password inspection off, so the pending sentinel is PASSWD_NONE
            // and fixtures can use a single password status.
            'nntmux_settings.check_passworded_rars' => false,
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->default('');
            $table->unsignedInteger('forced_root_categories_id')->nullable();
        });

        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('guid');
            $table->char('leftguid', 1);
            $table->integer('passwordstatus');
            $table->integer('haspreview');
            $table->integer('nzbstatus');
            $table->unsignedInteger('categories_id');
            $table->unsignedInteger('groups_id')->default(0);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('pp_timeout_count')->default(0);
            $table->dateTime('postdate')->nullable();
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->string('additional_pp_claim_token', 64)->nullable();
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });

        DB::table('settings')->insert([
            ['name' => 'minsizetopostprocess', 'value' => '314572800'],
            ['name' => 'maxsizetopostprocess', 'value' => '107374182400'],
            ['name' => 'releaseprocessingtimeout', 'value' => '120'],
            ['name' => 'maxpptimeoutcount', 'value' => '3'],
        ]);

        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.binaries.sounds.lossless', 'forced_root_categories_id' => null],
            ['id' => 2, 'name' => 'alt.binaries.boneless', 'forced_root_categories_id' => Category::MUSIC_ROOT],
            ['id' => 3, 'name' => 'alt.binaries.multimedia', 'forced_root_categories_id' => null],
        ]);

        $this->resetCandidateQueryCaches();
    }

    protected function tearDown(): void
    {
        $this->resetCandidateQueryCaches();

        parent::tearDown();
    }

    public function test_music_categories_and_forced_music_groups_both_route_to_audio(): void
    {
        $this->seedRelease(1, Category::MUSIC_MP3, groupId: 3);
        $this->seedRelease(2, Category::MOVIE_SD, groupId: 2);

        $this->assertSame([1, 2], $this->audioIds());
        $this->assertSame([], $this->videoIds());
    }

    public function test_a_music_video_release_in_a_plain_group_stays_on_the_video_path(): void
    {
        // Anime posted to alt.binaries.multimedia lands in MUSIC_VIDEO, which is
        // why the audio worker probes before committing -- but a release outside
        // 3xxx in a plain group never reaches it at all.
        $this->seedRelease(1, Category::TV_HD, groupId: 3);

        $this->assertSame([], $this->audioIds());
        $this->assertSame([1], $this->videoIds());
    }

    public function test_the_two_queries_partition_a_mixed_set_with_no_overlap_and_no_gap(): void
    {
        $this->seedRelease(1, Category::MUSIC_LOSSLESS, groupId: 1);
        $this->seedRelease(2, Category::MUSIC_VIDEO, groupId: 3);
        $this->seedRelease(3, Category::MOVIE_HD, groupId: 3);
        $this->seedRelease(4, Category::TV_HD, groupId: 2);
        $this->seedRelease(5, Category::OTHER_MISC, groupId: 3);

        $audio = $this->audioIds();
        $video = $this->videoIds();

        $this->assertSame([], array_intersect($audio, $video), 'No release may be claimable by both paths.');
        $covered = array_values(array_unique([...$audio, ...$video]));
        sort($covered);
        $this->assertSame([1, 2, 3, 4, 5], $covered, 'Every pending release must be claimable by one path.');
    }

    public function test_the_audio_query_applies_no_minimum_size(): void
    {
        // minsizetopostprocess is 300 MB above; the whole point of the audio path
        // is that a five megabyte single is still a release worth previewing.
        $this->seedRelease(1, Category::MUSIC_MP3, groupId: 1, size: 5 * 1024 * 1024);

        $this->assertSame([1], $this->audioIds());
    }

    public function test_the_audio_query_still_applies_the_global_maximum_size(): void
    {
        $this->seedRelease(1, Category::MUSIC_MP3, groupId: 1, size: 250 * 1024 * 1024 * 1024);

        $this->assertSame([], $this->audioIds());
    }

    public function test_a_release_past_the_timeout_threshold_is_not_selected(): void
    {
        $this->seedRelease(1, Category::MUSIC_MP3, groupId: 1, ppTimeoutCount: 3);

        $this->assertSame([], $this->audioIds());
    }

    public function test_a_declined_release_moves_to_the_video_path_and_never_returns(): void
    {
        $this->seedRelease(1, Category::MUSIC_MP3, groupId: 1);

        AudioCandidateQuery::declineToVideoPath(1);

        $row = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(AudioRouting::DECLINED_TOKEN, $row->additional_pp_claim_token);
        $this->assertNull($row->additional_pp_claimed_at, 'The marker must not make the release look claimed.');

        $this->assertSame([], $this->audioIds());
        $this->assertSame([1], $this->videoIds());
    }

    public function test_claiming_stamps_the_worker_token_and_hides_the_release_from_the_other_worker(): void
    {
        $this->seedRelease(1, Category::MUSIC_MP3, groupId: 1);

        $claimed = AudioCandidateQuery::claimBatch('a', 10, 'worker-token');

        $this->assertSame([1], $claimed->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertSame([], $this->audioIds(), 'A freshly claimed release is not offered again.');
        $this->assertSame([], $this->videoIds(), 'A claimed music release never leaks onto the video path.');
    }

    private function seedRelease(
        int $id,
        int $categoryId,
        int $groupId,
        int $size = 1073741824,
        int $ppTimeoutCount = 0,
    ): void {
        DB::table('releases')->insert([
            'id' => $id,
            'guid' => 'guid-'.$id,
            'leftguid' => 'a',
            'passwordstatus' => 0,
            'haspreview' => -1,
            'nzbstatus' => 1,
            'categories_id' => $categoryId,
            'groups_id' => $groupId,
            'size' => $size,
            'pp_timeout_count' => $ppTimeoutCount,
            'postdate' => '2026-01-01 00:00:00',
        ]);
    }

    /**
     * @return list<int>
     */
    private function audioIds(): array
    {
        return AudioCandidateQuery::baseBuilder()
            ->orderBy('r.id')
            ->pluck('r.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function videoIds(): array
    {
        return AdditionalCandidateQuery::baseBuilder()
            ->orderBy('r.id')
            ->pluck('r.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function resetCandidateQueryCaches(): void
    {
        $supportsClaims = new ReflectionProperty(ReleaseClaimant::class, 'supportsClaims');
        $supportsClaims->setValue(null, null);
    }
}
