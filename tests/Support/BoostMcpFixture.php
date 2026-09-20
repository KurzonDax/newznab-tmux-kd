<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Laravel\Boost\BoostServiceProvider;
use Laravel\Boost\Mcp\Tools\RecordRule;
use Laravel\Boost\Mcp\Tools\Tinker;
use Laravel\Mcp\Server\McpServiceProvider;
use Symfony\Component\Process\Process;

/** Disposable application exercising the real CLI and locked Boost child processes. */
final class BoostMcpFixture
{
    public static function prepare(string $root, bool $cached, string $mode = 'canary'): void
    {
        foreach (['scripts', 'bootstrap/cache', 'config', 'storage/logs', 'storage/framework/views', 'public/build'] as $directory) {
            mkdir($root.'/'.$directory, 0777, true);
        }
        foreach (['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'artisan'] as $file) {
            copy(base_path($file), $root.'/'.$file);
        }
        copy(base_path('scripts/agent-boost-canary.php'), $root.'/scripts/agent-boost-canary.php');
        symlink(base_path('vendor'), $root.'/vendor');
        file_put_contents($root.'/.env', $mode === 'canary' ? "APP_ENV=canary-fixture\nAPP_DEBUG=false\n" : "APP_ENV=local\nAPP_DEBUG=true\n");
        file_put_contents($root.'/public/build/fixture.js', '/* Synthetic prebuilt fixture asset. */');
        file_put_contents($root.'/bootstrap/providers.php', '<?php return [];');
        file_put_contents($root.'/bootstrap/app.php', <<<'BOOT'
<?php
$app = Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
    ->withProviders([Laravel\Mcp\Server\McpServiceProvider::class, Laravel\Boost\BoostServiceProvider::class])
    ->withExceptions()
    ->create();
$app->booted(function () use ($app) {
    if ($app->environment() !== 'canary-fixture' || config('app.debug') !== false
        || config('database.connections.probe.database') !== $app->basePath('probe.sqlite')) {
        throw new RuntimeException('Diagnostic configuration drifted.');
    }
    Illuminate\Console\Application::starting(function () {
    if (app()->environment('canary-fixture') && (app('router')->getRoutes()->getByName('boost.browser-logs') !== null
        || in_array(Laravel\Boost\Middleware\InjectBoost::class, app('router')->getMiddlewareGroups()['web'] ?? [], true))) {
        throw new RuntimeException('Diagnostic browser ingestion was registered.');
    }
    });
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake(['*' => Illuminate\Support\Facades\Http::response('Synthetic Laravel documentation', 200)]);
});
return $app;
BOOT);
        $config = [
            'app' => ['name' => 'Diagnostic fixture', 'env' => 'canary-fixture', 'debug' => false,
                'key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=', 'url' => 'http://fixture.test',
                'providers' => array_merge(ServiceProvider::defaultProviders()->toArray(), [
                    McpServiceProvider::class, BoostServiceProvider::class,
                ])],
            'database' => ['default' => 'probe', 'connections' => ['probe' => [
                'driver' => 'sqlite', 'database' => $root.'/probe.sqlite', 'prefix' => '',
            ]]],
            'logging' => ['default' => 'single', 'channels' => ['single' => ['driver' => 'single', 'path' => $root.'/storage/logs/laravel.log']]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
            'boost' => ['enabled' => true, 'browser_logs_watcher' => true, 'mcp' => ['tools' => [
                'include' => [Tinker::class, RecordRule::class, \stdClass::class],
                'exclude' => [],
            ]]],
        ];
        if ($mode === 'development') {
            $config['app']['env'] = 'local';
            $config['app']['debug'] = true;
            $config['boost'] = [];
            $bootstrap = file_get_contents($root.'/bootstrap/app.php');
            $bootstrap = str_replace("\$app->environment() !== 'canary-fixture'", "\$app->environment() !== 'local'", $bootstrap);
            $bootstrap = str_replace("config('app.debug') !== false", "config('app.debug') !== true", $bootstrap);
            file_put_contents($root.'/bootstrap/app.php', $bootstrap);
        }
        foreach ($config as $key => $value) {
            file_put_contents($root.'/config/'.$key.'.php', '<?php return '.var_export($value, true).';');
        }
        // Fixture-only schema, with no production counterpart or deployment data.
        touch($root.'/probe.sqlite');
        $database = DB::build(['driver' => 'sqlite', 'database' => $root.'/probe.sqlite']);
        $database->statement('CREATE TABLE diagnostic_probe (marker TEXT NOT NULL)');
        $database->insert('INSERT INTO diagnostic_probe VALUES (?)', ['fixture-'.$mode]);
        file_put_contents($root.'/storage/logs/laravel.log', "[2026-09-20 12:00:00] canary-fixture.ERROR: synthetic diagnostic entry\n");
        (new Process(['git', 'init', '-q', '-b', 'master'], $root))->mustRun();
        (new Process(['git', 'config', '--local', 'nntmux.boostMode', $mode], $root))->mustRun();
        // Prepare the normal package/provider manifests before the unchanged-file baseline.
        self::process($root, ['artisan', 'list', '--raw'])->mustRun();
        if ($cached) {
            self::process($root, ['artisan', 'config:cache'])->mustRun();
            // Effective cached settings must win even when the environment file disagrees.
            file_put_contents($root.'/.env', "APP_ENV=local\nAPP_DEBUG=true\nDB_CONNECTION=unavailable\n");
        }
    }

    /** @param list<string> $arguments */
    public static function process(string $root, array $arguments): Process
    {
        $environment = array_fill_keys(array_keys(getenv()), false);
        $environment['PATH'] = getenv('PATH');
        $environment['APP_PACKAGES_CACHE'] = $root.'/bootstrap/cache/packages.php';
        $environment['APP_SERVICES_CACHE'] = $root.'/bootstrap/cache/services.php';
        $environment['APP_CONFIG_CACHE'] = $root.'/bootstrap/cache/config.php';

        return new Process([PHP_BINARY, '-d', 'display_errors=stderr', ...$arguments], $root, $environment, timeout: 20);
    }

    /** @return array<string, string> */
    public static function snapshot(string $root): array
    {
        $snapshot = [];
        foreach ((new Filesystem)->allFiles($root, true) as $file) {
            $path = $file->getRelativePathname();
            if (! str_starts_with($path, '.git/') && ! str_starts_with($path, 'vendor/')) {
                $snapshot[$path] = hash_file('sha256', $file->getPathname());
            }
        }
        foreach (['app/Providers/CanaryBoostServiceProvider.php', 'app/Support/CanaryBoostPolicy.php',
            'app/Support/CanaryBoostServer.php', 'app/Support/CanaryBoostToolExecutor.php'] as $source) {
            $snapshot['repository/'.$source] = hash_file('sha256', base_path($source));
        }
        ksort($snapshot);

        return $snapshot;
    }
}
