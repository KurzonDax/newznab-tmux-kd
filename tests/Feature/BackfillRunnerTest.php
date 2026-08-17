<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Runners\BackfillRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BackfillRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-17 12:00:00');
        config(['nntmux.echocli' => false, 'nntmux.stream_fork_output' => false]);

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        Schema::dropIfExists('short_groups');
        Schema::dropIfExists('usenet_groups');

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('first_record');
            $table->dateTime('first_record_postdate');
            $table->unsignedInteger('backfill_target')->default(30);
            $table->boolean('backfill')->default(true);
        });

        Schema::create('short_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('first_record');
            $table->unsignedBigInteger('last_record');
        });

        foreach ([
            'backfill_days' => '1',
            'backfill_order' => '3',
            'safebackfilldate' => '2026-07-01',
            'backfill_qty' => '100',
            'backfillthreads' => '2',
        ] as $name => $value) {
            DB::table('settings')->updateOrInsert(['name' => $name], ['value' => $value]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('short_groups');
        Schema::dropIfExists('usenet_groups');

        parent::tearDown();
    }

    public function test_one_quantity_capped_child_is_built_per_eligible_group_and_failures_do_not_stop_the_pass(): void
    {
        $this->addGroup('alpha', firstRecord: 300, serverFirst: 100);
        $this->addGroup('beta', firstRecord: 150, serverFirst: 100);
        $this->addGroup('gamma', firstRecord: 180, serverFirst: 100);

        $commands = [
            PHP_BINARY.' artisan backfill:group "alpha" 2 100',
            PHP_BINARY.' artisan backfill:group "beta" 2 100',
            PHP_BINARY.' artisan backfill:group "gamma" 2 100',
        ];
        $factory = new BackfillRunnerFakeProcessFactory([
            $commands[0] => ['output' => 'alpha output'.PHP_EOL, 'exitCode' => 0],
            $commands[1] => ['output' => 'beta output'.PHP_EOL, 'exitCode' => 1],
            $commands[2] => ['output' => 'gamma output'.PHP_EOL, 'exitCode' => 0],
        ]);
        $runner = new BackfillRunnerTestDouble($factory);

        ob_start();
        $runner->backfill();
        $output = (string) ob_get_clean();

        $this->assertSame([PHP_BINARY.' app/Services/Tmux/Scripts/update_groups.php'], $runner->executedCommands);
        $this->assertSame($commands, $factory->startedCommands);
        $this->assertStringContainsString('[alpha]'.PHP_EOL.'alpha output', $output);
        $this->assertStringContainsString('[beta]'.PHP_EOL.'beta output', $output);
        $this->assertStringContainsString('[gamma]'.PHP_EOL.'gamma output', $output);
        $this->assertMatchesRegularExpression('/3 groups eligible, 2 ok, 1 failed, 180 articles, \d+s/', $output);
        $this->assertStringContainsString('Failed groups: beta', $output);
    }

    private function addGroup(string $name, int $firstRecord, int $serverFirst): void
    {
        DB::table('usenet_groups')->insert([
            'name' => $name,
            'first_record' => $firstRecord,
            'first_record_postdate' => '2026-08-10 00:00:00',
            'backfill_target' => 30,
            'backfill' => 1,
        ]);
        DB::table('short_groups')->insert([
            'name' => $name,
            'first_record' => $serverFirst,
            'last_record' => 1_000,
        ]);
    }
}

class BackfillRunnerTestDouble extends BackfillRunner
{
    /** @var list<string> */
    public array $executedCommands = [];

    public function __construct(private readonly BackfillRunnerFakeProcessFactory $factory) {}

    protected function executeCommand(string $command): string
    {
        $this->executedCommands[] = $command;

        return '';
    }

    protected function createProcess(string $command): Process
    {
        return $this->factory->create($command);
    }
}

class BackfillRunnerFakeProcessFactory
{
    /** @var array<string, array{output: string, exitCode: int}> */
    private array $definitions;

    /** @var list<string> */
    public array $startedCommands = [];

    /**
     * @param  array<string, array{output: string, exitCode: int}>  $definitions
     */
    public function __construct(array $definitions)
    {
        $this->definitions = $definitions;
    }

    public function create(string $command): Process
    {
        return new BackfillRunnerFakeProcess($command, $this->definitions[$command], $this);
    }
}

class BackfillRunnerFakeProcess extends Process
{
    private bool $started = false;

    /**
     * @param  array{output: string, exitCode: int}  $definition
     */
    public function __construct(
        private readonly string $fakeCommand,
        private readonly array $definition,
        private readonly BackfillRunnerFakeProcessFactory $factory,
    ) {
        parent::__construct([PHP_BINARY, '-r', '']);
    }

    public function start(?callable $callback = null, array $env = []): void
    {
        $this->started = true;
        $this->factory->startedCommands[] = $this->fakeCommand;
    }

    public function isRunning(): bool
    {
        if (! $this->started) {
            return false;
        }

        $this->started = false;

        return false;
    }

    public function getOutput(): string
    {
        return $this->definition['output'];
    }

    public function getErrorOutput(): string
    {
        return '';
    }

    public function getExitCode(): ?int
    {
        return $this->definition['exitCode'];
    }
}
