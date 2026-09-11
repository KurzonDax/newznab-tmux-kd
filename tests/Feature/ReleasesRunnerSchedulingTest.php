<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ForkingService;
use App\Services\Runners\ReleasesRunner;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReleasesRunnerSchedulingTest extends TestCase
{
    public function test_inactive_groups_form_releases_without_fetching_headers_or_repeating_active_groups(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'nntmux.stream_fork_output' => true, 'nntmux.echocli' => false]);
        DB::purge();
        DB::statement('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name TEXT, active INTEGER, backfill INTEGER)');
        DB::statement('CREATE TABLE collections (id INTEGER PRIMARY KEY, groups_id INTEGER)');
        DB::table('settings')->insert(['name' => 'releasethreads', 'value' => '2']);
        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'synthetic.active', 'active' => 1, 'backfill' => 0],
            ['id' => 2, 'name' => 'synthetic.inactive', 'active' => 0, 'backfill' => 0],
            ['id' => 3, 'name' => 'synthetic.empty', 'active' => 0, 'backfill' => 0],
        ]);
        DB::table('collections')->insert([['id' => 1, 'groups_id' => 1], ['id' => 2, 'groups_id' => 2], ['id' => 3, 'groups_id' => 999]]);
        $runner = new SchedulingReleasesRunner;
        $runner->releases();
        $this->assertSame([
            [PHP_BINARY, 'artisan', 'releases:process', '1', '--orchestrated'],
            [PHP_BINARY, 'artisan', 'releases:process', '2', '--orchestrated'],
        ], $runner->commands);
        $runner->updatePerGroup();
        $this->assertSame([
            [PHP_BINARY, 'artisan', 'group:update-all', '1', '--orchestrated'],
            [PHP_BINARY, 'artisan', 'releases:process', '2', '--orchestrated'],
        ], $runner->commands);
        DB::table('usenet_groups')->where('id', 1)->update(['active' => 0]);
        $runner->updatePerGroup();
        $this->assertSame([
            [PHP_BINARY, 'artisan', 'releases:process', '1', '--orchestrated'],
            [PHP_BINARY, 'artisan', 'releases:process', '2', '--orchestrated'],
        ], $runner->commands);
        $this->assertTrue(DB::table('collections')->where('groups_id', 999)->exists());
        DB::table('collections')->delete();
        DB::table('usenet_groups')->delete();
        $forking = new SchedulingForkingService($runner);
        $forking->releases();
        $this->assertSame([[PHP_BINARY, 'artisan', 'releases:finalize']], $forking->commands);
        $forking->updatePerGroup();
        $this->assertSame([
            [PHP_BINARY, 'artisan', 'releases:finalize'],
            [PHP_BINARY, 'artisan', 'releases:finalize'],
        ], $forking->commands);
    }
}

class SchedulingForkingService extends ForkingService
{
    public array $commands = [];

    public function __construct(ReleasesRunner $runner)
    {
        $this->releasesRunner = $runner;
    }

    protected function executeCommand(array|string $command): string
    {
        $this->commands[] = $command;

        return '';
    }
}

class SchedulingReleasesRunner extends ReleasesRunner
{
    public array $commands = [];

    protected function runStreamingCommands(array $commands, int $maxProcesses, string $desc, ?callable $onComplete = null): void
    {
        $this->commands = $commands;
    }
}
