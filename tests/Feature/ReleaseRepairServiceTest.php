<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReleaseRepairOutcome;
use App\Facades\Search;
use App\Models\Release;
use App\Services\AdditionalProcessing\Config\PasswordInspectionMode;
use App\Services\NNTP\Contracts\ProviderClient;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseRepair\ReleaseRepairOptions;
use App\Services\ReleaseRepair\ReleaseRepairService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * The repair state machine, end to end: derive the missing message-IDs, confirm a sample of them
 * on a provider, write them back into the stored NZB, and record an outcome the sweep can act on.
 */
class ReleaseRepairServiceTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private string $nzbRoot = '';

    /** Message-IDs the fake provider will confirm. */
    private array $providerArticles = [];

    private bool $leaseObservedDuringProbe = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        $this->nzbRoot = $this->makeTempDirectory('nntmux-repair-nzbs');
        config(['nntmux_settings.path_to_nzbs' => $this->nzbRoot]);

        $this->createSchema();
        Search::spy();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '0', 'nzbsplitlevel' => '1'];
    }

    #[Test]
    public function it_repairs_a_numbered_release_and_records_it_as_repaired(): void
    {
        $release = $this->releaseWithNzb(1, completion: 40.0, segments: [1 => 1, 3 => 3]);
        $this->providerHasEveryArticle();

        $result = $this->service()->repair($release, new ReleaseRepairOptions);

        $this->assertSame(ReleaseRepairOutcome::Repaired, $result->outcome);
        $this->assertSame(3, $result->segmentsAdded);
        $this->assertSame(100.0, $result->completionAfter);
        $this->assertTrue($result->nzbRewritten);

        $this->assertSame('repaired', $this->storedOutcome(1));
        $this->assertSame(95.0, (float) DB::table('releases')->where('id', 1)->value('repair_target_completion'));
        $this->assertSame(95.0, (float) DB::table('releases')->where('id', 1)->value('repair_evaluated_target_completion'));
        $this->assertSame(100.0, (float) DB::table('releases')->where('id', 1)->value('completion'));
        $this->assertStringContainsString('part5of5.Tok@host', $this->storedNzb($release));
    }

    #[Test]
    public function repair_holds_a_recovery_lease_while_working_and_clears_it_afterward(): void
    {
        $release = $this->releaseWithNzb(1, completion: 40.0, segments: [1 => 1, 3 => 3]);
        $this->providerHasEveryArticle();

        $this->service()->repair($release, new ReleaseRepairOptions);

        $this->assertTrue($this->leaseObservedDuringProbe);
        $this->assertNull(DB::table('releases')->where('id', 1)->value('recovery_claimed_at'));
    }

    #[Test]
    public function repair_clears_its_recovery_lease_when_work_throws(): void
    {
        $release = $this->releaseWithNzb(1, completion: 40.0, segments: [1 => 1, 3 => 3]);
        $nzb = \Mockery::mock(NzbService::class);
        $nzb->shouldReceive('readNzbContents')->once()->andReturnUsing(function (): never {
            $this->leaseObservedDuringProbe = DB::table('releases')
                ->where('id', 1)
                ->whereNotNull('recovery_claimed_at')
                ->exists();

            throw new \RuntimeException('read failed');
        });

        try {
            (new ReleaseRepairService($nzb, $this->pool()))->repair($release, new ReleaseRepairOptions);
            $this->fail('The fake NZB service should have interrupted repair.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('read failed', $exception->getMessage());
        }

        $this->assertTrue($this->leaseObservedDuringProbe);
        $this->assertNull(DB::table('releases')->where('id', 1)->value('recovery_claimed_at'));
    }

    #[Test]
    public function a_random_id_poster_yields_retry_pending_and_leaves_the_nzb_untouched(): void
    {
        $release = $this->releaseWithNzb(1, completion: 40.0, messageIds: [
            1 => 'a4f1c9e2b7d3@ngPost',
            3 => '9e17b0d4a2c8@ngPost',
        ]);
        $before = $this->storedNzb($release);
        $this->providerHasEveryArticle();

        $result = $this->service()->repair($release, new ReleaseRepairOptions);

        $this->assertSame(ReleaseRepairOutcome::RetryPending, $result->outcome);
        $this->assertSame(0, $result->segmentsAdded);
        $this->assertSame(0, $result->articlesProbed, 'Unguessable IDs must not cost a single STAT.');
        $this->assertSame($before, $this->storedNzb($release));
    }

    #[Test]
    public function the_second_pass_on_an_unrepairable_release_is_final(): void
    {
        $release = $this->releaseWithNzb(
            1,
            completion: 40.0,
            messageIds: [1 => 'a4f1c9e2b7d3@ngPost', 3 => '9e17b0d4a2c8@ngPost'],
            outcome: ReleaseRepairOutcome::RetryPending,
            attemptedAt: Carbon::now()->subHours(80)->toDateTimeString(),
        );

        $result = $this->service()->repair($release, new ReleaseRepairOptions);

        $this->assertSame(ReleaseRepairOutcome::Failed, $result->outcome);
        $this->assertTrue($result->outcome->isFinal(), 'Only now may the sweep touch it.');
        $this->assertSame('failed', $this->storedOutcome(1));
    }

    #[Test]
    public function a_complete_nzb_reconciles_stale_completion_instead_of_advancing_toward_deletion(): void
    {
        $release = $this->releaseWithNzb(
            1,
            completion: 91.67,
            segments: [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5],
        );

        $result = $this->service()->repair($release, new ReleaseRepairOptions(targetCompletion: 95.0));

        $this->assertSame(ReleaseRepairOutcome::Repaired, $result->outcome);
        $this->assertSame(100.0, $result->completionAfter);
        $this->assertSame('repaired', $this->storedOutcome(1));
        $this->assertSame(100.0, (float) DB::table('releases')->where('id', 1)->value('completion'));
    }

    #[Test]
    public function a_release_below_the_floor_is_skipped_without_touching_the_network(): void
    {
        $release = $this->releaseWithNzb(1, completion: 4.0, segments: [1 => 1]);

        $result = $this->service()->repair($release, new ReleaseRepairOptions);

        $this->assertSame(ReleaseRepairOutcome::SkippedFloor, $result->outcome);
        $this->assertSame(0, $result->articlesProbed, 'Unrecoverable dreck is not worth STATs.');
        $this->assertTrue($result->outcome->isFinal());
        $this->assertSame('skipped-floor', $this->storedOutcome(1));
    }

    #[Test]
    public function a_pass_that_falls_short_of_the_target_is_only_retry_pending(): void
    {
        // 3 of 10 present; repair adds the rest but the provider only confirms one file, so the
        // release improves without reaching the target.
        $release = $this->releaseWithNzb(1, completion: 30.0, segments: [1 => 1, 2 => 2, 3 => 3], declaredTotal: 10);
        $this->providerArticles = ['part4of10.Tok@host' => true];

        $result = $this->service()->repair($release, new ReleaseRepairOptions(targetCompletion: 95.0));

        $this->assertSame(ReleaseRepairOutcome::RetryPending, $result->outcome);
        $this->assertSame('retry-pending', $this->storedOutcome(1));
    }

    #[Test]
    public function a_reopened_repaired_release_that_cannot_reach_the_higher_target_stays_repaired(): void
    {
        $release = $this->releaseWithNzb(
            1,
            completion: 96.0,
            segments: [1 => 1, 2 => 2, 3 => 3],
            outcome: ReleaseRepairOutcome::Repaired,
            targetCompletion: 95.0,
        );

        $result = $this->service()->repair($release, new ReleaseRepairOptions(targetCompletion: 99.0));

        $this->assertSame(ReleaseRepairOutcome::Repaired, $result->outcome);
        $this->assertSame('repaired', $this->storedOutcome(1));
        $stored = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(95.0, (float) $stored->repair_target_completion);
        $this->assertSame(99.0, (float) $stored->repair_evaluated_target_completion);
        $this->assertFalse($result->outcome->isFinal(), 'Raising policy must never make grandfathered content deletable.');
    }

    #[Test]
    public function an_unconfirmed_message_id_keeps_its_file_out_of_the_nzb(): void
    {
        // A wrong template would fill the file with IDs that fail at download time, which is
        // worse than leaving the release short.
        $release = $this->releaseWithNzb(1, completion: 40.0, segments: [1 => 1, 3 => 3]);
        DB::table('releases')->where('id', 1)->update(array_merge([
            'haspreview' => 0,
            'passwordstatus' => 0,
            'nfostatus' => 0,
        ], array_fill_keys($this->nameSourceColumns(), 1)));
        $before = $this->storedNzb($release);
        $this->providerArticles = [];

        $candidate = Release::query()->select([
            'id', 'guid', 'completion', 'haspreview', 'repair_outcome', 'repair_attempted_at',
            'repair_target_completion', 'repair_evaluated_target_completion', 'postdate',
        ])->findOrFail($release->id);
        $result = $this->service()->repair($candidate, new ReleaseRepairOptions);
        $stored = DB::table('releases')->where('id', 1)->first();

        $this->assertSame(0, $result->segmentsAdded);
        $this->assertGreaterThan(0, $result->articlesProbed);
        $this->assertSame($before, $this->storedNzb($release));
        $this->assertSame(0, (int) $stored->haspreview);
        $this->assertSame(0, (int) $stored->passwordstatus);
        $this->assertSame(0, (int) $stored->nfostatus);

        foreach ($this->nameSourceColumns() as $column) {
            $this->assertSame(1, (int) $stored->{$column}, $column.' should remain settled without new evidence.');
        }

        Search::shouldNotHaveReceived('updateRelease');
    }

    #[Test]
    public function a_dry_run_probes_but_writes_nothing(): void
    {
        $release = $this->releaseWithNzb(1, completion: 40.0, segments: [1 => 1, 3 => 3]);
        $before = $this->storedNzb($release);
        $this->providerHasEveryArticle();

        $result = $this->service()->repair($release, new ReleaseRepairOptions(dryRun: true));

        $this->assertSame(ReleaseRepairOutcome::Repaired, $result->outcome);
        $this->assertSame(3, $result->segmentsAdded);
        $this->assertFalse($result->nzbRewritten);
        $this->assertSame($before, $this->storedNzb($release));
        $this->assertNull($this->storedOutcome(1));
        $this->assertSame(40.0, (float) DB::table('releases')->where('id', 1)->value('completion'));
    }

    #[Test]
    public function a_repaired_release_with_no_artifacts_is_re_queued_for_additional_processing(): void
    {
        $release = $this->releaseWithNzb(1, completion: 40.0, segments: [1 => 1, 3 => 3]);
        DB::table('releases')->where('id', 1)->update(array_merge([
            'haspreview' => 0,
            'passwordstatus' => 0,
            'nfostatus' => 0,
        ], array_fill_keys($this->nameSourceColumns(), 1)));
        $this->providerHasEveryArticle();

        $result = $this->service()->repair($release->fresh(), new ReleaseRepairOptions);

        $this->assertTrue($result->requeuedForAdditionalProcessing);
        // Exactly the values AdditionalCandidateQuery selects on, so the release is claimable again.
        $this->assertSame(-1, (int) DB::table('releases')->where('id', 1)->value('haspreview'));
        $this->assertSame(
            PasswordInspectionMode::pendingReleaseStatus(),
            (int) DB::table('releases')->where('id', 1)->value('passwordstatus'),
        );
        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('pp_timeout_count'));
        $stored = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(1, (int) $stored->totalpart);
        Search::shouldHaveReceived('updateRelease')->once()->with(1);
        $this->assertSame(100.0, (float) $stored->completion);
        $this->assertSame(-1, (int) $stored->nfostatus);

        foreach ($this->nameSourceColumns() as $column) {
            $this->assertSame(0, (int) $stored->{$column}, $column.' should be eligible once more.');
        }
    }

    #[Test]
    public function a_release_that_already_has_media_info_is_not_re_queued(): void
    {
        // Additional processing cannot improve on what it already produced, and the slot is
        // better spent on a fresh release.
        $release = $this->releaseWithNzb(1, completion: 40.0, segments: [1 => 1, 3 => 3]);
        DB::table('releases')->where('id', 1)->update(array_merge([
            'haspreview' => 0,
            'passwordstatus' => 0,
            'nfostatus' => 0,
        ], array_fill_keys($this->nameSourceColumns(), 1)));
        DB::table('video_data')->insert(['releases_id' => 1, 'videocodec' => 'h264']);
        $this->providerHasEveryArticle();

        $result = $this->service()->repair($release->fresh(), new ReleaseRepairOptions);

        $this->assertGreaterThan($result->completionBefore, $result->completionAfter);
        $this->assertFalse($result->requeuedForAdditionalProcessing);
        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('haspreview'));
        $stored = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(0, (int) $stored->passwordstatus);
        $this->assertSame(-1, (int) $stored->nfostatus);

        foreach ($this->nameSourceColumns() as $column) {
            $this->assertSame(0, (int) $stored->{$column}, $column.' should reset despite the AP artifact.');
        }
    }

    #[Test]
    public function stored_only_recovery_changes_do_not_sync_the_search_index(): void
    {
        $release = $this->releaseWithNzb(1, completion: 40.0, segments: [1 => 1, 3 => 3]);
        DB::table('releases')->where('id', 1)->update([
            'totalpart' => 1,
        ]);
        DB::table('video_data')->insert(['releases_id' => 1, 'videocodec' => 'h264']);
        $this->providerHasEveryArticle();

        $candidate = Release::query()->select([
            'id', 'guid', 'completion', 'haspreview', 'repair_outcome', 'repair_attempted_at',
            'repair_target_completion', 'repair_evaluated_target_completion', 'postdate',
        ])->findOrFail($release->id);
        $result = $this->service()->repair($candidate, new ReleaseRepairOptions);

        $this->assertSame(100.0, $result->completionAfter);
        $this->assertFalse($result->requeuedForAdditionalProcessing);
        Search::shouldNotHaveReceived('updateRelease');
    }

    #[Test]
    public function successful_name_and_nfo_verdicts_survive_repaired_evidence(): void
    {
        $release = $this->releaseWithNzb(1, completion: 40.0, segments: [1 => 1, 3 => 3]);
        DB::table('releases')->where('id', 1)->update(array_merge([
            'isrenamed' => 1,
            'predb_id' => 42,
            'nfostatus' => 1,
        ], array_fill_keys($this->nameSourceColumns(), 1)));
        $this->providerHasEveryArticle();

        $this->service()->repair($release->fresh(), new ReleaseRepairOptions);
        $stored = DB::table('releases')->where('id', 1)->first();

        $this->assertSame(1, (int) $stored->isrenamed);
        $this->assertSame(42, (int) $stored->predb_id);
        $this->assertSame(1, (int) $stored->nfostatus);
    }

    #[Test]
    public function a_release_with_an_existing_preview_is_not_re_queued(): void
    {
        $release = $this->releaseWithNzb(1, completion: 40.0, segments: [1 => 1, 3 => 3]);
        DB::table('releases')->where('id', 1)->update(['haspreview' => 1, 'passwordstatus' => 0]);
        $this->providerHasEveryArticle();

        $result = $this->service()->repair($release->fresh(), new ReleaseRepairOptions);

        $this->assertFalse($result->requeuedForAdditionalProcessing);
    }

    #[Test]
    public function a_missing_nzb_leaves_the_repair_state_untouched(): void
    {
        // A storage blip says nothing about whether the articles are still on the provider, so
        // it must not advance the state machine at all -- otherwise two unmounted volumes in a
        // row would be enough to mark the release `failed` and hand it to the reaper.
        $release = $this->releaseWithNzb(1, completion: 40.0, segments: [1 => 1, 3 => 3]);
        unlink((string) app(NzbService::class)->nzbPath((string) $release->guid));

        $result = $this->service()->repair($release, new ReleaseRepairOptions);

        $this->assertNull($result->outcome);
        $this->assertNull($this->storedOutcome(1));
        $this->assertNull(DB::table('releases')->where('id', 1)->value('repair_attempted_at'));
        $this->assertNull(DB::table('releases')->where('id', 1)->value('recovery_claimed_at'));
    }

    #[Test]
    public function a_missing_nzb_never_becomes_final_however_often_it_is_seen(): void
    {
        $release = $this->releaseWithNzb(
            1,
            completion: 40.0,
            segments: [1 => 1, 3 => 3],
            outcome: ReleaseRepairOutcome::RetryPending,
            attemptedAt: Carbon::now()->subHours(80)->toDateTimeString(),
        );
        unlink((string) app(NzbService::class)->nzbPath((string) $release->guid));

        $result = $this->service()->repair($release, new ReleaseRepairOptions);

        $this->assertNull($result->outcome, 'The pass never ran, so it cannot be the final one.');
        $this->assertSame('retry-pending', $this->storedOutcome(1));
    }

    #[Test]
    public function an_unparseable_nzb_leaves_the_repair_state_untouched(): void
    {
        $release = $this->releaseWithNzb(1, completion: 40.0, segments: [1 => 1, 3 => 3]);
        file_put_contents(
            (string) app(NzbService::class)->nzbPath((string) $release->guid),
            gzencode('this is not an nzb'),
        );

        $result = $this->service()->repair($release, new ReleaseRepairOptions);

        $this->assertNull($result->outcome);
        $this->assertNull($this->storedOutcome(1));
    }

    #[Test]
    public function stat_probes_are_capped_per_release(): void
    {
        $this->releaseWithNzb(1, completion: 40.0, files: 20, segments: [1 => 1, 3 => 3]);
        $this->providerHasEveryArticle();

        $result = $this->service()->repair(Release::query()->find(1), new ReleaseRepairOptions(maxStatProbes: 6));

        $this->assertLessThanOrEqual(6, $result->articlesProbed);
    }

    #[Test]
    public function a_file_is_never_accepted_on_a_thinner_sample_than_the_rest(): void
    {
        // With a budget of 3 and a sample size of 2, the second file cannot be sampled properly.
        // Accepting it on a single confirmation would be a much weaker argument against a wrong
        // template, so it keeps its missing segments instead.
        $this->releaseWithNzb(1, completion: 40.0, files: 3, segments: [1 => 1, 3 => 3]);
        $this->providerHasEveryArticle();

        $result = $this->service()->repair(
            Release::query()->find(1),
            new ReleaseRepairOptions(statSamplePerFile: 2, maxStatProbes: 3),
        );

        $this->assertSame(2, $result->articlesProbed);
        $this->assertSame(3, $result->segmentsAdded, 'Exactly one file\'s worth of segments.');
    }

    private function service(): ReleaseRepairService
    {
        return new ReleaseRepairService(app(NzbService::class), $this->pool());
    }

    private function pool(): NntpProviderPool
    {
        $articles = &$this->providerArticles;
        $onProbe = function (): void {
            $this->leaseObservedDuringProbe = DB::table('releases')
                ->where('id', 1)
                ->whereNotNull('recovery_claimed_at')
                ->exists();

        };

        $client = new class($articles, $onProbe) implements ProviderClient
        {
            public function __construct(private array &$articles, private readonly \Closure $onProbe) {}

            public function provider(): NntpProvider
            {
                return new NntpProvider(1, 'test', 'news.example.org', 119, false, '', '', 1, 120, true);
            }

            public function doConnect(bool $compression = true): mixed
            {
                return true;
            }

            public function fetchArticleBody(string $messageId): mixed
            {
                return 'body';
            }

            public function statArticle(string $messageId): mixed
            {
                ($this->onProbe)();

                return ($this->articles['*'] ?? false) || isset($this->articles[$messageId]);
            }

            public function doQuit(bool $force = false): mixed
            {
                return true;
            }
        };

        return new NntpProviderPool(
            [new NntpProvider(1, 'test', 'news.example.org', 119, false, '', '', 1, 120, true)],
            null,
            static fn (NntpProvider $provider): ProviderClient => $client,
        );
    }

    private function providerHasEveryArticle(): void
    {
        $this->providerArticles = ['*' => true];
    }

    /**
     * @param  array<int, int>  $segments  Segment number => number (present segments).
     * @param  array<int, string>|null  $messageIds  Explicit message-IDs, overriding $segments.
     */
    private function releaseWithNzb(
        int $id,
        float $completion,
        array $segments = [],
        ?array $messageIds = null,
        int $declaredTotal = 5,
        int $files = 1,
        ?ReleaseRepairOutcome $outcome = null,
        ?string $attemptedAt = null,
        ?float $targetCompletion = null,
    ): Release {
        $guid = sprintf('%032x', $id);

        DB::table('releases')->insert([
            'id' => $id,
            'guid' => $guid,
            'nzbstatus' => 1,
            'completion' => $completion,
            'repair_outcome' => $outcome?->value,
            'repair_attempted_at' => $attemptedAt,
            'repair_target_completion' => $targetCompletion,
            'repair_evaluated_target_completion' => $targetCompletion,
            'postdate' => '2024-01-01 00:00:00',
            'haspreview' => -1,
            'passwordstatus' => -1,
        ]);

        $messageIds ??= array_map(
            static fn (int $number): string => 'part'.$number.'of'.$declaredTotal.'.Tok@host',
            $segments,
        );

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'."\n";

        for ($file = 1; $file <= $files; $file++) {
            $xml .= '  <file poster="p@example.org" date="1700000000" subject="Example.part0'.$file.'.rar yEnc (1/'.$declaredTotal.')">'."\n"
                .'    <groups><group>alt.binaries.test</group></groups>'."\n    <segments>\n";

            foreach ($messageIds as $number => $messageId) {
                $xml .= '      <segment bytes="900" number="'.$number.'">'.htmlspecialchars($messageId, ENT_XML1).'</segment>'."\n";
            }

            $xml .= "    </segments>\n  </file>\n";
        }

        $xml .= '</nzb>'."\n";

        $nzb = app(NzbService::class);
        $path = $nzb->getNzbPath($guid, 0, true);
        file_put_contents($path, gzencode($xml));

        return Release::query()->findOrFail($id);
    }

    private function storedNzb(Release $release): string
    {
        return (string) app(NzbService::class)->readNzbContents((string) $release->guid);
    }

    private function storedOutcome(int $id): ?string
    {
        $value = DB::table('releases')->where('id', $id)->value('repair_outcome');

        return $value === null ? null : (string) $value;
    }

    /**
     * @return list<string>
     */
    private function nameSourceColumns(): array
    {
        return [
            'proc_nfo',
            'proc_files',
            'proc_srr',
            'proc_crc32',
            'proc_uid',
            'proc_hash16k',
            'proc_par2',
            'proc_srrdb',
            'proc_xxx',
            'proc_media_movie',
        ];
    }

    private function createSchema(): void
    {
        DB::statement('DROP TABLE IF EXISTS releases');
        DB::statement('DROP TABLE IF EXISTS video_data');
        DB::statement('DROP TABLE IF EXISTS audio_data');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(64),
            nzbstatus INTEGER NOT NULL DEFAULT 0,
            completion DOUBLE NOT NULL DEFAULT 0,
            repair_attempted_at DATETIME NULL,
            repair_outcome VARCHAR(16) NULL,
            repair_target_completion DOUBLE NULL,
            repair_evaluated_target_completion DOUBLE NULL,
            recovery_claimed_at DATETIME NULL,
            postdate DATETIME NULL,
            totalpart INTEGER NOT NULL DEFAULT 0,
            haspreview INTEGER NOT NULL DEFAULT -1,
            passwordstatus INTEGER NOT NULL DEFAULT -1,
            pp_timeout_count INTEGER NOT NULL DEFAULT 2,
            predb_id INTEGER NOT NULL DEFAULT 0,
            isrenamed INTEGER NOT NULL DEFAULT 0,
            nfostatus INTEGER NOT NULL DEFAULT -1,
            proc_nfo INTEGER NOT NULL DEFAULT 0,
            proc_files INTEGER NOT NULL DEFAULT 0,
            proc_srr INTEGER NOT NULL DEFAULT 0,
            proc_crc32 INTEGER NOT NULL DEFAULT 0,
            proc_uid INTEGER NOT NULL DEFAULT 0,
            proc_hash16k INTEGER NOT NULL DEFAULT 0,
            proc_par2 INTEGER NOT NULL DEFAULT 0,
            proc_srrdb INTEGER NOT NULL DEFAULT 0,
            proc_xxx INTEGER NOT NULL DEFAULT 0,
            proc_media_movie INTEGER NOT NULL DEFAULT 0
        )');
        DB::statement('CREATE TABLE video_data (releases_id INTEGER PRIMARY KEY, videocodec VARCHAR(255) NULL)');
        DB::statement('CREATE TABLE audio_data (id INTEGER PRIMARY KEY, releases_id INTEGER, audioformat VARCHAR(255) NULL)');
    }
}
