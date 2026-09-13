<?php

declare(strict_types=1);

namespace Tests\Support\ObfuscationRecovery;

use App\Enums\HeaderScanDirection;
use App\Facades\Search;
use App\Models\Release;
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\HeaderStorageService;
use App\Services\BlacklistService;
use App\Services\NNTP\NntpProvider;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\ObfuscationRecovery\RecoveryArtifacts;
use App\Services\ObfuscationRecovery\RecoveryBundleRefresh;
use App\Services\ObfuscationRecovery\RecoveryCapture;
use App\Services\ObfuscationRecovery\RecoveryCaptureBatch;
use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryControl;
use App\Services\ObfuscationRecovery\RecoveryDownload;
use App\Services\ObfuscationRecovery\RecoveryEvidence;
use App\Services\ObfuscationRecovery\RecoveryFrontierRebuild;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use App\Services\ObfuscationRecovery\RecoveryMaterialization;
use App\Services\ObfuscationRecovery\RecoveryPositiveCoverage;
use App\Services\ObfuscationRecovery\RecoveryPreparation;
use App\Services\ObfuscationRecovery\RecoveryPublicationCoverage;
use App\Services\ObfuscationRecovery\RecoveryRunRefresh;
use App\Services\ObfuscationRecovery\RecoveryScanContext;
use App\Services\ObfuscationRecovery\RecoveryScheduler;
use App\Services\ObfuscationRecovery\RecoverySettlement;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWork;
use App\Services\ReleaseCreationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NeverBlacklistedService;

trait BuildsPortablePublication
{
    use CreatesRecoveryCbpSchema;
    use CreatesRecoveryReleaseSchema;
    use IsolatedSqliteDatabase;

    private ?Process $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        $this->createRecoveryCbpSchema();
        $this->createRecoveryReleaseSchema();
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => (int) strtotime((string) $value));
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('portable-nzb')]);
    }

    protected function tearDown(): void
    {
        $this->server?->stop(1);
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** @return array{root:string,nzb:string,port:int} */
    protected function buildPortablePublication(string $case, bool $tied, int $parts, int $files, int $targets, bool $substitute = false, string $frontier = 'none'): array
    {
        $root = $this->makeTempDirectory('portable-articles');
        (new Process(['python3', base_path('tests/Support/ObfuscationRecovery/fixture_posting.py'), $root, $case, ...($tied ? ['--tied'] : []), ...($substitute ? ['--substitute'] : [])]))->setTimeout(180)->mustRun();
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.fixture', 'obfuscation_recovery_profile' => $case === 'rar4' ? 'rar' : 'media']);
        $this->travelTo(now()->setTimestamp(1700000000));
        $headers = json_decode(file_get_contents($root.'/headers.json'), true, flags: JSON_THROW_ON_ERROR);
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
        $context = (new RecoveryControl)->begin(RecoveryConfig::fromSettings(), $provider, 1, 'alt.binaries.fixture', 4000000000, (int) max(array_column($headers, 'Number')), HeaderScanDirection::Head, count($chunks));
        $captured = 0;
        foreach ($chunks as $ordinal => $chunk) {
            $parsed = (new HeaderParser($policy))->parse($chunk, 'alt.binaries.fixture');
            $capture = (new RecoveryCapture(RecoveryConfig::fromSettings(), $policy))->capture(new RecoveryCaptureBatch($chunk, $parsed['headers']), $context->chunk($ordinal));
            $captured += $capture->captured;
            $this->assertSame($ordinal === count($chunks) - 1, $capture->coverageComplete);
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
        if ($frontier === 'legacy') {
            $this->legacyPortableFrontiers($context);
            $repairs += $this->repairPortableFrontiers($provider);
        }
        $prepared = '';
        for ($i = 0; $i < 100 && $prepared !== 'ready'; $i++) {
            $claim = $work->claim(RecoveryStage::Discover);
            $this->assertNotNull($claim, $prepared);
            $prepared = app(RecoveryPreparation::class)->run($claim);
            if ($prepared !== 'ready') {
                $download = $work->claim(RecoveryStage::Download);
                $this->assertNotNull($download, $prepared);
                $result = app(RecoveryDownload::class)->run($download, [$provider]);
                $this->assertSame('downloaded', $result);
            }
        }
        $this->assertSame('ready', $prepared);
        if ($frontier === 'sealed_generation') {
            $original = DB::table('obfuscation_recovery_bundles')->where('kind', 'posting')->first();
            $this->legacyPortableFrontiers($context);
            DB::table('obfuscation_recovery_controls')->where('scope', 'group:1')->increment('generation');
            $repairs += $this->repairPortableFrontiers($provider);
            $retained = DB::table('obfuscation_recovery_bundles')->where('id', $original->id)->first();
            $this->assertSame($original->sealed_plan, $retained->sealed_plan);
            $this->assertSame($original->capture_generation, $retained->capture_generation);
            $this->assertSame($original->revision, $retained->revision);
            $this->assertTrue((new RecoveryPublicationCoverage)->ready($retained));
            $this->assertSame(2, (int) DB::table('obfuscation_recovery_frontier_requests')->value('capture_generation'));
        }
        $this->assertSame($targets + $repairs, DB::table('obfuscation_recovery_attempts')->count());
        $claim = $work->claim(RecoveryStage::Publish);
        $this->assertSame('materialized', (new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), $work))->run($claim));
        $this->assertSame($parts, DB::table('parts')->count());
        $this->assertSame($files, DB::table('binaries')->count());
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(0, (int) DB::table('collections')->value('declaredfiles'));
        $publication = DB::table('obfuscation_recovery_publications')->first();
        $this->assertSame('created', app(ReleaseCreationService::class)->createRecovered($claim, (int) $publication->id));
        $release = Release::query()->first();
        NzbCreationCandidateQuery::flushCapabilityCache();
        $nzb = app(NzbService::class);
        $this->assertTrue($nzb->createNzbForRelease($release)->success);
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
        $this->assertFileExists($path);

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
            $this->assertNotNull($claim, json_encode([$cycle, $report, DB::table('obfuscation_recovery_work')->get(),
                DB::table('obfuscation_recovery_bundles')->get(['id', 'kind', 'state', 'reason', 'capture_generation'])], JSON_THROW_ON_ERROR));
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
