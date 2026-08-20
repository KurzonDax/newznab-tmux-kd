<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\NNTP\NNTPService;
use Illuminate\Console\Command;

/**
 * Post-deploy smoke test for the NNTP provider pool.
 *
 * This is the standing answer to "is backbone 2 actually alive": it connects to each
 * configured provider in turn and reports SSL, auth, greeting and latency per provider.
 */
class NntpPoolStatus extends Command
{
    protected $signature = 'nntp:pool-status
        {--include-disabled : Also connect to providers whose ENABLED flag is false}';

    protected $description = 'Connect to each configured NNTP provider and report auth/SSL/greeting/latency';

    public function handle(NntpProviderPool $pool): int
    {
        $providers = $this->option('include-disabled') ? $pool->providers() : $pool->enabledProviders();

        if ($providers === []) {
            $this->error('No enabled NNTP providers are configured.');
            $this->line('Declare at least NNTP_PROVIDER_1_NAME and NNTP_PROVIDER_1_HOST in .env');
            $this->line('(see .env.example for the full numbered-provider block), then re-run this command.');

            return self::FAILURE;
        }

        $rows = [];
        $failed = false;

        foreach ($providers as $provider) {
            $result = $this->checkProvider($pool, $provider);

            if (! $result['ok'] && $provider->enabled) {
                $failed = true;
            }

            $rows[] = [
                $provider->position,
                $provider->name,
                $provider->host.':'.$provider->port,
                $provider->ssl ? 'ssl' : 'plain',
                $provider->enabled ? 'yes' : 'no',
                $provider->connections,
                $result['ok'] ? '<info>OK</info>' : '<error>FAIL</error>',
                $result['latencyMs'].'ms',
                $result['detail'],
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

    /**
     * @return array{ok: bool, latencyMs: int, detail: string}
     */
    private function checkProvider(NntpProviderPool $pool, NntpProvider $provider): array
    {
        $client = $pool->clientFor($provider);

        $start = hrtime(true);

        try {
            $result = $client->doConnect(compression: false);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'latencyMs' => (int) ((hrtime(true) - $start) / 1_000_000),
                'detail' => \Str::limit($e->getMessage(), 90),
            ];
        }

        $latency = (int) ((hrtime(true) - $start) / 1_000_000);

        if ($result === true) {
            return [
                'ok' => true,
                'latencyMs' => $latency,
                'detail' => $provider->username === '' ? 'connected (no auth configured)' : 'connected + authenticated',
            ];
        }

        return [
            'ok' => false,
            'latencyMs' => $latency,
            'detail' => NNTPService::isError($result)
                ? \Str::limit((string) $result->getMessage(), 90)
                : 'unknown connection result',
        ];
    }
}
