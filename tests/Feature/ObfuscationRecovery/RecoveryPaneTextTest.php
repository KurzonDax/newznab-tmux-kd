<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryPaneText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class RecoveryPaneTextTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $tables = ProductionTables::fromAuthority();
        foreach ([
            'usenet_groups' => ['id', 'name'],
            'releases' => ['id', 'searchname'],
            'obfuscation_recovery_bundles' => ['id', 'kind', 'state', 'reason', 'groups_id', 'start_ms', 'publication_id'],
            'obfuscation_recovery_publications' => ['id', 'releases_id', 'state', 'created_at'],
            'obfuscation_recovery_work' => ['id', 'stage', 'purpose', 'status'],
            'obfuscation_recovery_slots' => ['id', 'worker_token'],
        ] as $table => $columns) {
            $tables->create($table, $columns);
        }
        config(['app.timezone' => 'America/Chicago']);
        $this->travelTo(Carbon::parse('2026-09-18 12:00:00', 'America/Chicago'));
    }

    protected function tearDown(): void
    {
        \Termwind\renderUsing(null);
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_candidates_and_backlogs_describe_stored_work(): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.x']);
        DB::table('releases')->insert(['id' => 10, 'searchname' => 'A<B>&C']);
        DB::table('obfuscation_recovery_publications')->insert(['id' => 3, 'releases_id' => 10, 'state' => 'published', 'created_at' => now()]);
        DB::table('obfuscation_recovery_publications')->insert(['id' => 4, 'state' => 'published', 'created_at' => now()->subDays(2)]);
        DB::table('obfuscation_recovery_bundles')->insert(['id' => 2, 'kind' => 'posting', 'state' => 'collecting', 'reason' => 'awaiting_index', 'groups_id' => 1, 'start_ms' => 1789732800000, 'publication_id' => 3]);
        $text = new RecoveryPaneText;
        $this->assertSame('Candidate 2 in alt.x, posted 2026-09-18 07:00, revision 4: waiting for its index download (0.4 s)', $text->candidateLine(2, 4, 'awaiting_index', 0.4));
        $this->assertSame('Published release 10 "A<B>&C" (candidate 2, alt.x)', $text->candidateLine(2, 4, 'published', 0.4));
        $this->assertSame(['Backlog: 1 collecting (1 waiting for its index download), 0 ready, 0 publishing, 1 published in 24 h', 'Queue: empty'], $text->backlogLines());
        DB::table('obfuscation_recovery_work')->insert([
            ['stage' => 'download', 'purpose' => 'gap', 'status' => 'pending'],
            ['stage' => 'download', 'purpose' => 'gap', 'status' => 'claimed'],
            ['stage' => 'discover', 'purpose' => 'prepare', 'status' => 'pending'],
            ['stage' => 'download', 'purpose' => 'index', 'status' => 'completed'],
        ]);
        DB::table('obfuscation_recovery_slots')->insert(['id' => 1, 'worker_token' => 'busy']);
        $this->assertSame('Queue: 2 gap, 1 prepare', $text->backlogLines()[1]);
        $this->assertSame('Queue: 2 gap, slots 1/2 busy', $text->queueLine());
        $buffer = new BufferedOutput;
        \Termwind\renderUsing($buffer);
        $text->say('Published release 1 "A<B>&C" (candidate 2, alt.x)');
        $this->assertSame('Published release 1 "A<B>&C" (candidate 2, alt.x)', trim($buffer->fetch()));
        $this->assertSame('Recovery discover at 2026-09-18 12:00:00 CDT', $text->title('discover'));
    }
}
