<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use App\Models\Settings;
use App\Models\UsenetGroup;
use DariusIII\NetNntp\Error as NntpError;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Hourly measurement of how much of one provider's header listing another provider also lists.
 *
 * A provider can keep accepting connections while its XOVER listing leaves out a share of every
 * post. Once headers are read from every provider that gap no longer shows as incomplete
 * releases, so this samples a few busy groups in both directions between provider 1 and each
 * secondary provider, and the `nntp-headers` probe judges the result.
 */
final class ProviderListingCanary
{
    /** Busiest groups measured per run. */
    private const int GROUPS = 5;

    /**
     * Groups listing more than this many articles an hour are left out: the reference window
     * reads four hours of the group, and 200,000/h keeps one direction to about half a minute.
     */
    private const int MAX_ARTICLES_PER_HOUR = 200_000;

    /** Articles read from the sampled provider. */
    private const int SAMPLE_ARTICLES = 30_000;

    /** The sample starts this long before the run, so the reference window has closed. */
    private const int SAMPLE_HOURS_AGO = 3;

    /** The reference window reaches this far either side of the sample's start. */
    private const int REFERENCE_HOURS = 2;

    /** Posts of 10 or more segments; small posts are too few per window to measure. */
    private const string MULTI_SEGMENT = '/\(\d+\/\d{2,}\)/';

    private const int RETENTION_DAYS = 30;

    /** maxmssgs fallback, as the binaries pass resolves it. */
    private const int DEFAULT_BATCH = 20_000;

    public function __construct(private readonly ArticleTimeLocator $locator) {}

    public function run(): void
    {
        $enabled = array_values(array_filter(NntpProviderPool::configuredProviders(), static fn (NntpProvider $p): bool => $p->enabled));
        if (\count($enabled) < 2) {
            return;
        }

        $primary = array_values(array_filter($enabled, static fn (NntpProvider $p): bool => $p->isPrimary()))[0] ?? null;
        $secondaries = array_values(array_filter($enabled, static fn (NntpProvider $p): bool => ! $p->isPrimary()));
        if ($primary === null) {
            return;
        }

        $measuredAt = now();
        $sampleTime = $measuredAt->copy()->subHours(self::SAMPLE_HOURS_AGO);
        $groups = $this->groups();
        $connections = [];
        $rows = [];

        try {
            foreach ($enabled as $provider) {
                $nntp = app(NNTPService::class);
                $nntp->useProvider($provider, poolFailover: false);
                $connections[$provider->name] = $nntp;
                if ($nntp->doConnect() !== true) {
                    Log::warning('Listing canary could not connect to an NNTP provider.', ['provider' => $provider->name]);
                    $connections[$provider->name] = null;
                }
            }

            foreach ($groups as $group) {
                foreach ($secondaries as $secondary) {
                    foreach ([[$primary, $secondary], [$secondary, $primary]] as [$sample, $reference]) {
                        $row = $this->measure($connections[$sample->name] ?? null, $connections[$reference->name] ?? null,
                            $sample, $reference, $group, $sampleTime);
                        if ($row !== null) {
                            $rows[] = [...$row, 'measured_at' => $measuredAt->format('Y-m-d H:i:s')];
                        }
                    }
                }
            }
        } finally {
            foreach ($connections as $nntp) {
                $nntp?->doQuit();
            }
        }

        // One transaction, so a reader never sees part of a run.
        DB::transaction(static function () use ($rows): void {
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('nntp_listing_coverage')->insert($chunk);
            }
        });

