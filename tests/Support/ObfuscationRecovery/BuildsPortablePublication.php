<?php

declare(strict_types=1);

namespace Tests\Support\ObfuscationRecovery;

use App\Enums\HeaderScanDirection;
use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\Binaries\HeaderParser;
use App\Services\BlacklistService;
use App\Services\NNTP\NntpProvider;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\ObfuscationRecovery\RecoveredReleaseList;
use App\Services\ObfuscationRecovery\RecoveryArtifacts;
use App\Services\ObfuscationRecovery\RecoveryBundleRefresh;
use App\Services\ObfuscationRecovery\RecoveryCapture;
use App\Services\ObfuscationRecovery\RecoveryCaptureBatch;
use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryControl;
use App\Services\ObfuscationRecovery\RecoveryDownload;
use App\Services\ObfuscationRecovery\RecoveryEvidence;
use App\Services\ObfuscationRecovery\RecoveryFrontierRebuild;
use App\Services\ObfuscationRecovery\RecoveryFrontiers;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use App\Services\ObfuscationRecovery\RecoveryPositiveCoverage;
use App\Services\ObfuscationRecovery\RecoveryPublicationCoverage;
use App\Services\ObfuscationRecovery\RecoveryRunRefresh;
use App\Services\ObfuscationRecovery\RecoveryScanContext;
use App\Services\ObfuscationRecovery\RecoveryScheduler;
use App\Services\ObfuscationRecovery\RecoverySettlement;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NeverBlacklistedService;
use Tests\Support\ProductionTables;

trait BuildsPortablePublication
{
    use CreatesRecoveryCbpSchema;
    use CreatesRecoveryReleaseSchema;
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;
    use SeedsInheritedFrontierHistory;

