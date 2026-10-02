<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ShortGroup;
use App\Models\UsenetGroup;
use App\Models\UsenetGroupProviderCursor;
use App\Services\NNTP\ArticleTimeLocator;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\NNTP\NNTPService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GroupsUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'groups:update
                            {--provider= : Provider NAME (NNTP_PROVIDER_n_NAME); defaults to provider 1}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update first/last article numbers for all active groups';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $start = now();

        $providerName = $this->option('provider');
        if (\is_string($providerName) && $providerName !== '') {
            $provider = NntpProviderPool::headerProviderNamed($providerName);
            if ($provider === null) {
                $this->error('Unknown or disabled NNTP provider: '.$providerName);

                return Command::FAILURE;
            }
            if (! $provider->isPrimary()) {
                return $this->updateSecondary($provider);
            }
        }

        // Create NNTP connection
        $nntp = app(NNTPService::class);
        $connectResult = $nntp->doConnect();
        if ($connectResult !== true) {
            $errorMessage = '❌ Unable to connect to usenet server';
            if (NNTPService::isError($connectResult)) {
                $errorMessage .= ' Error: '.$connectResult->getMessage();
            }
            $this->error($errorMessage);

            return Command::FAILURE;
        }

        $this->info('📡 Getting first/last for all active groups...');

        try {
            $data = $nntp->getGroups();

            if ($nntp->isError($data)) {
                $this->error('❌ Failed to getGroups() from NNTP server');

                return Command::FAILURE;
            }

            $this->info('🔄 Updating short_groups table...');

            // Truncate and rebuild
            DB::statement('TRUNCATE TABLE short_groups');

            // Get all active groups
            $activeGroups = Arr::pluck(
                UsenetGroup::query()
                    ->where('active', '=', 1)
                    ->orWhere('backfill', '=', 1)
                    ->get(['name']),
                'name'
            );

            $updated = 0;
            $bar = $this->output->createProgressBar(count($data));
            $bar->start();

            foreach ($data as $newgroup) {
                if (\in_array($newgroup['group'], $activeGroups, true)) {
                    ShortGroup::query()->insert([
                        'name' => $newgroup['group'],
                        'first_record' => $newgroup['first'],
                        'last_record' => $newgroup['last'],
                        'updated' => now(),
                    ]);

                    $updated++;
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            $elapsed = now()->diffInSeconds($start, true);
            $this->info("✅ Updated {$updated} groups");
            $this->info("⏱️  Running time: {$elapsed} seconds");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Update failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Record a secondary provider's server positions on its cursors, and find a starting
     * position for every active group that has none on this provider's current host.
     * Provider 1's `short_groups` are not touched.
     */
    private function updateSecondary(NntpProvider $provider): int
    {
        $nntp = app(NNTPService::class);
        $nntp->useProvider($provider, poolFailover: false);
        $connectResult = $nntp->doConnect();
        if ($connectResult !== true) {
            $this->error('Unable to connect to NNTP provider '.$provider->label()
                .(NNTPService::isError($connectResult) ? ': '.$connectResult->getMessage() : ''));

            return Command::FAILURE;
        }

        $listing = $nntp->getGroups();
        if (NNTPService::isError($listing) || ! \is_array($listing)) {
            $this->error('Failed to list groups on NNTP provider '.$provider->label());

            return Command::FAILURE;
        }

        $listed = [];
        foreach ($listing as $row) {
            $listed[(string) $row['group']] = ['first' => (int) $row['first'], 'last' => (int) $row['last']];
        }

        $groups = UsenetGroup::query()->where('active', 1)->get(['id', 'name'])
            ->filter(static fn (UsenetGroup $group): bool => isset($listed[$group->name]));
        $cursors = UsenetGroupProviderCursor::query()->where('provider', $provider->name)
            ->whereIn('usenet_groups_id', $groups->pluck('id'))->get()->keyBy('usenet_groups_id');

        $refreshed = $initialised = 0;
        foreach ($groups as $group) {
            $server = $listed[$group->name];
            $cursor = $cursors->get($group->id);
            if ($cursor !== null && $cursor->provider_host === $provider->host) {
                UsenetGroupProviderCursor::query()->where('usenet_groups_id', $group->id)->where('provider', $provider->name)
                    ->update(['server_first' => $server['first'], 'server_last' => $server['last'], 'server_checked_at' => now()]);
                $refreshed++;

                continue;
            }

            if ($this->initialiseCursor($nntp, $provider, $group->id, $group->name, $server)) {
                $initialised++;
            }
        }

        $this->info("Provider {$provider->name}: refreshed {$refreshed} and initialised {$initialised} group positions.");

        return Command::SUCCESS;
    }

    /**
     * @param  array{first: int, last: int}  $server  The group's range from LIST ACTIVE.
     */
    private function initialiseCursor(NNTPService $nntp, NntpProvider $provider, int $groupId, string $groupName, array $server): bool
    {
        $selected = $nntp->selectGroup($groupName);
        if (NNTPService::isError($selected) || ! \is_array($selected)) {
            return false;
        }

        $goal = now()->subHours(UsenetGroupProviderCursor::startHours());
        try {
            $article = app(ArticleTimeLocator::class)->locate($nntp, $selected, $goal->getTimestamp());
        } catch (RuntimeException $e) {
            Log::warning('Could not find a starting article for a secondary NNTP provider.', [
                'provider' => $provider->name, 'group' => $groupName, 'error' => $e->getMessage(),
            ]);

            return false;
        }

        // Each group commits on its own, so a run stopped by the parent's timeout keeps every
        // group it finished.
        DB::transaction(function () use ($provider, $groupId, $server, $article, $goal): void {
            UsenetGroupProviderCursor::query()->upsert([[
                'usenet_groups_id' => $groupId,
                'provider' => $provider->name,
                'provider_host' => $provider->host,
                'last_record' => max(0, $article - 1),
                'last_record_postdate' => $goal->format('Y-m-d H:i:s'),
                'last_advanced_at' => now(),
                'server_first' => $server['first'],
                'server_last' => $server['last'],
                'server_checked_at' => now(),
            ]], ['usenet_groups_id', 'provider']);
            DB::table('usenet_group_provider_ingested_ranges')
                ->where('usenet_groups_id', $groupId)->where('provider', $provider->name)->delete();
        });

        return true;
    }
}
