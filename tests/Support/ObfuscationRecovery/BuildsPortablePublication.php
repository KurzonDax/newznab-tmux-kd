<?php

declare(strict_types=1);

namespace Tests\Support\ObfuscationRecovery;

use App\Enums\HeaderScanDirection;
use App\Facades\Search;
use App\Models\Release;
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\HeaderStorageService;
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
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use App\Services\ObfuscationRecovery\RecoveryMaterialization;
use App\Services\ObfuscationRecovery\RecoveryPreparation;
use App\Services\ObfuscationRecovery\RecoveryRunRefresh;
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
    protected function buildPortablePublication(string $case, bool $tied, int $parts, int $files, int $targets, bool $substitute = false): array
    {
        $root = $this->makeTempDirectory('portable-articles');
        (new Process(['python3', base_path('tests/Support/ObfuscationRecovery/fixture_posting.py'), $root, $case, ...($tied ? ['--tied'] : []), ...($substitute ? ['--substitute'] : [])]))->setTimeout(180)->mustRun();
        $this->server = new Process(['python3', base_path('tests/Support/ObfuscationRecovery/fixture_nntp.py'), $root]);
        $this->server->setTimeout(240)->start();
        $deadline = hrtime(true) + 5000000000;
        while (! is_file($root.'/server-port') && hrtime(true) < $deadline && $this->server->isRunning()) {
            usleep(10000);
        }
        $this->assertFileExists($root.'/server-port', $this->server->getErrorOutput());
        $provider = NntpProvider::fromConfig(['position' => 1, 'name' => 'fixture', 'host' => '127.0.0.1', 'port' => (int) file_get_contents($root.'/server-port')]);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.fixture', 'obfuscation_recovery_profile' => $case === 'rar4' ? 'rar' : 'media']);
        $this->travelTo(now()->setTimestamp(1700000000));
        $headers = json_decode(file_get_contents($root.'/headers.json'), true, flags: JSON_THROW_ON_ERROR);
        $parts = $parts === 0 ? count($headers) : $parts;
        foreach ([[4000000000, -130], [4000000000 + $parts + 1, 131]] as [$number, $minutes]) {
            $headers[] = ['Number' => (string) $number, 'Subject' => 'Boundary marker', 'From' => 'fixture@example.invalid',
                'Date' => now()->addMinutes($minutes)->format('D, d M Y H:i:s O'), 'Message-ID' => '<boundary-'.$number.'@fixture>', 'Bytes' => 100, 'Xref' => ''];
        }
        $policy = new NeverBlacklistedService;
        $parsed = (new HeaderParser($policy))->parse($headers, 'alt.binaries.fixture');
        $context = (new RecoveryControl)->begin(RecoveryConfig::fromSettings(), $provider, 1, 'alt.binaries.fixture', 4000000000, 4000000000 + $parts + 1, HeaderScanDirection::Head, 1);
        $capture = (new RecoveryCapture(RecoveryConfig::fromSettings(), $policy))->capture(new RecoveryCaptureBatch($headers, $parsed['headers']), $context);
        $this->assertTrue($capture->coverageComplete);
        $this->assertSame($parts, $capture->captured);
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
        $this->assertSame($targets, DB::table('obfuscation_recovery_attempts')->count());
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

        return ['root' => $root, 'nzb' => $path, 'port' => $provider->port];
    }
}
