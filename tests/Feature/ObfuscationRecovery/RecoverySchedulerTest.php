<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryScheduler;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\Tmux\Tmux;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoverySchedulerTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_engine_admission_follows_the_persisted_running_flag(): void
    {
        DB::table('settings')->insert(['name' => 'running', 'value' => 1]);
        $scheduler = app(RecoveryScheduler::class);

        $this->assertTrue($scheduler->allowed(true));

        DB::table('settings')->where('name', 'running')->update(['value' => 0]);

        $this->assertFalse($scheduler->allowed(true));
    }

    /** @return array<string, array{?string, bool}> */
    public static function runningSettings(): array
    {
        return [
            'missing' => [null, false],
            'blank' => ['', false],
            'stopped' => ['0', false],
            'running' => ['1', true],
            'unsupported' => ['2', false],
        ];
    }

    #[DataProvider('runningSettings')]
    public function test_manual_admission_is_unconditional_and_monitor_agrees_with_engine_admission(?string $running, bool $allowed): void
    {
        if ($running !== null) {
            DB::table('settings')->insert(['name' => 'running', 'value' => $running]);
        }
        $scheduler = app(RecoveryScheduler::class);

        $this->assertTrue($scheduler->allowed(false));
        $this->assertSame($allowed, $scheduler->allowed(true));
        $this->assertSame((int) $allowed, app(Tmux::class)->getMonitorSettings()['is_running']);
    }

    public function test_local_stages_run_only_while_the_engine_is_running(): void
    {
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        DB::table('settings')->insert(['name' => 'running', 'value' => 1]);
        $scheduler = app(RecoveryScheduler::class);

        foreach ([RecoveryStage::Discover, RecoveryStage::Publish] as $stage) {
            $report = $scheduler->local($stage, limit: 1, seconds: 1, engine: true);
            $this->assertArrayNotHasKey('engine_stopped', $report);
            $this->assertSame(0, $report['expired_headers']);
        }

        DB::table('settings')->where('name', 'running')->update(['value' => 0]);

        foreach ([RecoveryStage::Discover, RecoveryStage::Publish] as $stage) {
            $this->assertSame(['engine_stopped' => 1], $scheduler->local($stage, engine: true));
        }
    }
}
