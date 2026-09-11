<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ForkingService;
use App\Services\Releases\ReleaseFormationGroupQuery;
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
        DB::statement('CREATE TABLE releases (id INTEGER PRIMARY KEY, groups_id INTEGER, nzbstatus INTEGER)');
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

    public function test_global_selection_and_runner_dispatch_include_recovery_without_source_rows(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'nntmux.stream_fork_output' => true, 'nntmux.echocli' => false]);
        DB::purge();
        DB::statement('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name TEXT, active INTEGER, backfill INTEGER)');
        DB::statement('CREATE TABLE collections (id INTEGER PRIMARY KEY, groups_id INTEGER)');
        DB::statement('CREATE TABLE releases (id INTEGER PRIMARY KEY, groups_id INTEGER, nzbstatus INTEGER)');
        DB::statement('CREATE TABLE reconciled_artifacts (release_id INTEGER PRIMARY KEY, search_pending INTEGER)');
        DB::statement('CREATE TABLE reconciled_artifact_operations (id TEXT PRIMARY KEY, release_id INTEGER, state TEXT)');
        DB::statement('CREATE TABLE reconciled_artifact_sources (operation_id TEXT, cleanup_pending INTEGER)');
        DB::statement('CREATE TABLE reconciled_postings (release_id INTEGER PRIMARY KEY, state TEXT, review_digest TEXT, original_nzb TEXT)');
        DB::table('settings')->insert(['name' => 'releasethreads', 'value' => '2']);
        foreach (array_merge(range(1, 12), range(100, 1099)) as $id) {
            DB::table('usenet_groups')->insert(['id' => $id, 'name' => 'synthetic.group.'.$id,
                'active' => in_array($id, [1, 12], true) ? 1 : 0, 'backfill' => 0]);
        }
        DB::table('collections')->insert(['id' => 1, 'groups_id' => 1]);
        foreach (range(2, 11) as $id) {
            DB::table('releases')->insert(['id' => $id, 'groups_id' => $id, 'nzbstatus' => $id === 2 ? 0 : 1]);
        }
        foreach ([3, 4, 5, 7, 8] as $id) {
            DB::table('reconciled_artifacts')->insert(['release_id' => $id, 'search_pending' => $id === 3 ? 1 : 0]);
        }
        DB::table('reconciled_artifact_operations')->insert([
            ['id' => 'prepared', 'release_id' => 4, 'state' => 'prepared'],
            ['id' => 'cleanup', 'release_id' => 5, 'state' => 'committed'],
            ['id' => 'settled', 'release_id' => 7, 'state' => 'committed'],
            ['id' => 'abandoned', 'release_id' => 8, 'state' => 'abandoned'],
        ]);
        DB::table('reconciled_artifact_sources')->insert([
            ['operation_id' => 'cleanup', 'cleanup_pending' => 1],
            ['operation_id' => 'settled', 'cleanup_pending' => 0],
            ['operation_id' => 'abandoned', 'cleanup_pending' => 1],
        ]);
        DB::table('reconciled_postings')->insert([
            ['release_id' => 6, 'state' => 'prepared', 'review_digest' => null, 'original_nzb' => '<nzb/>'],
            ['release_id' => 9, 'state' => 'prepared', 'review_digest' => 'review', 'original_nzb' => '<nzb/>'],
            ['release_id' => 10, 'state' => 'published', 'review_digest' => null, 'original_nzb' => '<nzb/>'],
            ['release_id' => 11, 'state' => 'prepared', 'review_digest' => null, 'original_nzb' => null],
        ]);

        DB::enableQueryLog();
        $groups = ReleaseFormationGroupQuery::query()->orderBy('id')->pluck('id')->all();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(range(1, 6), $groups);
        $this->assertLessThanOrEqual(4, $queryCount, 'Empty groups must not cause per-group database probes.');

        $runner = new SchedulingReleasesRunner;
        $runner->releases();
        $this->assertSame(array_map(static fn (int $id): array => [PHP_BINARY, 'artisan', 'releases:process', (string) $id, '--orchestrated'], range(1, 6)), $runner->commands);
        $runner->updatePerGroup();
        $this->assertSame([
            [PHP_BINARY, 'artisan', 'group:update-all', '1', '--orchestrated'],
            [PHP_BINARY, 'artisan', 'group:update-all', '12', '--orchestrated'],
            ...array_map(static fn (int $id): array => [PHP_BINARY, 'artisan', 'releases:process', (string) $id, '--orchestrated'], range(2, 6)),
        ], $runner->commands);
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
