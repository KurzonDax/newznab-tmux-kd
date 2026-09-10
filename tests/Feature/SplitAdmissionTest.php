<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Release;
use App\Services\CollectionCleanupService;
use App\Services\CollectionReconciliation\CollectionAdmission;
use App\Services\CollectionReconciliation\CollectionClaims;
use App\Services\CollectionReconciliation\CollectionOwnership;
use App\Services\CollectionReconciliation\PendingInventory;
use App\Services\CollectionReconciliation\PendingReconciler;
use App\Services\CollectionReconciliation\PostingDecision;
use App\Services\CollectionReconciliation\PostingEvidence;
use App\Services\CollectionReconciliation\PostingNzb;
use App\Services\NNTP\Contracts\BoundedProviderClient;
use App\Services\NNTP\DTO\BoundedArticleResponse;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseProcessingService;
use App\Services\YencService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Reconciliation\CreatesPostingSchema;
use Tests\Support\Reconciliation\Par2Fixture;
use Tests\TestCase;

class SplitAdmissionTest extends TestCase
{
    use CreatesPostingSchema;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        $this->registerSqliteFunction('regexp', static fn ($pattern, $value): int => preg_match('/'.str_replace('/', '\\/', $pattern).'/', (string) $value) === 1 ? 1 : 0, 2);
        $this->createPostingSchema();
        Cache::flush();
        (require database_path('migrations/2026_09_10_134847_add_reconciliation_admissions.php'))->up();
        $this->travelTo(Carbon::parse('2026-01-01T12:00:00Z'));
        DB::table('collection_regexes')->insert(['group_regex' => '.*', 'regex' => '/^\[\d+\/\d+\] - "(?P<name>[^.]+)\./', 'status' => 1]);
        $this->source(1, [1 => 'Example.mkv', 4 => 'Example.par2', 5 => 'Example.vol000+001.par2']);
        $this->source(2, [2 => 'example.r10', 3 => 'example.sfv']);
    }

    public function test_artifact_reservation_fences_existing_evidence_claim_until_abandoned(): void
    {
        (require database_path('migrations/2026_09_10_140952_create_reconciled_artifact_operations.php'))->up();
        $files = app(PendingInventory::class)->load([1, 2]);
        $revision = PendingInventory::digest($files);
        $claims = app(CollectionClaims::class);
        $this->assertNotNull($claims->claim([1, 2], 'evidence-worker', $revision, 1));
        DB::table('releases')->insert(['id' => 99, 'guid' => 'reserved-artifact']);
        DB::table('reconciled_artifact_operations')->insert([
            'id' => 'source-reservation', 'release_id' => 99, 'guid' => 'reserved-artifact',
            'kind' => 'duplicate', 'expected_version' => 1, 'expected_epoch' => 1,
            'expected_proof_revision' => 1, 'target_digest' => str_repeat('a', 64),
            'change_kind' => 'additive', 'delta' => '{}', 'updates' => '{}',
            'source_revisions' => '{}', 'state' => 'prepared',
        ]);
        DB::table('reconciled_artifact_sources')->insert([
            'operation_id' => 'source-reservation', 'collection_id' => 1,
            'revision' => str_repeat('b', 64), 'cleanup_pending' => true,
        ]);
        $decision = new PostingDecision($files, 5, null, 'Example', [], [], 'verified');
        $this->assertFalse(app(CollectionAdmission::class)->admitProven($decision, 1));
        $publish = new \ReflectionMethod(PendingReconciler::class, 'publishAssociation');
        $population = ['group' => 1, 'poster' => 'Synthetic Poster', 'total' => 5,
            'from' => '2026-01-01 00:00:00', 'until' => '2026-01-02 00:00:00'];
        $this->assertSame('changed_inventory', $publish->invoke(app(PendingReconciler::class), [1, 2], 'evidence-worker', $revision, $decision, $population, [1, 2]));
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertNull($claims->claim([1, 2], 'next-worker', $revision, 1));
        DB::table('reconciled_artifact_operations')->update(['state' => 'abandoned']);
        $this->assertTrue(app(CollectionAdmission::class)->admitProven($decision, 1));
    }

    #[DataProvider('completeUnionCases')]
    public function test_exact_case_sensitive_five_file_fixture_requires_every_advertised_ordinal(bool $complete, bool $existingEmptyNzb = false): void
    {
        (require database_path('migrations/2026_09_10_140952_create_reconciled_artifact_operations.php'))->up();
        DB::table('collections')->update(['date' => '2026-01-01 12:00:00', 'last_seen_head_postdate' => '2026-01-01 12:00:00']);
        DB::table('usenet_groups')->update(['name' => 'example.group', 'last_record_postdate' => '2026-01-01 14:00:00']);
        if (! $complete) {
            $binary = DB::table('binaries')->where('filenumber', 3)->value('id');
            DB::table('parts')->where('binaries_id', $binary)->delete();
            DB::table('binaries')->where('id', $binary)->delete();
        }
        $manifest = Par2Fixture::metadata(['Example.mkv' => 'video', 'example.r10' => 'archive', 'example.sfv' => 'checksum']);
        $responses = [];
        foreach ((new PendingInventory)->load([1, 2]) as $file) {
            $id = $file->firstArticle();
            $responses['HEAD'.$id] = "From: Synthetic Poster\r\nSubject: {$file->subject} (1/1) 100\r\nDate: Thu, 01 Jan 2026 12:00:00 +0000\r\nMessage-ID: {$id}\r\n";
            if ($file->isPar2()) {
                $responses['BODY'.$id] = (new YencService)->encode($manifest, $file->filename);
            }
        }
        $client = \Mockery::mock(BoundedProviderClient::class);
        $client->shouldReceive('fetchBoundedArticle')->andReturnUsing(static function ($id, $head) use ($responses) {
            $data = $responses[($head ? 'HEAD' : 'BODY').$id];

            return new BoundedArticleResponse($data, strlen($data));
        });
        $provider = new NntpProvider(1, 'fixture', 'example.invalid', 119, false, '', '', 1, 5, true);
        $this->app->instance(PostingEvidence::class, new PostingEvidence(new NntpProviderPool([$provider], clientFactory: static fn () => $client), new YencService));
        $reason = app(PendingReconciler::class)->reconcile(1, 1);
        $this->assertSame($complete ? 'associated' : 'verified_incomplete', $reason);
        if (! $complete) {
            $this->assertSame(0, DB::table('releases')->count());
            $this->assertTrue(CollectionOwnership::protects(1));
            $this->assertTrue(CollectionOwnership::protects(2));

            return;
        }
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('five-file-union')]);
        $release = Release::query()->first();
        $nzbs = app(NzbService::class);
        if ($existingEmptyNzb) {
            $path = $nzbs->getNzbPath($release->guid, $nzbs->getNzbSplitLevel(), true);
            $existing = gzencode('');
            file_put_contents($path, $existing);
            $this->assertFalse($nzbs->readNzbContents($release->guid));
            $this->assertFalse($nzbs->createNzbForRelease($release)->success);
            $this->assertSame($existing, file_get_contents($path));
            $this->assertSame('conflict', DB::table('reconciled_artifact_operations')->value('state'));

            return;
        }
        $result = $nzbs->createNzbForRelease($release);
        $this->assertTrue($result->success, $result->reason);
        $files = (new PostingNzb)->parse($nzbs->readNzbContents($release->guid), 'published');
        $this->assertCount(5, $files);
        $this->assertContains('Example.mkv', array_column($files, 'filename'));
        $this->assertContains('example.r10', array_column($files, 'filename'));
        $this->assertSame(100.0, (float) $release->fresh()->completion);
    }

    public static function completeUnionCases(): iterable
    {
        yield 'all five files' => [true];
        yield 'existing unusable NZB is never absence' => [true, true];
        yield 'four files at eighty percent' => [false];
    }

    public function test_mutation_rescreens_a_previously_ineligible_source_under_its_locks(): void
    {
        DB::table('binaries')->where('collections_id', 2)->where('filenumber', 2)->update(['totalparts' => 2]);
        $this->assertTrue(app(CollectionAdmission::class)->screen([1, 2], 1));
        $this->assertFalse(CollectionOwnership::protects(1));
        DB::transaction(function (): void {
            CollectionOwnership::ingest([2]);
            DB::table('binaries')->where('collections_id', 2)->where('filenumber', 2)->update(['totalparts' => 1]);
        });
        $this->assertSame(0, app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([1, 2]));
        $this->assertTrue(CollectionOwnership::protects(1));
        $this->assertTrue(CollectionOwnership::protects(2));
    }

    public function test_disproval_releases_the_relationship_and_ingestion_does_not_extend_its_deadline(): void
    {
        $admission = app(CollectionAdmission::class);
        $admission->screen([1, 2], 1);
        $deadline = DB::table('reconciliation_admissions')->where('collection_id', 1)->value('expires_at');
        $admission->disprove([2]);
        $this->assertFalse(CollectionOwnership::protects(1));
        $this->assertFalse(CollectionOwnership::protects(2));
        $admission->screen([1, 2], 1);
        $this->assertFalse(CollectionOwnership::protects(1));
        $this->travel(60)->seconds();
        DB::transaction(static fn () => CollectionOwnership::ingest([1, 2]));
        $admission->screen([1, 2], 1);
        $this->assertTrue(CollectionOwnership::protects(1));
        $this->assertSame($deadline, DB::table('reconciliation_admissions')->where('collection_id', 1)->value('expires_at'));
    }

    public function test_family_admission_protects_whole_sources_and_expires_without_renewal(): void
    {
        app(CollectionAdmission::class)->screen([1, 2], 1);
        $this->assertTrue(CollectionOwnership::protects(1));
        $this->assertTrue(CollectionOwnership::protects(2));
        $this->assertSame(0, app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([1, 2]));
        $this->travel(7199)->seconds();
        app(CollectionAdmission::class)->screen([1, 2], 1);
        $this->assertTrue(CollectionOwnership::protects(1));
        $this->travel(1)->seconds();
        app(CollectionAdmission::class)->screen([1, 2], 1);
        $this->assertFalse(CollectionOwnership::protects(1));
        app(CollectionAdmission::class)->screen([1, 2], 1);
        $this->assertFalse(CollectionOwnership::protects(2));
        $this->assertSame(2, app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([1, 2]));
    }

    public function test_sizing_and_cleanup_admit_sources_without_a_worker_pass(): void
    {
        DB::table('collections')->update(['filecheck' => 2, 'filesize' => 100]);
        $processing = app(ReleaseProcessingService::class);
        $processing->setEchoCLI(false);
        $processing->processCollectionSizes(1);
        $this->assertTrue(CollectionOwnership::protects(1));
        $this->assertSame(2, (int) DB::table('collections')->where('id', 1)->value('filecheck'));
        DB::table('reconciliation_admissions')->delete();
        $this->assertSame(0, app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([1, 2]));
        $this->assertTrue(CollectionOwnership::protects(2));
    }

    public function test_candidate_cap_preserves_the_pair_and_allows_ordinary_progress(): void
    {
        DB::table('collections')->where('id', 1)->update(['id' => 101, 'collectionhash' => sha1('101', true)]);
        DB::table('collections')->where('id', 2)->update(['id' => 102]);
        DB::table('binaries')->where('collections_id', 1)->update(['collections_id' => 101]);
        DB::table('binaries')->where('collections_id', 2)->update(['collections_id' => 102]);
        $this->source(1, [1 => 'Unrelated.txt']);
        DB::table('collections')->update(['last_seen_head_postdate' => '2026-01-01 09:00:00']);
        DB::table('usenet_groups')->where('id', 1)->update(['active' => 1, 'last_record_postdate' => '2026-01-01 12:00:00']);
        config(['collection-reconciliation.candidate_limit' => 1]);
        $this->app->instance(PostingEvidence::class, new PostingEvidence(new NntpProviderPool([]), new YencService));
        $processing = app(ReleaseProcessingService::class);
        $processing->setEchoCLI(false);
        $processing->processIncompleteCollections(1);
        $this->assertFalse(CollectionOwnership::protects(1), 'Worker leases alone must not hold unsupported ordinary sources.');
        $this->assertTrue(CollectionOwnership::protects(101), 'The pair must receive its cheap admission screen.');
        $this->assertSame(2, (int) DB::table('collections')->where('id', 1)->value('filecheck'));
        $this->assertTrue(CollectionOwnership::protects(101));
        $this->assertTrue(CollectionOwnership::protects(102));
    }

    public function test_shared_deferrals_do_not_spend_transport_retries_or_renew_admission(): void
    {
        app(CollectionAdmission::class)->screen([1, 2], 1);
        $expires = DB::table('reconciliation_admissions')->where('collection_id', 1)->value('expires_at');
        $revision = PendingInventory::digest(app(PendingInventory::class)->load([1, 2]));
        $claims = app(CollectionClaims::class);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->assertNotNull($claims->claim([1, 2], 'worker', $revision, 1));
            $claims->retry('worker', 'budget_exhausted');
        }
        $this->assertSame(0, (int) DB::table('reconciliation_claims')->where('collection_id', 1)->value('attempts'));
        $this->assertSame($expires, DB::table('reconciliation_admissions')->where('collection_id', 1)->value('expires_at'));
        $this->assertTrue(CollectionOwnership::protects(1));
    }

    public function test_transport_exhaustion_stops_reads_but_retains_admitted_hold(): void
    {
        app(CollectionAdmission::class)->screen([1, 2], 1);
        $revision = PendingInventory::digest(app(PendingInventory::class)->load([1, 2]));
        $claims = app(CollectionClaims::class);
        foreach ([60, 300, 300] as $delay) {
            $this->assertNotNull($claims->claim([1, 2], 'worker', $revision, 1));
            $claims->retry('worker', 'article_unavailable');
            $this->assertNull($claims->claim([1, 2], 'worker', $revision, 1));
            $this->travel($delay)->seconds();
        }
        $this->assertNull($claims->claim([1, 2], 'worker', $revision, 1));
        $this->assertSame(3, (int) DB::table('reconciliation_claims')->where('collection_id', 1)->value('attempts'));
        $this->assertTrue(CollectionOwnership::protects(1));
    }

    #[DataProvider('ineligibleCases')]
    public function test_ineligible_pairs_receive_no_speculative_hold(string $case): void
    {
        match ($case) {
            'gap' => DB::table('parts')->where('binaries_id', 1)->delete(),
            'poster' => DB::table('collections')->where('id', 2)->update(['fromname' => 'Different Poster']),
            'count' => DB::table('collections')->where('id', 2)->update(['declaredfiles' => 6]),
            'time' => DB::table('collections')->where('id', 2)->update(['date' => '2026-01-01 10:30:01']),
            'family' => DB::table('binaries')->where('collections_id', 2)->update(['name' => '[02/05] - "Unrelated.r10" yEnc']),
            'overlap' => DB::table('binaries')->where('collections_id', 2)->where('filenumber', 2)->update(['name' => '[01/05] - "example.r10" yEnc']),
            'not-quiet' => DB::table('collections')->where('id', 2)->update(['last_seen_head_postdate' => '2026-01-01 12:00:00']),
        };
        app(CollectionAdmission::class)->screen([1, 2], 1);
        $this->assertFalse(CollectionOwnership::protects(1));
        $this->assertFalse(CollectionOwnership::protects(2));
    }

    /** @return iterable<string, array{string}> */
    public static function ineligibleCases(): iterable
    {
        foreach (['gap', 'poster', 'count', 'time', 'family', 'overlap', 'not-quiet'] as $case) {
            yield $case => [$case];
        }
    }

    /** @param array<int, string> $files */
    private function source(int $id, array $files): void
    {
        DB::table('collections')->insert(['id' => $id, 'groups_id' => 1, 'fromname' => 'Synthetic Poster',
            'subject' => reset($files), 'date' => '2026-01-01 10:00:00', 'dateadded' => '2026-01-01 09:00:00',
            'totalfiles' => 5, 'declaredfiles' => 5, 'collectionhash' => sha1((string) $id, true)]);
        foreach ($files as $ordinal => $name) {
            $binary = DB::table('binaries')->insertGetId(['collections_id' => $id,
                'name' => sprintf('[%02d/05] - "%s" yEnc', $ordinal, $name),
                'totalparts' => 1, 'currentparts' => 1, 'partcheck' => 1, 'filenumber' => $ordinal]);
            DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => 1,
                'messageid' => 'file-'.$ordinal.'@example.invalid', 'size' => 100]);
        }
    }
}
