<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Release;
use App\Services\Nzb\NzbService;
use App\Services\Par2Sidecar\SidecarCombiner;
use App\Services\Par2Sidecar\SidecarWork;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\InterruptingSidecarCombiner;
use Tests\Feature\Par2SidecarWorkflowTest;

/** Runs the same crash contract against InnoDB, plus deterministic competing connections. */
class Par2SidecarMariaDbTest extends Par2SidecarWorkflowTest
{
    protected function bootIsolatedDatabase(): void
    {
        if (getenv('CBP_INTEGRATION_DB_DATABASE') !== 'cbp_integration') {
            $this->markTestSkipped('Requires the disposable cbp_integration Sail database.');
        }
        config(['database.default' => 'mariadb', 'database.connections.mariadb.host' => 'mariadb',
            'database.connections.mariadb.database' => 'cbp_integration',
            'database.connections.mariadb.username' => getenv('CBP_INTEGRATION_DB_USERNAME'),
            'database.connections.mariadb.password' => getenv('CBP_INTEGRATION_DB_PASSWORD')]);
        DB::purge('mariadb');
        $this->dropFixtureTables();
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        foreach ($this->bootstrapSettings() as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
    }

    protected function tearDown(): void
    {
        if (DB::getDefaultConnection() === 'mariadb') {
            DB::disconnect('sidecar_peer');
            $this->dropFixtureTables();
        }
        parent::tearDown();
    }

    private function dropFixtureTables(): void
    {
        foreach (['par2_sidecar_inventories', 'par2_file_descriptors', 'payload_prefix_hashes', 'par2_sidecar_operations',
            'release_files', 'par_hashes', 'predb', 'releases', 'usenet_groups', 'categories', 'root_categories', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function test_competing_writers_cannot_change_rows_between_nzb_write_and_accounting(): void
    {
        Search::spy();
        Event::fake([ReleaseNameFixed::class]);
        $this->seedPostingPair();
        config(['database.connections.sidecar_peer' => config('database.connections.mariadb')]);
        $peer = DB::connection('sidecar_peer');
        $peer->statement('SET SESSION innodb_lock_wait_timeout=1');
        $attempted = false;
        $combiner = app(InterruptingSidecarCombiner::class);
        $combiner->onPhase = function (string $phase) use ($peer, &$attempted): void {
            if ($phase !== 'nzb_written') {
                return;
            }
            $attempted = true;
            foreach ([1, 2] as $id) {
                try {
                    $peer->table('releases')->where('id', $id)->update(['additional_pp_claimed_at' => now(), 'additional_pp_claim_token' => 'competing']);
                    self::fail('A competing claimant modified a locked release.');
                } catch (QueryException $error) {
                    self::assertSame(1205, (int) ($error->errorInfo[1] ?? 0));
                }
            }
            self::assertFalse(app(NzbService::class)->replaceNzbContents(str_repeat('1', 40), '<nzb/>')->success);
        };
        $this->app->instance(SidecarCombiner::class, $combiner);
        app(SidecarWork::class)->run();
        self::assertTrue($attempted);
        self::assertSame('done', DB::table('par2_sidecar_operations')->value('phase'), (string) DB::table('par2_sidecar_operations')->value('reason'));
        self::assertSame(2200000, (int) Release::query()->findOrFail(1)->size);
    }
}
