<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\HeaderScanDirection;
use App\Models\Release;
use App\Services\Binaries\HeaderParser;
use App\Services\BlacklistService;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\ObfuscationRecovery\RecoveredReleaseList;
use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryArtifacts;
use App\Services\ObfuscationRecovery\RecoveryBundleRefresh;
use App\Services\ObfuscationRecovery\RecoveryCapture;
use App\Services\ObfuscationRecovery\RecoveryCaptureBatch;
use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryControl;
use App\Services\ObfuscationRecovery\RecoveryEvidence;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use App\Services\ObfuscationRecovery\RecoveryProcess;
use App\Services\ObfuscationRecovery\RecoveryRunRefresh;
use App\Services\ObfuscationRecovery\RecoveryScanContext;
use App\Services\ObfuscationRecovery\RecoveryScheduler;
use App\Services\ObfuscationRecovery\RecoveryStage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\NeverBlacklistedService;
use Tests\Support\ObfuscationRecovery\BuildsPortablePublication;
use Tests\TestCase;

final class RecoveryPortablePublicationTest extends TestCase
{
    use BuildsPortablePublication;

    public function test_competing_media_and_rar_publish_after_a_killed_handoff_and_previous_boot_restart(): void
    {
        $root = $this->makeTempDirectory('combined-postings');
        $headers = $articles = $truthFiles = $expectedTargets = [];
        foreach (['media1', 'rar4'] as $case) {
            $directory = $root.'/'.$case;
            (new Process(['python3', base_path('tests/Support/ObfuscationRecovery/fixture_posting.py'), $directory, $case, '--tied']))->setTimeout(180)->mustRun();
            $headers = [...$headers, ...json_decode(file_get_contents($directory.'/headers.json'), true, flags: JSON_THROW_ON_ERROR)];
            $articles += json_decode(file_get_contents($directory.'/articles.json'), true, flags: JSON_THROW_ON_ERROR);
            $truth = json_decode(file_get_contents($directory.'/truth.json'), true, flags: JSON_THROW_ON_ERROR);
            $truthFiles = [...$truthFiles, ...array_values($truth['files'])];
            $expectedTargets = [...$expectedTargets, ...$truth['targets']];
        }
        $this->travelTo(now()->setTimestamp(1700000000));
        $zeroId = trim($headers[0]['Message-ID'], '<>');
        $headers[0]['Bytes'] = 0;
        foreach ($truthFiles as &$file) {
            foreach ($file['messages'] as &$message) {
                if ($message['id'] === $zeroId) {
                    $message['bytes'] = 0;
                }
            }
            unset($message);
        }
        unset($file);
        foreach ([-130, 131] as $minutes) {
            $headers[] = ['Subject' => 'Boundary marker', 'From' => 'fixture@example.invalid',
                'Date' => now()->addMinutes($minutes)->format('D, d M Y H:i:s O'),
                'Message-ID' => '<boundary-'.$minutes.'@fixture>', 'Bytes' => 100, 'Xref' => ''];
        }
        for ($i = 0; $i < 108; $i++) {
            $time = now()->addHours(3)->addSeconds(31 * $i);
            $headers[] = ['Subject' => '[a] - '.str_repeat('Q', 32).' yEnc (1/99)', 'From' => 'fixture@example.invalid',
                'Date' => $time->format('D, d M Y H:i:s O'), 'Message-ID' => '<pending-'.($time->getTimestamp() * 1000).'@nyuu>', 'Bytes' => 100, 'Xref' => ''];
        }
        usort($headers, static fn (array $a, array $b): int => strtotime($a['Date']) <=> strtotime($b['Date']));
        foreach ($headers as $i => &$header) {
            $header['Number'] = (string) (4000000000 + 2 * $i);
            $id = trim($header['Message-ID'], '<>');
            $articles[$id] = [...($articles[$id] ?? ['body' => $root.'/unused']), 'header' => $header];
        }
        unset($header);
        file_put_contents($root.'/articles.json', json_encode($articles, JSON_THROW_ON_ERROR));
        $this->server = new Process(['python3', base_path('tests/Support/ObfuscationRecovery/fixture_nntp.py'), $root]);
        $this->server->setTimeout(240)->start();
        $deadline = hrtime(true) + 5000000000;
        while (! is_file($root.'/server-port') && hrtime(true) < $deadline && $this->server->isRunning()) {
            usleep(10000);
        }
        $this->assertFileExists($root.'/server-port', $this->server->getErrorOutput());
        $providerConfig = ['position' => 1, 'name' => 'combined', 'host' => '127.0.0.1', 'port' => (int) file_get_contents($root.'/server-port')];
        config(['nntmux_nntp.providers' => [$providerConfig]]);
        NntpProviderPool::forgetConfiguredProviders();
        $provider = NntpProvider::fromConfig($providerConfig);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.fixture', 'obfuscation_recovery_profile' => 'both']);
        $policy = new NeverBlacklistedService;
        $this->app->instance(BlacklistService::class, $policy);
        $context = (new RecoveryControl)->begin(RecoveryConfig::fromSettings(), $provider, 1, 'alt.binaries.fixture',
            4000000000, 4000000000 + 2 * (count($headers) - 1), HeaderScanDirection::Head, 1);
        $parsed = (new HeaderParser($policy))->parse($headers, 'alt.binaries.fixture');
        $report = (new RecoveryCapture(RecoveryConfig::fromSettings(), $policy))->capture(new RecoveryCaptureBatch($headers, $parsed['headers']), $context);
        $this->assertSame(125, $report->captured);
        $this->assertTrue($report->coverageComplete);
        $cacheRoot = $this->makeTempDirectory('combined-cache');
        $bindCache = function () use ($cacheRoot): void {
            $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => (int) strtotime((string) $value));
            $artifacts = new RecoveryArtifacts($cacheRoot);
            $this->app->instance(RecoveryArtifacts::class, $artifacts);
            $this->app->instance(RecoveryEvidence::class, new RecoveryEvidence($artifacts, new RecoveryIdentity));
        };
        $bindCache();
        $this->travel(121)->minutes();
        while (app(RecoveryRunRefresh::class)->step() !== null) {
        }
        while (app(RecoveryBundleRefresh::class)->step() !== null) {
        }
        $this->assertSame(110, DB::table('obfuscation_recovery_bundles')->count());
        NzbCreationCandidateQuery::flushCapabilityCache();
        $crashed = $late = false;
        $originalAttempt = null;
        for ($tick = 0; $tick < 660 && (new RecoveredReleaseList)->query()->count() < 2; $tick++) {
            app(RecoveryScheduler::class)->local(RecoveryStage::Discover, 10, 10);
            if (! $crashed && DB::table('obfuscation_recovery_work')->where('stage', 'download')->where('status', 'pending')->exists()) {
                DB::disconnect();
                $pid = pcntl_fork();
                $this->assertGreaterThanOrEqual(0, $pid);
                if ($pid === 0) {
                    DB::reconnect();
                    $bindCache();
                    DB::listen(static function (QueryExecuted $query): void {
                        if (str_starts_with($query->sql, 'insert into "obfuscation_recovery_evidence"')) {
                            posix_kill(getmypid(), SIGKILL);
                        }
                    });
                    app(RecoveryScheduler::class)->download();
                    exit(2);
                }
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifsignaled($status));
                $this->assertSame(SIGKILL, pcntl_wtermsig($status));
                DB::reconnect();
                $bindCache();
                $originalAttempt = DB::table('obfuscation_recovery_attempts')->first();
                $this->assertSame('success', $originalAttempt->outcome);
                $local = RecoveryProcess::current();
                $prior = RecoveryProcess::inDomain($local->machine, hash('sha256', 'prior-boot'), $local->namespace, $local->pid, $local->started);
                DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->update([...$prior->columns('owner_'), 'expires_at' => now()->subSecond()]);
                DB::table('obfuscation_recovery_work')->where('status', 'claimed')->update([...$prior->columns('claim_owner_'), 'claim_expires_at' => now()->subSecond()]);
                $crashed = true;
            } else {
                $this->assertNotSame('worker_failure', app(RecoveryScheduler::class)->download());
            }
            $media = DB::table('obfuscation_recovery_bundles')->where('profile', RecoveryAlgorithm::Media->value)->where('state', 'ready')->first();
            if (! $late && $media !== null) {
                $this->captureCombinedAuxiliary($media, 0);
                $late = true;
            }
            $published = app(RecoveryScheduler::class)->local(RecoveryStage::Publish, 10, 10);
            $this->assertArrayNotHasKey('nzb_pending', $published, json_encode($published, JSON_THROW_ON_ERROR));
            $this->travel(5)->seconds();
        }
        $this->assertTrue($crashed && $late);
        $this->assertSame(2, (new RecoveredReleaseList)->query()->count());
        $this->assertSame(2, Release::query()->count());
        $this->assertGreaterThan(0, DB::table('obfuscation_recovery_bundles')->where('state', 'collecting')->count());
        $this->assertEquals($originalAttempt, DB::table('obfuscation_recovery_attempts')->where('id', $originalAttempt->id)->first());
        $this->assertSame(13, DB::table('obfuscation_recovery_attempts')->count());
        $requests = array_column(array_filter(array_map(static fn (string $line): array => json_decode($line, true),
            file($root.'/server-events.jsonl', FILE_IGNORE_NEW_LINES)), static fn (array $event): bool => $event['kind'] === 'request'), 'message');
        sort($requests);
        sort($expectedTargets);
        $this->assertSame($expectedTargets, $requests);
        $this->actingAs($this->admin())->get('/admin/recovered-releases')->assertOk()->assertSee('1–2 of 2 recovered releases');
        $actual = $paths = [];
        foreach (Release::query()->get() as $release) {
            $nzb = app(NzbService::class);
            $path = $nzb->getNzbPath($release->guid, $nzb->getNzbSplitLevel());
            $paths[$path] = hash_file('sha256', $path);
            $xml = new \DOMDocument;
            $this->assertTrue($xml->loadXML(gzdecode(file_get_contents($path))));
            $xpath = new \DOMXPath($xml);
            foreach ($xpath->query('//*[local-name()="file"]') as $file) {
                $groups = $xpath->query('.//*[local-name()="group"]', $file);
                $this->assertSame(1, $groups->length);
                $this->assertSame('alt.binaries.fixture', $groups->item(0)->textContent);
                foreach ($xpath->query('.//*[local-name()="segment"]', $file) as $segment) {
                    $this->assertArrayNotHasKey($segment->textContent, $actual);
                    $actual[$segment->textContent] = [(int) $segment->getAttribute('bytes'), (int) $segment->getAttribute('number')];
                }
            }
        }
        foreach ($truthFiles as $file) {
            foreach ($file['messages'] as $message) {
                $this->assertSame([$message['bytes'], $message['ordinal']], $actual[$message['id']]);
            }
        }
        $this->assertCount(17, $actual);
        $media = DB::table('obfuscation_recovery_bundles')->where('profile', RecoveryAlgorithm::Media->value)->where('state', 'published')->first();
        $this->captureCombinedAuxiliary($media, 1);
        $ledger = DB::table('obfuscation_recovery_attempts')->orderBy('id')->get()->toJson();
        for ($i = 0; $i < 5; $i++) {
            app(RecoveryScheduler::class)->local(RecoveryStage::Discover, 2, 10);
            app(RecoveryScheduler::class)->local(RecoveryStage::Publish, 2, 10);
        }
        $this->assertSame($ledger, DB::table('obfuscation_recovery_attempts')->orderBy('id')->get()->toJson());
        foreach ($paths as $path => $digest) {
            $this->assertSame($digest, hash_file('sha256', $path));
        }
        $this->assertSame(2, Release::query()->count());
        NntpProviderPool::forgetConfiguredProviders();
    }

    private function captureCombinedAuxiliary(object $bundle, int $ordinal): void
    {
        $header = ['Number' => (string) (4000000007 + 2 * $ordinal), 'Subject' => '[a] - '.str_repeat('A', 32).' yEnc (1/99)',
            'From' => 'fixture@example.invalid', 'Date' => 'Tue, 14 Nov 2023 22:13:20 +0000',
            'Message-ID' => '<aux-'.((int) $bundle->start_ms + 50 + $ordinal).'@nyuu>', 'Bytes' => 100, 'Xref' => ''];
        $policy = new NeverBlacklistedService;
        $parsed = (new HeaderParser($policy))->parse([$header], 'alt.binaries.fixture');
        for ($repeat = 0; $repeat < 2; $repeat++) {
            $context = new RecoveryScanContext(1, 'alt.binaries.fixture', $bundle->source_epoch, (int) $bundle->capture_generation,
                (int) $header['Number'], (int) $header['Number'], HeaderScanDirection::Head, (string) Str::uuid());
            $this->assertTrue((new RecoveryCapture(RecoveryConfig::fromSettings(), $policy))->capture(new RecoveryCaptureBatch([$header], $parsed['headers']), $context)->coverageComplete);
            while (app(RecoveryRunRefresh::class)->step() !== null) {
            }
            while (app(RecoveryBundleRefresh::class)->step() !== null) {
            }
            $this->assertEquals($bundle, DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->first());
        }
    }

    public function test_zero_advertised_media_bytes_survive_preparation_publication_and_replay(): void
    {
        $this->buildPortablePublication('media1', false, 5, 2, 2, advertised: 'zero');
    }

    public function test_all_zero_advertised_bytes_still_obey_the_existing_minimum_size(): void
    {
        $this->buildPortablePublication('media1', false, 5, 2, 2, advertised: 'all_zero');
    }

    #[DataProvider('cases')]
    public function test_generated_articles_pass_actual_capture_transport_materialization_and_nzb_output(string $case, bool $tied, int $parts, int $files, int $targets): void
    {
        $this->buildPortablePublication($case, $tied, $parts, $files, $targets);
    }

    public function test_sparse_acceptance_does_not_claim_to_detect_unobserved_middle_substitution(): void
    {
        $fixture = $this->buildPortablePublication('media7', false, 43, 8, 8, true);
        $oracle = new Process(['python3', base_path('tests/Support/ObfuscationRecovery/fixture_oracle.py'),
            $fixture['root'], $fixture['nzb'], (string) $fixture['port']]);
        $oracle->setTimeout(60)->mustRun();
        $result = json_decode($oracle->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(2, $result['mismatches']);
        $this->assertSame(8, $result['files']);
    }

    public static function cases(): array
    {
        return [['media1', false, 5, 2, 2], ['media1', true, 5, 2, 3], ['media7', false, 43, 8, 8],
            ['media32', false, 593, 33, 33], ['rar4', false, 12, 5, 9], ['rar4', true, 12, 5, 10]];
    }

    #[DataProvider('frontierCases')]
    public function test_interleaved_dates_and_retained_frontiers_reach_actual_nzb_publication(string $case, string $frontier): void
    {
        $this->buildPortablePublication($case, false, $case === 'rar4' ? 12 : 5, $case === 'rar4' ? 5 : 2,
            $case === 'rar4' ? 9 : 2, frontier: $frontier);
    }

    #[DataProvider('retainedControls')]
    public function test_retained_recovery_preserves_real_refusal_and_initialization_outcomes(string $control): void
    {
        $this->buildPortablePublication('media1', false, 5, 2, 2, frontier: 'retained_right_'.$control);
    }

    public static function retainedControls(): array
    {
        return [['missing'], ['policy'], ['initialization_failure']];
    }

    public static function frontierCases(): array
    {
        return [['media1', 'retained_right'], ['rar4', 'retained_right'], ['media1', 'interleaved'], ['rar4', 'interleaved'], ['media1', 'legacy'], ['rar4', 'legacy'],
            ['media1', 'sealed_generation'], ['rar4', 'sealed_generation'], ['media1', 'cross_chunk'], ['rar4', 'cross_chunk'],
            ['media1', 'sealed_same_generation'], ['rar4', 'sealed_same_generation']];
    }

    #[DataProvider('inheritedFrontierCases')]
    public function test_candidate_scoped_and_inherited_frontiers_reach_the_real_admin_release(string $case, string $frontier): void
    {
        $this->buildPortablePublication($case, false, $case === 'rar4' ? 12 : 5, $case === 'rar4' ? 5 : 2,
            $case === 'rar4' ? 9 : 2, frontier: $frontier);
    }

    public static function inheritedFrontierCases(): array
    {
        $cases = [];
        foreach (['media1', 'rar4'] as $case) {
            foreach (['irrelevant_context', 'inherited_r1', 'inherited_r2', 'inherited_r3',
                'inherited_r2_pending', 'inherited_r3_pending', 'inherited_r2_pending_claim', 'inherited_r3_pending_claim'] as $frontier) {
                $cases[] = [$case, $frontier];
            }
        }

        return $cases;
    }

    #[DataProvider('irrelevantContextControls')]
    public function test_irrelevant_legacy_context_does_not_hide_a_real_publication_blocker(string $control): void
    {
        $this->buildPortablePublication('media1', false, 5, 2, 2, frontier: $control);
    }

    public static function irrelevantContextControls(): array
    {
        return [['irrelevant_gap'], ['irrelevant_conflict']];
    }
}
