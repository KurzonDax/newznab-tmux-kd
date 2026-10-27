<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ForkingService;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ForkingServiceFinalizeTest extends TestCase
{
    #[DataProvider('finalizeExitProvider')]
    public function test_a_failing_finalize_run_is_logged_with_its_exit_code(int $exitCode, bool $warned): void
    {
        config(['nntmux.multiprocessing_max_child_time' => 10]);
        Log::spy();
        $service = new class($exitCode) extends ForkingService
        {
            public function __construct(private readonly int $exitCode) {}

            public function finalize(): void
            {
                $this->processReleasesEndWork();
            }

            /** Runs a real child with the wanted exit code in place of `releases:finalize`. */
            protected function executeCommand(array|string $command): string
            {
                return parent::executeCommand([PHP_BINARY, '-r', 'exit('.$this->exitCode.');']);
            }
        };

        $service->finalize();

        if ($warned) {
            Log::shouldHaveReceived('warning')
                ->withArgs(fn (string $message, array $context): bool => $message === "releases:finalize exited with code {$exitCode}"
                    && $context === ['exit_code' => $exitCode])
                ->once();
        } else {
            Log::shouldNotHaveReceived('warning');
        }
    }

    /** @return array<string, array{int, bool}> */
    public static function finalizeExitProvider(): array
    {
        return ['failed' => [3, true], 'succeeded' => [0, false]];
    }
}
