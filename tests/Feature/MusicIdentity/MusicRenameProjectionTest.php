<?php

declare(strict_types=1);

namespace Tests\Feature\MusicIdentity;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseMusicIdentification;
use App\Models\ReleaseMusicRename;
use App\Services\AudioProcessing\AudioProcessingConfiguration;
use App\Services\AudioProcessing\AudioTagRenamer;
use App\Services\Categorization\CategorizationService;
use App\Services\MusicIdentity\DTO\AcceptedMusicText;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateIdentity;
use App\Services\MusicIdentity\DTO\CandidateSummary;
use App\Services\MusicIdentity\DTO\DecisionReason;
use App\Services\MusicIdentity\DTO\IdentificationDecision;
use App\Services\MusicIdentity\Enums\AcceptedIdentityScope;
use App\Services\MusicIdentity\Enums\IdentificationBand;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\Enums\MusicRenameOutcome;
use App\Services\MusicIdentity\MusicIdentityRetryPolicy;
use App\Services\MusicIdentity\Persistence\IdentificationDecisionStore;
use App\Services\MusicIdentity\Persistence\MusicIdentityLeaseManager;
use App\Services\MusicIdentity\Rename\MusicRenameProjection;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Releases\PreviewGenerationPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionProperty;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The guarded canonical rename of an accepted MusicBrainz album (issue #309): who may be renamed,
 * what the name is, the before/after record, and its reversal.
 */
final class MusicRenameProjectionTest extends TestCase
{
    private const string GROUP = '11111111-1111-4111-8111-111111111111';

    private const string OTHER_GROUP = '22222222-2222-4222-8222-222222222222';

    private const string EDITION = '33333333-3333-4333-8333-333333333333';

    private const int RELEASE = 10;

    private const string TAG_NAME = 'Artist - Album (Deluxe) FLAC';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'music-identity.algorithm_version' => 'music-identity-v2',
        ]);
        Search::spy();
        DB::purge();
        DB::reconnect();
        Carbon::setTestNow('2026-10-08 12:00:00');

        $tables = ProductionTables::fromAuthority();
        $tables->create('releases');
        $tables->create('usenet_groups', ['id', 'forced_root_categories_id']);
        $tables->create('releases_groups', ['releases_id', 'groups_id']);
        foreach (['*_create_release_audio_evidence_tables.php', '*_create_release_music_identification_tables.php', '*_add_accepted_music_text_to_release_music_identifications.php', '*_create_musicbrainz_release_group_genres_table.php', '*_create_musicbrainz_release_tracks_table.php', '*_create_musicbrainz_artist_tables.php', '*_create_release_music_renames_table.php'] as $pattern) {
            $this->migration($pattern)->up();
        }
        DB::table('usenet_groups')->insert(['id' => 1]);
        Event::fake([ReleaseNameFixed::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function provenances(): array
    {
        return [
            'NFO' => [['is_trusted_name' => 1, 'name_source' => 'NFO'], false],
            'PAR2' => [['is_trusted_name' => 1, 'name_source' => 'PAR2'], false],
            'SRR' => [['is_trusted_name' => 1, 'name_source' => 'SRRDB'], false],
            'an unknown historical source' => [['is_trusted_name' => 1, 'name_source' => null], false],
            'an admin edit' => [['is_trusted_name' => 0, 'name_source' => 'Manual'], false],
            'recorded audio tags' => [['is_trusted_name' => 1, 'name_source' => 'Audio tags'], true],
            'no trusted source' => [['is_trusted_name' => 0, 'name_source' => null, 'isrenamed' => 0, 'proc_pp' => 0], true],
        ];
    }

    /** @param  array<string, mixed>  $provenance */
    #[Test]
    #[DataProvider('provenances')]
    public function only_a_name_audio_tags_or_no_trusted_source_set_is_renamed(array $provenance, bool $renamed): void
    {
        $this->release($provenance);
        $this->accept($this->evidence(1), IdentificationStatus::AcceptedReleaseGroup);

        $this->project();

        $this->assertSame($renamed ? 'Artist - Album (1980) FLAC' : self::TAG_NAME, $this->currentName());
        $this->assertSame(
            $renamed ? MusicRenameOutcome::Applied : MusicRenameOutcome::Declined,
            ReleaseMusicRename::query()->sole()->outcome,
            'a trusted name matching the tags\' performer/album prefix is no proof of origin',
        );
    }

    #[Test]
    public function the_rename_paths_record_which_source_set_the_name(): void
    {
        $this->release(['is_trusted_name' => 0, 'name_source' => null, 'isrenamed' => 0, 'proc_pp' => 0]);
        $updates = new ReleaseUpdateService;

        $updates->renameFromAudioTags(self::RELEASE, 'Artist - Album (2001) FLAC', Category::MUSIC_LOSSLESS);
        $this->assertSame(['Audio tags', 1], $this->source());

        $updates->updateRelease(Release::query()->findOrFail(self::RELEASE), 'Artist-Album-2001-GRP', 'NFO name', true, 'NFO, ', true, false);
        $this->assertSame(['NFO', 1], $this->source());

        $this->adminEdit('Artist - Album (typed)');
        $this->assertSame(['Manual', 1], $this->source());
    }

    #[Test]
    public function a_predb_matched_release_is_not_renamed(): void
    {
        $this->release(['predb_id' => 5]);
        $this->accept($this->evidence(1), IdentificationStatus::AcceptedReleaseGroup);

        $this->project();

        $this->assertSame(self::TAG_NAME, $this->currentName());
        $this->assertSame('predb_match', ReleaseMusicRename::query()->sole()->reason?->value);
    }

    #[Test]
    public function a_compilation_is_named_after_the_release_credit_not_a_track_performer(): void
    {
        $this->release(['searchname' => 'Track Artist - Hits MP3']);
        $this->accept(
            $this->evidence(1, [['raw_filename' => '01 - Track Artist - Song.mp3', 'performer' => 'Track Artist', 'album' => 'Hits']]),
            IdentificationStatus::AcceptedReleaseGroup,
            credit: 'Various Artists',
            title: 'Hits',
        );

        $this->project();

        $this->assertSame('Various Artists - Hits (1980) MP3', $this->currentName());
    }

    /** @return array<string, array{array<string, mixed>, ?string}> */
    public static function gateFailures(): array
    {
        return [
            'a margin below the calibrated value' => [['margin' => -1], 'runner_up_margin_below_minimum'],
            'a score below the calibrated value' => [['score' => -1], 'score_below_minimum'],
            'a hard contradiction' => [['contradictions' => ['track_count_mismatch']], 'hard_contradiction'],
            'the calibrated margin' => [['margin' => 0], null],
            'no runner-up' => [['margin' => null], null],
        ];
    }

    /** @param  array<string, mixed>  $gate */
    #[Test]
    #[DataProvider('gateFailures')]
    public function the_decision_must_clear_the_calibrated_album_thresholds_without_a_contradiction(array $gate, ?string $reason): void
    {
        $minimumScore = (int) config('music-identity.scoring.minimum_album_score');
        $minimumMargin = (int) config('music-identity.scoring.minimum_runner_up_margin');
        $this->release();
        $this->accept(
            $this->evidence(1),
            IdentificationStatus::AcceptedReleaseGroup,
            score: $minimumScore + (int) ($gate['score'] ?? 0),
            margin: array_key_exists('margin', $gate) ? ($gate['margin'] === null ? null : $minimumMargin + $gate['margin']) : $minimumMargin,
            contradictions: $gate['contradictions'] ?? [],
        );

        $this->project();

        $this->assertSame($reason === null ? 'Artist - Album (1980) FLAC' : self::TAG_NAME, $this->currentName());
        $this->assertSame($reason, ReleaseMusicRename::query()->sole()->reason?->value);
    }

    /** @return array<string, array{IdentificationStatus, bool, string}> */
    public static function renameInputs(): array
    {
        return [
            'a release-group acceptance' => [IdentificationStatus::AcceptedReleaseGroup, true, 'Artist - Album (1980) FLAC'],
            'an edition acceptance' => [IdentificationStatus::AcceptedEdition, true, 'Artist - Album (1980) FLAC'],
            'a release-group acceptance without dates or format' => [IdentificationStatus::AcceptedReleaseGroup, false, 'Artist - Album'],
            'an edition acceptance without dates or format' => [IdentificationStatus::AcceptedEdition, false, 'Artist - Album'],
        ];
    }

    #[Test]
    #[DataProvider('renameInputs')]
    public function the_name_uses_the_group_title_original_year_and_observed_format_never_edition_details_or_medium(IdentificationStatus $state, bool $known, string $expected): void
    {
        $this->release();
        $tracks = $known
            ? [['raw_filename' => '01 - Song.flac', 'container' => 'FLAC'], ['raw_filename' => '02 - Other.flac']]
            : [['raw_filename' => 'track (13)', 'container' => 'MPEG Audio']];
        $edition = $state === IdentificationStatus::AcceptedEdition;
        $this->accept(
            $this->evidence(1, $tracks),
            $state,
            editionTitle: $edition ? 'Album (2020 Remaster)' : null,
            originalDate: $known ? '1980-01-01' : null,
            editionDate: $edition && $known ? '2020-01-01' : null,
            medium: 'CD',
        );

        $this->project();

        $this->assertSame($expected, $this->currentName());
    }

    #[Test]
    public function a_recording_only_acceptance_is_not_renamed(): void
    {
        $this->release();
        $this->accept($this->evidence(1), IdentificationStatus::AcceptedRecording);

        $this->project();

        $this->assertSame(self::TAG_NAME, $this->currentName());
        $this->assertSame(0, ReleaseMusicRename::query()->count(), 'only an accepted album gets a rename record');
    }

    #[Test]
    public function the_rename_keeps_the_category_and_music_link_trusts_the_name_and_records_every_changed_field_once(): void
    {
        $this->release(['is_trusted_name' => 0, 'name_source' => null, 'isrenamed' => 0, 'proc_pp' => 0, 'videos_id' => 7]);
        $this->accept($this->evidence(1), IdentificationStatus::AcceptedReleaseGroup);
        $this->project();
        $this->project();

        $release = DB::table('releases')->find(self::RELEASE);
        $this->assertSame('Artist - Album (1980) FLAC', $release->searchname);
        $this->assertSame([Category::MUSIC_LOSSLESS, 44, 1, 'MusicBrainz', 1, 1], [
            (int) $release->categories_id, (int) $release->musicinfo_id, (int) $release->is_trusted_name,
            $release->name_source, (int) $release->isrenamed, (int) $release->proc_pp,
        ]);
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
        Event::assertDispatched(ReleaseNameFixed::class, static fn (ReleaseNameFixed $event): bool => $event->categoryOverride === Category::MUSIC_LOSSLESS);
        Search::shouldHaveReceived('updateRelease')->with(self::RELEASE)->twice(); // the decision write, then the rename once

        $record = ReleaseMusicRename::query()->sole();
        $this->assertSame(MusicRenameOutcome::Applied, $record->outcome);
        $this->assertSame(self::TAG_NAME, $record->before['searchname'] ?? null);
        $this->assertSame('Artist - Album (1980) FLAC', $record->after['searchname'] ?? null);
        $this->assertEqualsCanonicalizing(['searchname', 'searchname_normalized', 'display_name', 'is_trusted_name', 'name_source', 'isrenamed', 'proc_pp', 'videos_id'], array_keys($record->after ?? []));
        $this->assertSame([0, null, 7], [(int) $record->before['is_trusted_name'], $record->before['name_source'], (int) $record->before['videos_id']]);
    }

    /** @return array<string, array{string}> the two ways a release gets a new target decision */
    public static function newTargets(): array
    {
        return ['an algorithm version bump' => ['version'], 'a newer evidence revision' => ['evidence']];
    }

    #[Test]
    #[DataProvider('newTargets')]
    public function a_completed_non_album_decision_reverts_the_rename_but_unfinished_attempts_do_not(string $variant): void
    {
        $this->release();
        $first = $this->evidence(1);
        $this->accept($first, IdentificationStatus::AcceptedReleaseGroup);
        $this->project();
        $this->assertSame('Artist - Album (1980) FLAC', $this->currentName());

        if ($variant === 'version') {
            config(['music-identity.algorithm_version' => 'music-identity-v3']);
            $newer = $first;
        } else {
            $newer = $this->evidence(2);
        }
        $this->assertNotNull((new MusicIdentityLeaseManager)->acquire($newer, 'worker-a'));
        $this->project();
        $this->assertSame('Artist - Album (1980) FLAC', $this->currentName(), 'a pending lease with no completed row keeps the name');
        $this->accept($newer, IdentificationStatus::RetryableError, leaseToken: 'worker-a');
        $this->project();
        $this->assertSame('Artist - Album (1980) FLAC', $this->currentName(), 'an unfinished replacement keeps the name');
        $this->assertSame(MusicRenameOutcome::Applied, ReleaseMusicRename::query()->sole()->outcome);

        Carbon::setTestNow(now()->addHour());
        $this->assertNotNull((new MusicIdentityLeaseManager)->acquire($newer, 'worker-b'));
        $this->accept($newer, IdentificationStatus::Unresolved, leaseToken: 'worker-b');
        $this->project();
        $this->project();

        $this->assertSame(self::TAG_NAME, $this->currentName());
        $this->assertSame(['Audio tags', 1], $this->source());
        $record = ReleaseMusicRename::query()->sole();
        $this->assertSame(MusicRenameOutcome::Reverted, $record->outcome);
        $this->assertContains('searchname', $record->restored ?? []);
        Event::assertDispatched(ReleaseNameFixed::class, static fn (ReleaseNameFixed $event): bool => $event->newName === self::TAG_NAME
            && $event->categoryOverride === Category::MUSIC_LOSSLESS);
    }

    #[Test]
    public function a_reversal_after_a_human_edit_leaves_the_human_name_and_everything_else(): void
    {
        $this->release(['is_trusted_name' => 0, 'name_source' => null, 'isrenamed' => 0, 'proc_pp' => 0]);
        $this->accept($this->evidence(1), IdentificationStatus::AcceptedReleaseGroup);
        $this->project();

        $this->adminEdit('Artist - Album (my spelling)');
        $this->accept($this->evidence(2), IdentificationStatus::Unresolved);
        $this->project();

        $release = DB::table('releases')->find(self::RELEASE);
        $this->assertSame(['Artist - Album (my spelling)', 'Manual', 1, 1, 1], [
            $release?->searchname, $release?->name_source, (int) $release?->is_trusted_name, (int) $release?->isrenamed, (int) $release?->proc_pp,
        ]);
        $record = ReleaseMusicRename::query()->sole();
        $this->assertSame(MusicRenameOutcome::Reverted, $record->outcome);
        $this->assertSame([], $record->restored);

        $renamed = $this->tagRenamer()->rename(Release::query()->findOrFail(self::RELEASE), ['performer' => 'Artist', 'album' => 'Album'], 'flac');
        $this->assertFalse($renamed, 'a later tag rename never replaces the human name');
        $this->assertSame('Artist - Album (my spelling)', $this->currentName());
    }

    #[Test]
    public function a_long_multibyte_name_is_cut_on_a_character(): void
    {
        $this->release();
        $this->accept($this->evidence(1), IdentificationStatus::AcceptedReleaseGroup, title: str_repeat('Ü', 300));

        $this->project();

        $name = $this->currentName();
        $this->assertSame(255, mb_strlen($name));
        $this->assertTrue(mb_check_encoding($name, 'UTF-8'));
        $this->assertStringStartsWith('Artist - ÜÜ', $name);
    }

    #[Test]
    public function a_decision_naming_another_album_replaces_the_rename(): void
    {
        $this->release();
        $this->accept($this->evidence(1), IdentificationStatus::AcceptedReleaseGroup);
        $this->project();

        $this->accept($this->evidence(2), IdentificationStatus::AcceptedReleaseGroup, groupId: self::OTHER_GROUP, title: 'Other Album');
        $this->project();

        $this->assertSame('Artist - Other Album (1980) FLAC', $this->currentName());
        $this->assertSame(
            [MusicRenameOutcome::Reverted, MusicRenameOutcome::Applied],
            ReleaseMusicRename::query()->orderBy('id')->pluck('outcome')->all(),
        );
        $this->assertSame(self::TAG_NAME, ReleaseMusicRename::query()->orderByDesc('id')->first()?->before['searchname'] ?? null);
    }

    #[Test]
    public function a_later_tag_rename_does_not_replace_a_musicbrainz_name(): void
    {
        $this->release(['is_trusted_name' => 0, 'name_source' => null, 'isrenamed' => 0, 'proc_pp' => 0]);
        $this->accept($this->evidence(1), IdentificationStatus::AcceptedReleaseGroup);
        $this->project();

        $renamed = $this->tagRenamer()->rename(Release::query()->findOrFail(self::RELEASE), ['performer' => 'Artist', 'album' => 'Album'], 'flac');

        $this->assertFalse($renamed);
        $this->assertSame('Artist - Album (1980) FLAC', $this->currentName());
    }

    #[Test]
    public function the_catch_up_renames_releases_accepted_before_the_rename_existed_once(): void
    {
        $this->release();
        $this->accept($this->evidence(1), IdentificationStatus::AcceptedReleaseGroup);
        $projection = app(MusicRenameProjection::class);

        $this->assertSame(1, $projection->catchUp());
        $this->assertSame('Artist - Album (1980) FLAC', $this->currentName());
        $this->assertSame(0, $projection->catchUp(), 'a decision with a record is not projected again');
        $this->assertSame(1, ReleaseMusicRename::query()->count());
    }

    #[Test]
    public function a_catch_up_pass_with_nothing_changed_selects_nothing(): void
    {
        $this->release();
        $this->accept($this->evidence(1), IdentificationStatus::AcceptedReleaseGroup, groupId: self::OTHER_GROUP);
        $current = $this->evidence(2);
        $this->accept($current, IdentificationStatus::AcceptedReleaseGroup);
        $projection = app(MusicRenameProjection::class);
        $this->assertSame(1, $projection->catchUp());

        // A newer completed row the shared rule ignores: the same evidence under another algorithm version.
        config(['music-identity.algorithm_version' => 'music-identity-v1']);
        $this->accept($current, IdentificationStatus::Unresolved);
        config(['music-identity.algorithm_version' => 'music-identity-v2']);

        $this->assertSame(0, $projection->catchUp(), 'neither the superseded album nor the ignored row is selected');
        $this->assertSame('Artist - Album (1980) FLAC', $this->currentName());
        $this->assertSame(1, ReleaseMusicRename::query()->count());
    }

    /** @param  array<string, mixed>  $overrides */
    private function release(array $overrides = []): void
    {
        DB::table('releases')->insert([
            'id' => self::RELEASE,
            'guid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'name' => 'artist-album-flac',
            'groups_id' => 1,
            'categories_id' => Category::MUSIC_LOSSLESS,
            'fromname' => 'poster@example.test',
            'predb_id' => 0,
            'is_trusted_name' => 1,
            'name_source' => 'Audio tags',
            'isrenamed' => 1,
            'iscategorized' => 1,
            'proc_pp' => 1,
            'videos_id' => 0,
            'tv_episodes_id' => 0,
            'musicinfo_id' => 44,
            'gamesinfo_id' => 0,
            ...Release::searchNameValues((string) ($overrides['searchname'] ?? self::TAG_NAME)),
            ...$overrides,
        ]);
    }

    /** @param  list<array<string, mixed>>|null  $tracks */
    private function evidence(int $revision, ?array $tracks = null): ReleaseAudioEvidence
    {
        $evidence = ReleaseAudioEvidence::query()->create([
            'releases_id' => self::RELEASE,
            'revision' => $revision,
            'evidence_hash' => hash('sha256', self::RELEASE.':'.$revision),
            'schema_version' => 1,
            'provenance' => 'captured',
            'release_snapshot' => [],
            'archive_manifest_complete' => true,
            'nzb_manifest' => [],
            'archive_manifest' => [],
            'sidecar_manifest' => [],
            'captured_at' => now(),
        ]);
        $tracks ??= [['raw_filename' => '01 - Song.flac', 'container' => 'FLAC', 'performer' => 'Artist', 'album' => 'Album']];
        foreach ($tracks as $ordinal => $track) {
            DB::table('release_audio_evidence_tracks')->insert([
                'release_audio_evidence_id' => $evidence->id,
                'source_kind' => 'nzb',
                'source_ordinal' => $ordinal,
                ...$track,
            ]);
        }

        return $evidence;
    }

    /** @param  list<string>  $contradictions */
    private function accept(
        ReleaseAudioEvidence $evidence,
        IdentificationStatus $status,
        ?int $score = null,
        ?int $margin = 5,
        array $contradictions = [],
        string $groupId = self::GROUP,
        string $credit = 'Artist',
        string $title = 'Album',
        ?string $editionTitle = null,
        ?string $originalDate = '1980-01-01',
        ?string $editionDate = null,
        string $medium = 'CD',
        ?string $leaseToken = null,
    ): ReleaseMusicIdentification {
        $score ??= 97;
        $accepted = in_array($status, IdentificationStatus::accepted(), true);
        $edition = $status === IdentificationStatus::AcceptedEdition;
        $retryable = $status === IdentificationStatus::RetryableError;
        $identity = new CandidateIdentity(releaseId: $edition ? self::EDITION : null, releaseGroupId: $groupId);
        $text = match (true) {
            $status === IdentificationStatus::AcceptedRecording => new AcceptedMusicText(AcceptedIdentityScope::Recording, 'Song', artistCredit: $credit),
            $accepted => new AcceptedMusicText(
                scope: $edition ? AcceptedIdentityScope::Edition : AcceptedIdentityScope::ReleaseGroup,
                title: $title,
                editionTitle: $editionTitle,
                aliases: ['Album Alias'],
                artistCredit: $credit,
                originalReleaseDate: $originalDate,
                editionReleaseDate: $editionDate,
            ),
            default => null,
        };

        return (new IdentificationDecisionStore)->persist(
            releaseId: self::RELEASE,
            evidence: new AudioEvidenceSet((int) $evidence->id, (string) $evidence->evidence_hash, null, null, null, null, []),
            decision: new IdentificationDecision(
                status: $status,
                score: $retryable ? 0 : $score,
                band: $retryable ? IdentificationBand::Unresolved : IdentificationBand::fromScore($score),
                acceptedIdentity: $accepted ? $identity : null,
                reasons: [new DecisionReason('fixture', 'fixture')],
                candidates: $retryable ? [] : [new CandidateSummary(
                    identity: $identity,
                    score: $score,
                    displaySnapshot: ['title' => $title, 'medium' => $medium],
                    featureVector: [],
                    scoreContributions: [],
                    contradictions: $contradictions,
                    provenanceFamilies: [],
                )],
                runnerUpMargin: $margin,
                algorithmVersion: (string) config('music-identity.algorithm_version'),
                resolverVersion: 'resolver-v1',
                normalizerVersion: 'normalizer-v1',
                scorerVersion: 'whole-release-v1',
                policyVersion: 'shadow-v1',
                operationalError: $retryable ? 'mirror unavailable' : null,
                acceptedText: $text,
            ),
            nextAttemptAt: $retryable ? (new MusicIdentityRetryPolicy)->nextAttemptAt(0) : null,
            leaseToken: $leaseToken,
        );
    }

    private function project(): void
    {
        app(MusicRenameProjection::class)->project(self::RELEASE);
    }

    private function adminEdit(string $searchName): void
    {
        $release = Release::query()->findOrFail(self::RELEASE);
        Release::updateRelease(self::RELEASE, $release->name, $searchName, $release->fromname, $release->categories_id, 1, 0, 0, null, null, 0, 0, null, null);
    }

    private function currentName(): string
    {
        return (string) DB::table('releases')->where('id', self::RELEASE)->value('searchname');
    }

    /** @return array{0: ?string, 1: int} */
    private function source(): array
    {
        $release = DB::table('releases')->where('id', self::RELEASE)->first(['name_source', 'is_trusted_name']);

        return [$release?->name_source, (int) $release?->is_trusted_name];
    }

    private function tagRenamer(): AudioTagRenamer
    {
        $config = (new ReflectionClass(AudioProcessingConfiguration::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(AudioProcessingConfiguration::class, 'renameMusicMediaInfo'))->setValue($config, true);
        (new ReflectionProperty(AudioProcessingConfiguration::class, 'echoCLI'))->setValue($config, false);

        return new AudioTagRenamer($config, new CategorizationService, new ReleaseUpdateService, new PreviewGenerationPolicy);
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
