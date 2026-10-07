<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\NNTP\NntpProviderPool;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Secondary NNTP providers and their group positions for tests of what reads them. A test using
 * this must call {@see NntpProviderPool::forgetConfiguredProviders()} in its tearDown, and creates
 * `usenet_group_provider_cursors` itself through {@see ProductionTables}.
 */
trait InteractsWithSecondaryProviders
{
    /**
     * Replace the configured providers, as the pool reads them from config.
     *
     * @param  list<array<string, mixed>>  $providers
     */
    protected function configureProviders(array $providers): void
    {
        config(['nntmux_nntp.providers' => $providers]);
        NntpProviderPool::forgetConfiguredProviders();
    }

    /** Provider 1 plus one enabled secondary provider, `super`, at `super.example.invalid`. */
    protected function configureSecondaryProvider(): void
    {
        $this->configureProviders([
            ['position' => 1, 'name' => 'primary', 'host' => 'news.example.invalid'],
            ['position' => 2, 'name' => 'super', 'host' => 'super.example.invalid'],
        ]);
    }

    /** Where a secondary provider has read to in one group: the post date of its position. */
    protected function secondaryPosition(int $group, ?string $at, string $provider = 'super', string $host = 'super.example.invalid'): void
    {
        DB::table('usenet_group_provider_cursors')->upsert([[
            'usenet_groups_id' => $group, 'provider' => $provider, 'provider_host' => $host, 'last_record' => 10, 'last_record_postdate' => $at,
        ]], ['usenet_groups_id', 'provider'], ['provider_host', 'last_record_postdate']);
    }

    /**
     * Count, from now on, the reads the repairing rule makes beside the rows: the secondary
     * cursors and the `completionpercent` and `delaytime` settings.
     *
     * @return Closure(): array{cursors: int, completionpercent: int, delaytime: int}
     */
    protected function recordRepairReads(): Closure
    {
        $counts = ['cursors' => 0, 'completionpercent' => 0, 'delaytime' => 0];
        DB::listen(static function (QueryExecuted $query) use (&$counts): void {
            if (str_contains($query->sql, 'from "usenet_group_provider_cursors"')) {
                $counts['cursors']++;
            }
            if (str_contains($query->sql, '"settings"')) {
                foreach (['completionpercent', 'delaytime'] as $name) {
                    if (in_array($name, $query->bindings, true)) {
                        $counts[$name]++;
                    }
                }
            }
        });

        return static function () use (&$counts): array {
            return $counts;
        };
    }
}
