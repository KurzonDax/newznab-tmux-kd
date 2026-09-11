<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Runners\ReleasesRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\OwnedProcessFixtureRunner;
use Tests\TestCase;

class BufferedRunnerOwnershipTest extends TestCase
{
    public function test_outer_laravel_deadline_ends_wrapper_artisan_and_descendant_before_refill(): void
    {
        $database = $this->makeTempPath('owned-process-db', '.sqlite');
        touch($database);
        $pids = $this->makeTempPath('owned-process-pids');
        $next = $this->makeTempPath('owned-process-next');
        $original = [];
        foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database] as $key => $value) {
            $original[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
            'nntmux.stream_fork_output' => false, 'nntmux.echocli' => false]);
        DB::purge();
        DB::statement('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name TEXT, active INTEGER, backfill INTEGER)');
        DB::statement('CREATE TABLE collections (id INTEGER PRIMARY KEY, groups_id INTEGER)');
        DB::statement('CREATE TABLE releases (id INTEGER PRIMARY KEY, groups_id INTEGER, nzbstatus INTEGER)');
        DB::table('settings')->insert([
            ['name' => 'releasethreads', 'value' => '1'], ['name' => 'categorizeforeign', 'value' => '0'], ['name' => 'catwebdl', 'value' => '0'],
        ]);
        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'fixture.one', 'active' => 1, 'backfill' => 0],
            ['id' => 2, 'name' => 'fixture.two', 'active' => 1, 'backfill' => 0],
        ]);
        DB::table('collections')->insert([['id' => 1, 'groups_id' => 1], ['id' => 2, 'groups_id' => 2]]);
        Log::spy();
        try {
            ob_start();
            try {
                (new OwnedProcessFixtureRunner($pids, $next))->releases();
            } finally {
                $output = (string) ob_get_clean();
            }
            $this->assertFileExists($pids, $output);
            Log::shouldHaveReceived('error')->once()->withArgs(
                static fn (string $message): bool => str_contains($message, 'Release processing batch failed:')
                    && str_contains($message, 'exceeded the timeout of 10 seconds')
            );
            $this->assertCount(3, json_decode(file_get_contents($pids), true));
            $this->assertFileExists($next, $output);
            foreach (json_decode(file_get_contents($next), true) as $state) {
                $this->assertContains($state, [null], 'The next worker started while owned work was alive.');
            }
            $productionRunner = new ReleasesRunner;
            $task = (new \ReflectionMethod($productionRunner, 'taskForCommand'))->invoke($productionRunner,
                [PHP_BINARY, '-r', 'echo $argv[1];', 'literal "quoted" argument']);
            $results = (new \ReflectionMethod($productionRunner, 'runConcurrentTasks'))->invoke($productionRunner, ['probe' => $task]);
            $this->assertSame(['probe' => 'literal "quoted" argument'], $results);
        } finally {
            if (is_file($pids)) {
                foreach (json_decode(file_get_contents($pids), true) as $pid) {
                    @posix_kill($pid, 9);
                }
            }
            foreach ($original as $key => [$value, $env, $server]) {
                $value === false ? putenv($key) : putenv($key.'='.$value);
                if ($env === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
            }
        }
    }
}
