<?php

declare(strict_types=1);

namespace Tests\Feature\MusicIdentity;

use App\Models\Category;
use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseMusicIdentification;
use App\Services\AudioProcessing\AudioRouting;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\MusicIdentityCandidateQuery;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MusicIdentityCandidateQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'music-identity.algorithm_version' => 'music-identity-v1',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('forced_root_categories_id')->nullable();
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
            $table->char('leftguid', 1);
            $table->unsignedInteger('groups_id');
            $table->unsignedInteger('categories_id');
            $table->integer('musicinfo_id')->nullable();
            $table->string('additional_pp_claim_token')->nullable();
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->timestamp('postdate')->nullable();
        });
        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('groups_id');
        });

        $this->migration('*_create_release_audio_evidence_tables.php')->up();
        $this->migration('*_create_release_music_identification_tables.php')->up();
        $this->migration('*_create_release_music_synthesis_attempts_table.php')->up();

        DB::table('usenet_groups')->insert([
            ['id' => 1, 'forced_root_categories_id' => null],
            ['id' => 2, 'forced_root_categories_id' => Category::MUSIC_ROOT],
            ['id' => 3, 'forced_root_categories_id' => Category::MOVIE_ROOT],
        ]);
    }

    #[Test]
    public function it_admits_the_audio_routed_corpus_without_legacy_category_or_musicinfo_sentinels(): void
    {
        $this->release(1, Category::MUSIC_MP3, 'a', musicInfoId: 91);
        $this->release(2, Category::MUSIC_AUDIOBOOK, 'b', musicInfoId: -2);
        $this->release(3, Category::MUSIC_PODCAST, 'c');
        $this->release(4, Category::MUSIC_FOREIGN, 'd');
        $this->release(5, Category::MOVIE_HD, 'e', groupId: 2);
        $this->release(6, Category::MUSIC_VIDEO, 'f');
        $this->release(7, Category::MUSIC_LOSSLESS, '0', claimToken: AudioRouting::DECLINED_TOKEN);
        $this->release(8, Category::MUSIC_OTHER, '1', groupId: 3);

        $this->assertSame(
            [1, 2, 3, 4, 5],
            MusicIdentityCandidateQuery::query()->orderBy('r.id')->pluck('r.id')->all(),
        );
    }

    #[Test]
    public function the_latest_evidence_revision_and_algorithm_version_define_replay_eligibility(): void
    {
        $this->release(10, Category::MUSIC_LOSSLESS, 'a');
        $firstEvidence = $this->evidence(10, 1, 'a');

        $this->assertSame([10], MusicIdentityCandidateQuery::query()->pluck('r.id')->all());

        $this->terminalDecision($firstEvidence, 'music-identity-v1');
        $this->assertSame([], MusicIdentityCandidateQuery::query()->pluck('r.id')->all());

        $latestEvidence = $this->evidence(10, 2, 'b');
        $this->assertSame([10], MusicIdentityCandidateQuery::query()->pluck('r.id')->all());

        $this->terminalDecision($latestEvidence, 'music-identity-v1');
        $this->assertSame([], MusicIdentityCandidateQuery::query()->pluck('r.id')->all());

        config(['music-identity.algorithm_version' => 'music-identity-v2']);
        $this->assertSame([10], MusicIdentityCandidateQuery::query()->pluck('r.id')->all());
    }

    #[Test]
    public function retry_time_and_expiring_leases_jointly_control_eligibility(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');
        $this->release(20, Category::MUSIC_MP3, 'a');
        $evidence = $this->evidence(20, 1, 'c');
        $identification = ReleaseMusicIdentification::factory()->create([
            'release_audio_evidence_id' => $evidence->id,
            'releases_id' => 20,
            'evidence_hash' => $evidence->evidence_hash,
            'state' => IdentificationStatus::RetryableError,
            'attempt_count' => 1,
            'lease_token' => null,
            'lease_expires_at' => null,
            'next_attempt_at' => now()->addMinute(),
            'decided_at' => null,
        ]);

        $this->assertSame([], MusicIdentityCandidateQuery::query()->pluck('r.id')->all());

        Carbon::setTestNow(now()->addMinutes(2));
        $identification->update([
            'lease_token' => 'active-worker',
            'lease_expires_at' => now()->addMinute(),
        ]);
        $this->assertSame([], MusicIdentityCandidateQuery::query()->pluck('r.id')->all());

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertSame([20], MusicIdentityCandidateQuery::query()->pluck('r.id')->all());
    }

    #[Test]
    public function bucket_selection_collapses_hot_guid_prefixes_before_applying_the_limit(): void
    {
        $this->release(30, Category::MUSIC_MP3, 'a');
        $this->release(31, Category::MUSIC_LOSSLESS, 'a');
        $this->release(32, Category::MUSIC_PODCAST, 'b');

        $this->assertSame(
            ['a', 'b'],
            array_map(static fn (object $bucket): string => $bucket->id, MusicIdentityCandidateQuery::buckets()),
        );
    }

    private function release(
        int $id,
        int $categoryId,
        string $guidCharacter,
        ?int $musicInfoId = null,
        int $groupId = 1,
        ?string $claimToken = null,
    ): void {
        DB::table('releases')->insert([
            'id' => $id,
            'guid' => str_repeat($guidCharacter, 36),
            'leftguid' => $guidCharacter,
            'groups_id' => $groupId,
            'categories_id' => $categoryId,
            'musicinfo_id' => $musicInfoId,
            'additional_pp_claim_token' => $claimToken,
            'postdate' => now(),
        ]);
    }

    private function evidence(int $releaseId, int $revision, string $hashCharacter): ReleaseAudioEvidence
    {
        return ReleaseAudioEvidence::query()->create([
            'releases_id' => $releaseId,
            'revision' => $revision,
            'evidence_hash' => str_repeat($hashCharacter, 64),
            'schema_version' => 1,
            'provenance' => 'captured',
            'release_snapshot' => [],
            'nzb_manifest' => [],
            'archive_manifest' => [],
            'sidecar_manifest' => [],
            'captured_at' => now(),
        ]);
    }

    private function terminalDecision(ReleaseAudioEvidence $evidence, string $algorithmVersion): void
    {
        ReleaseMusicIdentification::factory()->create([
            'release_audio_evidence_id' => $evidence->id,
            'releases_id' => $evidence->releases_id,
            'evidence_hash' => $evidence->evidence_hash,
            'algorithm_version' => $algorithmVersion,
        ]);
    }

    private function migration(string $pattern): Migration
    {
        $paths = glob(database_path('migrations/'.$pattern)) ?: [];
        $this->assertCount(1, $paths);

        /** @var Migration $migration */
        $migration = require $paths[0];

        return $migration;
    }
}