        DB::table('nntp_listing_coverage')
            ->where('measured_at', '<', now()->subDays(self::RETENTION_DAYS)->format('Y-m-d H:i:s'))
            ->delete();
    }

    /**
     * Up to five active groups with the highest article rate at or below the cap.
     *
     * @return list<UsenetGroup>
     */
    private function groups(): array
    {
        $rated = [];
        foreach (UsenetGroup::query()->where('active', 1)->get(['id', 'name', 'first_record', 'last_record', 'first_record_postdate', 'last_record_postdate']) as $group) {
            $rate = $this->articlesPerHour($group);
            if ($rate !== null && $rate <= self::MAX_ARTICLES_PER_HOUR) {
                $rated[] = [$rate, $group];
            }
        }
        usort($rated, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_map(static fn (array $entry): UsenetGroup => $entry[1], \array_slice($rated, 0, self::GROUPS));
    }

    /** The group's article rate, as the header re-scan measures it; null without both postdates. */
    private function articlesPerHour(UsenetGroup $group): ?float
    {
        $firstAt = $group->first_record_postdate === null ? false : strtotime((string) $group->first_record_postdate);
        $lastAt = $group->last_record_postdate === null ? false : strtotime((string) $group->last_record_postdate);
        if ($firstAt === false || $lastAt === false || $lastAt <= $firstAt) {
            return null;
        }

        return ((int) $group->last_record - (int) $group->first_record) / ($lastAt - $firstAt) * 3600;
    }

    /**
     * One group and direction: how many of P's multi-segment lines from the sample time Q lists
     * within two hours either side.
     *
     * @return array{groups_id: int, sample_provider: string, reference_provider: string, sample_time: string, sampled: int, found: int}|null
     */
    private function measure(?NNTPService $p, ?NNTPService $q, NntpProvider $sample, NntpProvider $reference, UsenetGroup $group, Carbon $sampleTime): ?array
    {
        if ($p === null || $q === null) {
            return null;
        }

        try {
            $groupP = $this->select($p, $sample, $group->name);
            $groupQ = $this->select($q, $reference, $group->name);

            $start = $this->locator->locate($p, $groupP, $sampleTime->getTimestamp());
            $sampleIds = [];
            foreach ($this->xover($p, $sample, $group->name, $start, $start + self::SAMPLE_ARTICLES - 1) as $line) {
                if (preg_match(self::MULTI_SEGMENT, (string) ($line['Subject'] ?? '')) === 1) {
                    $sampleIds[] = trim((string) ($line['Message-ID'] ?? ''));
                }
            }

            $from = $this->locator->locate($q, $groupQ, $sampleTime->copy()->subHours(self::REFERENCE_HOURS)->getTimestamp());
            $to = $this->locator->locate($q, $groupQ, $sampleTime->copy()->addHours(self::REFERENCE_HOURS)->getTimestamp());
            $listed = [];
            $batch = $this->batchSize();
            for ($first = $from; $first <= $to; $first += $batch) {
                // Only the id set is kept; one batch of parsed lines at a time.
                foreach ($this->xover($q, $reference, $group->name, $first, min($to, $first + $batch - 1)) as $line) {
                    $listed[trim((string) ($line['Message-ID'] ?? ''))] = true;
                }
            }
        } catch (RuntimeException $e) {
            Log::warning('Listing canary skipped a group and direction.', [
                'sample_provider' => $sample->name, 'reference_provider' => $reference->name,
                'group' => $group->name, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        $found = 0;
        foreach ($sampleIds as $messageId) {
            if (isset($listed[$messageId])) {
                $found++;
            }
        }

        return [
            'groups_id' => (int) $group->id,
            'sample_provider' => $sample->name,
            'reference_provider' => $reference->name,
            'sample_time' => $sampleTime->format('Y-m-d H:i:s'),
            'sampled' => \count($sampleIds),
            'found' => $found,
        ];
    }

    /** @return array{first: int|string, last: int|string} */
    private function select(NNTPService $nntp, NntpProvider $provider, string $group): array
    {
        $selected = $nntp->selectGroup($group);
        if (NNTPService::isError($selected) || ! \is_array($selected)) {
            throw new RuntimeException("NNTP provider {$provider->name} refused group {$group}.");
        }

        /** @var array{first: int|string, last: int|string} $selected */
        return $selected;
    }

    /** @return list<array<string, mixed>> */
    private function xover(NNTPService $nntp, NntpProvider $provider, string $group, int $first, int $last): array
    {
        $lines = $nntp->getXOVER($first.'-'.$last);
        if ($lines instanceof NntpError) {
            throw new RuntimeException("XOVER failed on NNTP provider {$provider->name} in {$group}: {$lines->getMessage()}");
        }

        return \is_array($lines) ? array_values(array_filter($lines, 'is_array')) : [];
    }

    private function batchSize(): int
    {
        $batch = (int) Settings::settingValue('maxmssgs');

        return $batch < 1 ? self::DEFAULT_BATCH : $batch;
    }
}
