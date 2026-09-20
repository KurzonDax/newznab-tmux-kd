<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laravel\Boost\Mcp\Tools\ApplicationInfo;
use Laravel\Boost\Mcp\Tools\DatabaseQuery;
use Laravel\Boost\Mcp\Tools\RecordRule;
use Laravel\Boost\Mcp\Tools\Tinker;
use Symfony\Component\Process\Process;
use Tests\Support\BoostMcpFixture;
use Tests\TestCase;

class CanaryBoostMcpTest extends TestCase
{
    protected function fixtureOnlyTables(): array
    {
        return ['diagnostic_probe'];
    }

    public function test_canary_requires_its_explicit_mode_before_application_bootstrap(): void
    {
        $root = $this->makeTempDirectory();
        mkdir($root.'/scripts');
        copy(base_path('scripts/agent-boost-canary.php'), $root.'/scripts/agent-boost-canary.php');
        symlink(base_path('vendor'), $root.'/vendor');
        (new Process(['git', 'init', '-q', '-b', 'master'], $root))->mustRun();
        $process = new Process([PHP_BINARY, $root.'/scripts/agent-boost-canary.php', 'mcp:start', 'laravel-boost'], $root);
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertStringContainsString('canary mode', $process->getErrorOutput());
        (new Process(['git', 'config', '--local', 'nntmux.boostMode', 'canary'], $root))->mustRun();
        foreach ([['list'], ['mcp:start', 'other'], ['mcp:start', 'laravel-boost', '--env=local'],
            ['boost:execute-tool', Tinker::class, base64_encode('{}')],
            ['boost:execute-tool', RecordRule::class, base64_encode('{}')],
            ['boost:execute-tool', 'UnknownTool', base64_encode('{}')],
            ['boost:execute-tool', \stdClass::class, base64_encode('{}')],
            ['boost:execute-tool', ApplicationInfo::class, 'invalid'],
        ] as $arguments) {
            $denied = new Process([PHP_BINARY, $root.'/scripts/agent-boost-canary.php', ...$arguments], $root);
            $denied->run();
            $this->assertSame(2, $denied->getExitCode());
            $this->assertSame('', $denied->getOutput());
            $this->assertStringContainsString('permits only', $denied->getErrorOutput());
        }
        unlink($root.'/vendor');
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('installed Composer dependencies', $process->getErrorOutput());
    }

    public function test_canary_serves_only_diagnostics_through_real_children_with_cached_and_uncached_configuration(): void
    {
        foreach ([false, true] as $cached) {
            $root = $this->makeTempDirectory();
            BoostMcpFixture::prepare($root, $cached);
            $http = BoostMcpFixture::process($root, ['-r', <<<'HTTP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(Illuminate\Http\Request::create('/diagnostic-http-fixture'));
echo json_encode([
    'status' => $response->getStatusCode(),
    'canary_provider' => $app->getProvider(App\Providers\CanaryBoostServiceProvider::class) !== null,
    'browser_route' => app('router')->getRoutes()->getByName('boost.browser-logs') !== null,
    'browser_middleware' => in_array(Laravel\Boost\Middleware\InjectBoost::class, app('router')->getMiddlewareGroups()['web'] ?? [], true),
]);
HTTP]);
            $http->mustRun();
            $this->assertSame(['status' => 404, 'canary_provider' => false, 'browser_route' => false, 'browser_middleware' => false], json_decode($http->getOutput(), true));
            $httpResult = $http->getOutput();
            $baseline = BoostMcpFixture::snapshot($root);
            $messages = [
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass, 'clientInfo' => ['name' => 'fixture', 'version' => '1']]],
                ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
                ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            ];
            foreach ([
                'application-info' => [],
                'database-schema' => ['filter' => 'diagnostic_probe'],
                'database-query' => ['query' => 'SELECT marker FROM diagnostic_probe LIMIT 1'],
                'read-log-entries' => ['entries' => 1],
                'browser-logs' => ['entries' => 1],
                'search-docs' => ['queries' => ['Laravel configuration']],
                'tinker' => ['code' => 'throw new Exception("must not execute");'],
            ] as $name => $arguments) {
                $messages[] = ['jsonrpc' => '2.0', 'id' => count($messages), 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $arguments]];
            }
            $process = BoostMcpFixture::process($root, ['scripts/agent-boost-canary.php', 'mcp:start', 'laravel-boost']);
            $process->setInput(implode("\n", array_map(json_encode(...), $messages))."\n");
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $responses = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), array_filter(explode("\n", $process->getOutput())));
            $this->assertCount(9, $responses, $process->getOutput());
            $names = array_column($responses[1]['result']['tools'], 'name');
            sort($names);
            $this->assertSame(['application-info', 'browser-logs', 'database-connections', 'database-query', 'database-schema', 'get-absolute-url', 'last-error', 'read-log-entries', 'search-docs'], $names);
            $this->assertFalse($responses[2]['result']['isError']);
            $this->assertStringContainsString('probe', json_encode($responses[2]));
            $this->assertFalse($responses[3]['result']['isError']);
            $this->assertStringContainsString('diagnostic_probe', json_encode($responses[3]));
            $this->assertStringContainsString('fixture-canary', json_encode($responses[4]));
            $this->assertStringContainsString('synthetic diagnostic entry', json_encode($responses[5]));
            $this->assertStringContainsString('No log file found', json_encode($responses[6]));
            $this->assertStringContainsString('Synthetic Laravel documentation', json_encode($responses[7]));
            $this->assertArrayHasKey('error', $responses[8]);
            $write = BoostMcpFixture::process($root, ['scripts/agent-boost-canary.php', 'boost:execute-tool',
                DatabaseQuery::class, base64_encode(json_encode(['query' => "UPDATE diagnostic_probe SET marker = 'changed'"]))]);
            $write->mustRun();
            $this->assertTrue(json_decode($write->getOutput(), true)['isError']);
            $this->assertStringContainsString('Only read-only queries', $write->getOutput());
            $read = BoostMcpFixture::process($root, ['scripts/agent-boost-canary.php', 'boost:execute-tool',
                DatabaseQuery::class, base64_encode(json_encode(['query' => 'SELECT marker FROM diagnostic_probe LIMIT 1']))]);
            $read->mustRun();
            $this->assertStringContainsString('fixture-canary', $read->getOutput());
            $http->mustRun();
            $this->assertSame($httpResult, $http->getOutput());
            $this->assertSame($baseline, BoostMcpFixture::snapshot($root));
        }
    }

    public function test_development_retains_ordinary_boost_and_uses_its_separate_fixture(): void
    {
        $root = $this->makeTempDirectory();
        BoostMcpFixture::prepare($root, false, 'development');
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass, 'clientInfo' => ['name' => 'fixture', 'version' => '1']]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'database-query', 'arguments' => ['query' => 'SELECT marker FROM diagnostic_probe LIMIT 1']]],
        ];
        $process = BoostMcpFixture::process($root, ['artisan', 'mcp:start', 'laravel-boost']);
        $process->setInput(implode("\n", array_map(json_encode(...), $messages))."\n");
        $process->mustRun();
        $responses = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), array_filter(explode("\n", $process->getOutput())));
        $this->assertCount(3, $responses);
        $this->assertContains('record-rule', array_column($responses[1]['result']['tools'], 'name'));
        $this->assertStringContainsString('fixture-development', json_encode($responses[2]));
        $this->assertStringNotContainsString('fixture-canary', json_encode($responses[2]));
    }
}
