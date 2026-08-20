<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NNTP\NntpProviderPool;
use Illuminate\Console\Command;

/**
 * Post-deploy smoke test for the NNTP provider pool.
 *
 * This is the standing answer to "is backbone 2 actually alive": it connects to each enabled
 * provider in turn and reports SSL, auth and latency per provider. Disabled providers are
 * listed but not dialled -- being switched off is a configuration fact, not a failure.
 */
class NntpPoolStatus extends Command
{
    protected $signature = 'nntp:pool-status';

    protected $description = 'Connect to each configured NNTP provider and report auth/SSL/greeting/latency';

    public function handle(NntpProviderPool $pool): int
    {
        $providers = $pool->providers();

        if ($providers === []) {
            $this->error('No NNTP providers are configured.');
            $this->line('Declare at least NNTP_PROVIDER_1_NAME and NNTP_PROVIDER_1_HOST in .env');
            $this->line('(see .env.example for the full numbered-provider block), then re-run this command.');

            return self::FAILURE;
        }

        $rows = [];
        $failed = false;

        foreach ($providers as $provider) {
            if (! $provider->enabled) {
                $rows[] = [
                    $provider->position,
                    $provider->name,
                    $provider->host.':'.$provider->port,
                    $provider->ssl ? 'ssl' : 'plain',
                    'no',
                    $provider->connections,
                    '<comment>SKIPPED</comment>',
                    '-',
                    'disabled in configuration',
                ];

                continue;
            }

            $result = $pool->probe($provider);
            $failed = $failed || ! $result->ok;

            $rows[] = [
                $provider->position,
                $provider->name,
                $provider->host.':'.$provider->port,
                $provider->ssl ? 'ssl' : 'plain',
                'yes',
                $provider->connections,
                $result->ok ? '<info>OK</info>' : '<error>FAIL</error>',
                $result->responseTimeMs.'ms',
                $result->detail,
            ];
        }

        $this->table(
            ['#', 'Name', 'Endpoint', 'Transport', 'Enabled', 'Conns', 'Status', 'Latency', 'Detail'],
            $rows,
        );

        $this->line('');
        $this->line('Provider 1 is the primary: it serves all header traffic (group scanning, backfill,');
        $this->line('part repair). Article operations walk every enabled provider in the order above.');
        $this->line('CONNECTIONS is advisory metadata only -- nothing enforces it.');

        $pool->quit();

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
