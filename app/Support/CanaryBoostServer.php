<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Boost\Mcp\Boost;

final class CanaryBoostServer extends Boost
{
    protected string $instructions = 'Canary diagnostics only. Use bounded read queries and targeted schema requests. Read existing logs only. Search documentation with generic technical terms; never send private logs, credentials, hostnames, paths, or user data. Diagnostic access does not authorize repairs, source edits, or data/runtime mutations. SQL filtering is not a database permission boundary.';

    protected function discoverTools(): array
    {
        return CanaryBoostPolicy::TOOLS;
    }

    protected function discoverResources(): array
    {
        return [];
    }

    protected function discoverPrompts(): array
    {
        return [];
    }
}