    private ?Process $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        ProductionTables::fromAuthority()->create('usenet_groups', ['id', 'name']);
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        (require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php'))->up();
        (require database_path('migrations/2026_09_13_190549_add_recovery_frontier_request_attribution.php'))->up();
        (require database_path('migrations/2026_09_14_110835_add_recovery_handoff_and_process_identity.php'))->up();
        (require database_path('migrations/2026_09_18_120000_bucket_obfuscation_recovery_dirty_marks.php'))->up();
        $this->createRecoveryCbpSchema();
        $this->createRecoveryReleaseSchema();
        Schema::drop('categories');
        $this->bootAdminListPage();
        DB::table('categories')->insert(['id' => Category::OTHER_MISC, 'title' => 'Other', 'root_categories_id' => 1]);
        Schema::table('releases', fn (Blueprint $table) => $table->integer('rarinnerfilecount')->default(0));
        Schema::create('par_hashes', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('hash');
            $table->unique(['releases_id', 'hash']);
        });
        config(['nntmux_settings.add_par2' => false]);
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => (int) strtotime((string) $value));
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('settings')->updateOrInsert(['name' => 'lookuppar2'], ['value' => 0]);
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('portable-nzb')]);
    }

    protected function tearDown(): void
    {
        $this->server?->stop(1);
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** @return array{root:string,nzb:string,port:int} */
    protected function buildPortablePublication(string $case, bool $tied, int $parts, int $files, int $targets, bool $substitute = false,
        string $frontier = 'none', string $advertised = 'original'): array
    {
        $root = $this->makeTempDirectory('portable-articles');
        (new Process(['python3', base_path('tests/Support/ObfuscationRecovery/fixture_posting.py'), $root, $case, ...($tied ? ['--tied'] : []), ...($substitute ? ['--substitute'] : [])]))->setTimeout(180)->mustRun();
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.fixture', 'obfuscation_recovery_profile' => $case === 'rar4' ? 'rar' : 'media']);
        $this->travelTo(now()->setTimestamp(1700000000));
        $headers = json_decode(file_get_contents($root.'/headers.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($advertised !== 'original') {
            $ids = [];
            foreach ($headers as $i => &$header) {
                if ($i === 0 || $advertised === 'all_zero') {
                    $header['Bytes'] = 0;
                    $ids[] = trim($header['Message-ID'], '<>');
                }
            }
            unset($header);
            $truth = json_decode(file_get_contents($root.'/truth.json'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($truth['files'] as &$file) {
                foreach ($file['messages'] as &$message) {
                    if (in_array($message['id'], $ids, true)) {
                        $message['bytes'] = 0;
                    }
                }
                unset($message);
            }
            unset($file);
            file_put_contents($root.'/truth.json', json_encode($truth, JSON_THROW_ON_ERROR));
        }
        $parts = $parts === 0 ? count($headers) : $parts;
        foreach ([[4000000000, -130], [4000000000 + $parts + 1, 131]] as [$number, $minutes]) {
            $headers[] = ['Number' => (string) $number, 'Subject' => 'Boundary marker', 'From' => 'fixture@example.invalid',
                'Date' => now()->addMinutes($minutes)->format('D, d M Y H:i:s O'), 'Message-ID' => '<boundary-'.$number.'@fixture>', 'Bytes' => 100, 'Xref' => ''];
        }
        if ($frontier !== 'none') {
            $interleaved = [];
            foreach ($headers as $header) {
                if ($header['Subject'] === 'Boundary marker') {
                    continue;
                }
                $interleaved[] = $header;
                $interleaved[] = ['Subject' => 'Unrelated ordinary posting', 'From' => 'fixture@example.invalid',
                    'Date' => now()->subSeconds(count($interleaved) % 2)->format('D, d M Y H:i:s O'),
                    'Message-ID' => '<unrelated-'.count($interleaved).'@fixture.invalid>', 'Bytes' => 100, 'Xref' => ''];
            }
            array_unshift($interleaved, $headers[count($headers) - 2]);
            $interleaved[] = $headers[count($headers) - 1];
            $headers = $interleaved;
            foreach ($headers as $ordinal => &$header) {
                $header['Number'] = (string) (4000000000 + $ordinal);
            }
            unset($header);
            if (str_starts_with($frontier, 'inherited_')) {
                foreach ($headers as $ordinal => &$header) {
                    $header['Number'] = (string) (4000018999 + $ordinal);
                }
                unset($header);
            }
            if (str_starts_with($frontier, 'retained_right') || str_starts_with($frontier, 'inherited_') || str_starts_with($frontier, 'irrelevant_')) {
                $headers[0]['Number'] = '3999990000';
                $headers[count($headers) - 1]['Number'] = '4000030000';
            }
            $articles = json_decode(file_get_contents($root.'/articles.json'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($headers as $header) {
                $id = trim($header['Message-ID'], '<>');
                $articles[$id] = [...($articles[$id] ?? ['body' => $root.'/unused']), 'header' => $header];
            }
            file_put_contents($root.'/articles.json', json_encode($articles, JSON_THROW_ON_ERROR));
            file_put_contents($root.'/headers.json', json_encode($headers, JSON_THROW_ON_ERROR));
        }
        $this->server = new Process(['python3', base_path('tests/Support/ObfuscationRecovery/fixture_nntp.py'), $root]);
        $this->server->setTimeout(240)->start();
        $deadline = hrtime(true) + 5000000000;
        while (! is_file($root.'/server-port') && hrtime(true) < $deadline && $this->server->isRunning()) {
            usleep(10000);
        }
        $this->assertFileExists($root.'/server-port', $this->server->getErrorOutput());
        $provider = NntpProvider::fromConfig(['position' => 1, 'name' => 'fixture', 'host' => '127.0.0.1', 'port' => (int) file_get_contents($root.'/server-port')]);
        $policy = new NeverBlacklistedService;
        $this->app->instance(BlacklistService::class, $policy);
        $chunks = $frontier === 'cross_chunk' ? array_chunk($headers, 2) : [$headers];
        $captured = 0;
        $ranges = str_starts_with($frontier, 'retained_right') || str_starts_with($frontier, 'inherited_') || str_starts_with($frontier, 'irrelevant_')
            ? [[3999940001, 3999960000], [3999960001, 3999980000], [3999980001, 4000000000], [4000000001, 4000020000], [4000020001, 4000040000]]
            : [[4000000000, (int) max(array_column($headers, 'Number'))]];
        foreach ($ranges as [$first, $last]) {
            $selected = array_values(array_filter($headers, static fn (array $header): bool => (int) $header['Number'] >= $first && (int) $header['Number'] <= $last));
            $chunks = $frontier === 'cross_chunk' ? array_chunk($selected, 2) : [$selected];
            $context = (new RecoveryControl)->begin(RecoveryConfig::fromSettings(), $provider, 1, 'alt.binaries.fixture', $first, $last, HeaderScanDirection::Head, count($chunks));
            foreach ($chunks as $ordinal => $chunk) {
                $parsed = (new HeaderParser($policy))->parse($chunk, 'alt.binaries.fixture');
                $capture = (new RecoveryCapture(RecoveryConfig::fromSettings(), $policy))->capture(new RecoveryCaptureBatch($chunk, $parsed['headers']), $context->chunk($ordinal));
                $captured += $capture->captured;
                $this->assertSame($ordinal === count($chunks) - 1, $capture->coverageComplete);
            }
        }
        $this->assertTrue($capture->coverageComplete);
        $this->assertSame($parts, $captured);
        $this->travel(121)->minutes();
        $artifacts = new RecoveryArtifacts($this->makeTempDirectory('portable-artifacts'));
        $this->app->instance(RecoveryArtifacts::class, $artifacts);
        $this->app->instance(RecoveryEvidence::class, new RecoveryEvidence($artifacts, new RecoveryIdentity));
        while (app(RecoveryRunRefresh::class)->step() !== null) {
        }
        while (app(RecoveryBundleRefresh::class)->step() !== null) {
        }
        $this->assertSame(1, DB::table('obfuscation_recovery_bundles')->count());
        $work = app(RecoveryWork::class);
        $repairs = 0;
        $historicalAttempts = 0;
        if (str_starts_with($frontier, 'irrelevant_')) {
            $scope = RecoveryPositiveCoverage::scope($context->sourceEpoch, 1, $context->generation);
            DB::table('obfuscation_recovery_frontier_ranges')->where('first_article', '>', 4000020000)->update(['evidence_version' => 1]);
            (new RecoveryFrontiers)->saveRange(DB::connection(), $scope, 4000030000, 4000030000, [], true, true);
            DB::table('obfuscation_recovery_frontier_conflicts')->insert([
                'identity' => hash('sha256', 'irrelevant-date'), 'scope_digest' => $scope, 'kind' => 'unknown',
                'first_article' => 4000025000, 'last_article' => 4000025001,
            ]);
            if (in_array($frontier, ['irrelevant_gap', 'irrelevant_conflict'], true)) {
                if ($frontier === 'irrelevant_gap') {
                    DB::transaction(fn () => (new RecoveryPositiveCoverage)->expire(DB::connection(), $context->sourceEpoch, 1, $context->generation, 4000025000));
                    DB::table('obfuscation_recovery_scan_windows')->where('requested_first', 4000020001)->delete();
                } else {
                    DB::table('obfuscation_recovery_frontier_conflicts')->insert([
                        'identity' => hash('sha256', 'member-conflict'), 'scope_digest' => $scope, 'kind' => 'contradiction',
                        'first_article' => 4000000001, 'last_article' => 4000000001,
                    ]);
                }
                for ($cycle = 0; $cycle < 10; $cycle++) {
                    app(RecoveryScheduler::class)->local(RecoveryStage::Discover, 2, 10);
                    app(RecoveryScheduler::class)->local(RecoveryStage::Publish, 2, 10);
                }
                $this->assertSame(0, Release::query()->count());
                $this->assertSame(0, (new RecoveredReleaseList)->query()->count());
                $this->actingAs($this->admin())->get('/admin/recovered-releases')->assertOk()->assertSee('No recovered releases');

                return ['root' => $root, 'nzb' => '', 'port' => $provider->port];
            }
        }
        if (str_starts_with($frontier, 'inherited_')) {
            $scope = RecoveryPositiveCoverage::scope($context->sourceEpoch, 1, $context->generation);
            DB::table('obfuscation_recovery_frontier_ranges')->where('first_article', 4000000001)->update(['evidence_version' => 1]);
            DB::table('obfuscation_recovery_frontier_conflicts')->insert([
                'identity' => hash('sha256', 'required-date'), 'scope_digest' => $scope, 'kind' => 'unknown',
                'first_article' => 4000019000, 'last_article' => 4000019001,
            ]);
            $history = str_replace(['inherited_', '_claim'], '', $frontier);
            $seed = $this->seedInheritedHistory(DB::table('obfuscation_recovery_bundles')->where('kind', 'posting')->first(), 4000000001, $history);
            $historicalAttempts = DB::table('obfuscation_recovery_attempts')->count();
            if (str_ends_with($frontier, '_claim')) {
                $claim = $work->claim(RecoveryStage::Download);
                $this->assertNotNull($claim);
                $this->assertSame([4000000001, 4000020000], [$claim->payload['first'], $claim->payload['last']]);
                $this->assertSame('frontier_rebuilt', app(RecoveryDownload::class)->run($claim, [$provider]));
                $repairs++;
            } else {
                $repairs += $this->repairPortableFrontiers($provider);
            }
            $this->assertSame($seed['attempts'], DB::table('obfuscation_recovery_attempts')->where('id', '<=', $historicalAttempts)->orderBy('id')->get()->toJson());
            $this->assertEquals($seed['policy'], DB::table('obfuscation_recovery_frontier_policy')->first());
            $this->assertSame(str_ends_with($history, '_pending') ? 0 : 1, DB::table('obfuscation_recovery_frontier_allowances')->count());
        }
        if (str_starts_with($frontier, 'retained_right')) {
            $scope = RecoveryPositiveCoverage::scope($context->sourceEpoch, 1, $context->generation);
            DB::table('obfuscation_recovery_frontier_ranges')->where('first_article', '>', 4000020000)->update(['evidence_version' => 1]);
            DB::table('obfuscation_recovery_frontier_conflicts')->insert([
                'identity' => hash('sha256', 'right-debt'), 'scope_digest' => $scope, 'kind' => 'unknown',
                'first_article' => 4000030000, 'last_article' => 4000030000,
            ]);
            if ($frontier === 'retained_right_missing') {
                DB::table('obfuscation_recovery_scan_windows')->where('requested_first', 4000020001)->delete();
                for ($cycle = 0; $cycle < 10; $cycle++) {
                    app(RecoveryScheduler::class)->local(RecoveryStage::Discover, 2, 10);
                    app(RecoveryScheduler::class)->local(RecoveryStage::Publish, 2, 10);
                }
                $this->assertNull($work->claim(RecoveryStage::Download));
                $this->assertSame(0, Release::query()->count());
                $this->assertSame(0, (new RecoveredReleaseList)->query()->count());
                $this->actingAs($this->admin())->get('/admin/recovered-releases')->assertOk()->assertSee('No recovered releases');

                return ['root' => $root, 'nzb' => '', 'port' => $provider->port];
            }
            $repairs += $this->repairPortableFrontiers($provider);
            $this->assertSame([[4000020001, 4000040000]], DB::table('obfuscation_recovery_frontier_requests')->get()
                ->map(static fn (object $row): array => [(int) $row->requested_first, (int) $row->requested_last])->all());
        }
        if (in_array($frontier, ['legacy', 'sealed_same_generation'], true)) {
            $this->legacyPortableFrontiers($context);
            $repairs += $this->repairPortableFrontiers($provider);
        }
        $prepared = '';
        for ($i = 0; $i < 100 && $prepared !== 'ready'; $i++) {
            $report = app(RecoveryScheduler::class)->local(RecoveryStage::Discover, 1, 10);
            $prepared = DB::table('obfuscation_recovery_bundles')->where('kind', 'posting')->value('state');
            if ($prepared !== 'ready') {
                $download = $work->claim(RecoveryStage::Download);
                if ($download !== null) {
                    $this->assertSame('downloaded', app(RecoveryDownload::class)->run($download, [$provider]));
                }
            }
        }
        $this->assertSame('ready', $prepared, json_encode($report, JSON_THROW_ON_ERROR));
        if (str_starts_with($frontier, 'irrelevant_')) {
            $this->assertTrue((new RecoveryPublicationCoverage)->ready(DB::table('obfuscation_recovery_bundles')->where('kind', 'posting')->first()));
            $this->assertSame(0, DB::table('obfuscation_recovery_frontier_requests')->count());
            $this->assertSame(1, DB::table('obfuscation_recovery_frontier_conflicts')->where('identity', hash('sha256', 'irrelevant-date'))->count());
        }
        if (in_array($frontier, ['sealed_generation', 'sealed_same_generation'], true)) {
            $original = DB::table('obfuscation_recovery_bundles')->where('kind', 'posting')->first();
            $this->legacyPortableFrontiers($context);
            if ($frontier === 'sealed_generation') {
                DB::table('obfuscation_recovery_controls')->where('scope', 'group:1')->increment('generation');
            }
            $repairs += $this->repairPortableFrontiers($provider);
            $retained = DB::table('obfuscation_recovery_bundles')->where('id', $original->id)->first();
            $this->assertSame($original->sealed_plan, $retained->sealed_plan);
            $this->assertSame($original->capture_generation, $retained->capture_generation);
            $this->assertSame($original->revision, $retained->revision);
            $this->assertTrue((new RecoveryPublicationCoverage)->ready($retained));
            $this->assertSame($frontier === 'sealed_generation' ? 2 : 1, (int) DB::table('obfuscation_recovery_frontier_requests')->value('capture_generation'));
            if ($frontier === 'sealed_same_generation') {
                $this->assertSame(2, DB::table('obfuscation_recovery_frontier_targets')->where('outcome', 'obsolete')->count());
                $this->assertSame(2, DB::table('obfuscation_recovery_frontier_targets')->whereNotNull('plan_digest')->where('outcome', 'examined')->count());
            }
        }
        $this->assertSame($targets + $repairs + $historicalAttempts, DB::table('obfuscation_recovery_attempts')->count());
        if ($frontier === 'retained_right_policy') {
            DB::table('categories')->where('id', Category::OTHER_MISC)->update(['minsizetoformrelease' => PHP_INT_MAX]);
            $report = app(RecoveryScheduler::class)->local(RecoveryStage::Publish, 1, 10);
            $this->assertSame(1, $report['policy_blocked'] ?? 0);
            $this->assertSame('category_minimum_size', DB::table('obfuscation_recovery_publications')->value('reason'));
            $this->assertSame(0, Release::query()->count());
            $this->actingAs($this->admin())->get('/admin/recovered-releases')->assertOk()->assertSee('No recovered releases');

            return ['root' => $root, 'nzb' => '', 'port' => $provider->port];
        }
        NzbCreationCandidateQuery::flushCapabilityCache();
        if ($advertised === 'all_zero') {
            DB::table('settings')->updateOrInsert(['name' => 'minsizetoformrelease'], ['value' => 1]);
            $report = app(RecoveryScheduler::class)->local(RecoveryStage::Publish, 1, 10);
            $this->assertSame(1, $report['policy_blocked'] ?? 0);
            $this->assertSame('minimum_size', DB::table('obfuscation_recovery_publications')->value('reason'));
            $this->assertSame(0, Release::query()->count());

            return ['root' => $root, 'nzb' => '', 'port' => $provider->port];
        }
        $report = app(RecoveryScheduler::class)->local(RecoveryStage::Publish, 1, 10);
        $this->assertSame(1, $report['published'] ?? 0, json_encode($report, JSON_THROW_ON_ERROR));
        $release = Release::query()->first();
        $publication = DB::table('obfuscation_recovery_publications')->first();
        $this->assertSame('published', $publication->state);
        $this->assertSame((int) $release->id, (int) $publication->releases_id);
        $this->assertSame($release->guid, $publication->guid);
        $this->assertSame('pending', $publication->initialization_state);
        $list = new RecoveredReleaseList;
        $this->assertSame(0, $list->query()->count());
        if ($frontier === 'retained_right_initialization_failure') {
            DB::table('obfuscation_recovery_evidence')->where('message_id', $publication->index_message_id)->delete();
        }
        $report = app(RecoveryScheduler::class)->local(RecoveryStage::Publish, 1, 10);
        $this->assertSame(1, $report[$frontier === 'retained_right_initialization_failure' ? 'bootstrap_cached_index_unavailable' : 'bootstrap_complete'] ?? 0, json_encode([$report, DB::table('obfuscation_recovery_publications')->value('reason')], JSON_THROW_ON_ERROR));
        $this->assertSame($frontier === 'retained_right_initialization_failure' ? 'failed' : 'complete', DB::table('obfuscation_recovery_publications')->value('initialization_state'));
        if ($frontier === 'retained_right_initialization_failure') {
            $this->assertSame('cached_index_unavailable', DB::table('obfuscation_recovery_publications')->value('reason'));
        }
        $this->assertSame((int) $release->id, (int) $list->query()->value('releases.id'));
        $this->actingAs($this->admin())->get('/admin/recovered-releases')->assertOk()->assertSee($release->searchname)->assertSee('1–1 of 1 recovered releases');
        $nzb = app(NzbService::class);
        $this->assertSame(0, DB::table('parts')->count());
        $truth = json_decode(file_get_contents($root.'/truth.json'), true, flags: JSON_THROW_ON_ERROR);
        $events = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($root.'/server-events.jsonl', FILE_IGNORE_NEW_LINES));
        $requests = array_column(array_filter($events, static fn (array $event): bool => $event['kind'] === 'request'), 'message');
        sort($requests, SORT_STRING);
        sort($truth['targets'], SORT_STRING);
        $this->assertSame($truth['targets'], $requests);
        $path = $nzb->getNzbPath($release->guid, $nzb->getNzbSplitLevel());
        $xml = new \DOMDocument;
        $this->assertTrue($xml->loadXML(gzdecode(file_get_contents($path))));
        $xpath = new \DOMXPath($xml);
        $actual = [];
        foreach ($xpath->query('//*[local-name()="file"]') as $file) {
            foreach ($xpath->query('.//*[local-name()="segment"]', $file) as $segment) {
                $id = $segment->textContent;
                $this->assertArrayNotHasKey($id, $actual);
                $actual[$id] = ['bytes' => (int) $segment->getAttribute('bytes'), 'ordinal' => (int) $segment->getAttribute('number')];
            }
        }
        foreach ($truth['files'] as $file) {
            foreach ($file['messages'] as $message) {
                $this->assertSame(['bytes' => $message['bytes'], 'ordinal' => $message['ordinal']], $actual[$message['id']]);
            }
        }
        $this->assertCount($parts, $actual);
        $this->assertSame($files, (int) $release->totalpart);
        $this->assertSame(0, (int) $release->declaredfiles);

        $before = DB::table('obfuscation_recovery_budgets')->orderBy('id')->get()->toJson();
        app(RecoveryScheduler::class)->local(RecoveryStage::Discover, 2, 10);
        $this->assertSame(1, Release::query()->count());
        $this->assertSame($before, DB::table('obfuscation_recovery_budgets')->orderBy('id')->get()->toJson());
        app(RecoveryScheduler::class)->local(RecoveryStage::Publish, 2, 10);
        $this->assertSame($release->guid, Release::query()->value('guid'));
        $this->assertSame((int) $release->id, (int) DB::table('obfuscation_recovery_publications')->value('releases_id'));
        $this->assertSame($before, DB::table('obfuscation_recovery_budgets')->orderBy('id')->get()->toJson());
        $this->assertFileExists($path);
        $repeatedEvents = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($root.'/server-events.jsonl', FILE_IGNORE_NEW_LINES));
        $this->assertSame(count($events), count($repeatedEvents));

        return ['root' => $root, 'nzb' => $path, 'port' => $provider->port];
    }

    private function legacyPortableFrontiers(RecoveryScanContext $context): void
    {
        DB::table('obfuscation_recovery_frontier_ranges')->delete();
        DB::table('obfuscation_recovery_frontiers')->where('article_number', $context->first)->delete();
        DB::table('obfuscation_recovery_scans')->update(['evidence_version' => 1, 'date_order_consistent' => false, 'date_points' => null]);
        DB::table('obfuscation_recovery_frontier_conflicts')->insert([
            'identity' => hash('sha256', 'legacy'), 'scope_digest' => RecoveryPositiveCoverage::scope($context->sourceEpoch, 1, $context->generation),
            'kind' => 'unknown', 'first_article' => $context->first, 'last_article' => $context->last,
        ]);
    }

    private function repairPortableFrontiers(NntpProvider $provider): int
    {
        $membership = DB::table('obfuscation_recovery_bundles')->where('kind', 'posting')->value('membership_changed_at');
        $repairs = 0;
        for ($cycle = 0; $cycle < 10; $cycle++) {
            $report = app(RecoveryScheduler::class)->local(RecoveryStage::Discover, 2, 10);
            $claim = app(RecoveryWork::class)->claim(RecoveryStage::Download);
            if ($claim === null) {
                continue;
            }
            $this->assertSame('frontier_rebuild', $claim->purpose);
            $this->assertSame('frontier_rebuilt', app(RecoveryDownload::class)->run($claim, [$provider]));
            $repairs++;
            $bundle = DB::table('obfuscation_recovery_bundles')->where('kind', 'posting')->first();
            $this->assertSame($membership, $bundle->membership_changed_at);
            $rebuild = new RecoveryFrontierRebuild;
            $envelope = $rebuild->envelope($bundle);
            $assessment = (new RecoverySettlement)->assess($bundle->source_epoch, 1, (int) $bundle->capture_generation,
                $envelope['first_article'], $envelope['last_article'], $envelope['first_postdate'], $envelope['last_postdate'], $envelope['changed_at'], $rebuild->sealed($bundle));
            if ($assessment === 'ready') {
                return $repairs;
            }
        }
        $this->fail('Retained frontier repair did not establish settlement.');
    }
}
