<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Boost\Mcp\Tools;
use Laravel\Mcp\Server\Tool;
use RuntimeException;
use Symfony\Component\Process\Process;

final class CanaryBoostPolicy
{
    /** @var list<class-string<Tool>> */
    public const TOOLS = [
        Tools\ApplicationInfo::class,
        Tools\DatabaseConnections::class,
        Tools\DatabaseSchema::class,
        Tools\DatabaseQuery::class,
        Tools\LastError::class,
        Tools\ReadLogEntries::class,
        Tools\BrowserLogs::class,
        Tools\GetAbsoluteUrl::class,
        Tools\SearchDocs::class,
    ];

    /** @param list<string> $arguments */
    public static function validateInvocation(string $root, array $arguments): void
    {
        $mode = new Process(['git', 'config', '--local', '--get-all', 'nntmux.boostMode'], $root);
        $mode->run();
        if (! $mode->isSuccessful() || $mode->getOutput() !== "canary\n") {
            throw new RuntimeException('Boost diagnostic bootstrap requires exactly one canary mode: git config --local nntmux.boostMode canary');
        }

        if ($arguments === ['mcp:start', 'laravel-boost']) {
            return;
        }

        if (count($arguments) === 3 && $arguments[0] === 'boost:execute-tool'
            && in_array($arguments[1], self::TOOLS, true)) {
            $decoded = base64_decode($arguments[2], true);
            if ($decoded !== false && is_array(json_decode($decoded, true))) {
                return;
            }
        }

        throw new RuntimeException('Boost canary permits only its local MCP server and allowlisted diagnostic child tools.');
    }
}
