<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Boost\Mcp\ToolExecutor;
use Laravel\Mcp\Response;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class CanaryBoostToolExecutor extends ToolExecutor
{
    /** @param array<string, mixed> $arguments */
    public function execute(string $toolClass, array $arguments = []): Response
    {
        if (! in_array($toolClass, CanaryBoostPolicy::TOOLS, true)) {
            return Response::error('Tool is not permitted by the canary diagnostic policy.');
        }

        return parent::execute($toolClass, $arguments);
    }

    /** @param array<string, mixed> $arguments */
    protected function executeInSubprocess(string $toolClass, array $arguments): Response
    {
        // Keep inherited deployment overrides. Boost's default executor unsets .env keys.
        $process = new Process($this->buildCommand($toolClass, $arguments), base_path(), timeout: $this->getTimeout($arguments));
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return Response::error('Canary diagnostic tool timed out.');
        }
        if (! $process->isSuccessful()) {
            return Response::error('Canary diagnostic child failed: '.$process->getErrorOutput().$process->getOutput());
        }
        $decoded = json_decode($process->getOutput(), true);
        if (! is_array($decoded)) {
            return Response::error('Invalid JSON output from canary diagnostic child.');
        }

        return $this->reconstructResponse($decoded);
    }

    protected function buildCommand(string $toolClass, array $arguments): array
    {
        return [PHP_BINARY, base_path('scripts/agent-boost-canary.php'), 'boost:execute-tool',
            $toolClass, base64_encode(json_encode($arguments, JSON_THROW_ON_ERROR))];
    }
}
