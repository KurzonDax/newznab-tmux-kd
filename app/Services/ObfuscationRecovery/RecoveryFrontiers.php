<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final class RecoveryFrontiers
{
    public const int VERSION = 2;

    public const int SUMMARY_POINTS = 128;

    /** @param iterable<object> $scans */
    public function recordBatch(Connection $connection, iterable $scans): void
    {
        $combined = $this->combine($scans);
        if ($combined !== null) {
            $this->record($connection, $combined);
        }
    }

    /** @param iterable<object> $scans */
    public function combine(iterable $scans): ?object
    {
        $combined = null;
        $values = ['date_points' => [], 'date_conflicts' => [], 'invalid_date_articles' => []];
        foreach ($scans as $scan) {
            $combined ??= clone $scan;
            foreach ($values as $field => $points) {
                $values[$field] = [...$points, ...json_decode($scan->{$field} ?? '[]', true, flags: JSON_THROW_ON_ERROR)];
            }
        }
        if ($combined === null || $combined->date_points === null) {
            return null;
        }
        foreach ($values as $field => $points) {
            $combined->{$field} = json_encode($points, JSON_THROW_ON_ERROR);
        }

        return $combined;
    }

    public static function witnesses(Connection $connection, string $scope, bool $excludeConflicts = true): Builder
    {
        return $connection->table('obfuscation_recovery_frontiers')->where('scope_digest', $scope)->where('head_observed', true)
            ->where('postdate', '<=', now('UTC')->addDay()->format('Y-m-d H:i:s'))
            ->when($excludeConflicts, fn (Builder $query) => $query->whereNotExists(function (Builder $conflict) use ($scope): void {
                $conflict->selectRaw('1')->from('obfuscation_recovery_frontier_conflicts as conflict')->where('conflict.scope_digest', $scope)
                    ->where('conflict.kind', 'contradiction')->whereColumn('conflict.first_article', 'obfuscation_recovery_frontiers.article_number');
            }));
    }

    /** @param list<array{0:int,1:string,2?:?string}> $points
     * @return list<array{0:int,1:string,2?:?string}>
     */
    public function usable(Connection $connection, string $scope, array $points): array
    {
        $invalid = [];
        foreach (array_chunk(array_column($points, 0), 250) as $articles) {
            foreach ($connection->table('obfuscation_recovery_frontier_conflicts')->where('scope_digest', $scope)->where('kind', 'contradiction')
                ->whereIn('first_article', $articles)->pluck('first_article') as $article) {
                $invalid[(int) $article] = true;
            }
        }

        return array_values(array_filter($points, static fn (array $point): bool => ! isset($invalid[$point[0]])));
    }

    public function record(Connection $connection, object $scan): void
    {
        if ($connection->transactionLevel() < 1) {
            throw new \LogicException('frontier_transaction_required');
        }
        $scope = RecoveryPositiveCoverage::scope($scan->source_epoch, (int) $scan->groups_id, (int) $scan->capture_generation);
        $connection->table('obfuscation_recovery_controls')->where('scope', $scope)->lockForUpdate()->first();
        if ((int) ($scan->evidence_version ?? 1) < self::VERSION) {
            return;
        }
        $points = json_decode($scan->date_points ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $conflicts = json_decode($scan->date_conflicts ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $seen = [];
        foreach ($points as $point) {
            $article = $point[0];
            if (isset($seen[$article]) && ($seen[$article][1] !== $point[1]
                || (isset($seen[$article][2], $point[2]) && $seen[$article][2] !== $point[2]))) {
                $conflicts[] = $article;
            }
            $seen[$article] = $point;
        }
        foreach (array_chunk(array_values($seen), 250) as $batch) {
            $existing = $connection->table('obfuscation_recovery_frontiers')->where('scope_digest', $scope)
                ->whereIn('article_number', array_column($batch, 0))->get()->keyBy('article_number');
            foreach ($batch as $point) {
                $previous = $existing[$point[0]] ?? null;
                if ($previous !== null && ($previous->postdate !== $point[1]
                    || ($previous->observation_digest !== null && isset($point[2]) && $previous->observation_digest !== $point[2]))) {
                    $conflicts[] = $point[0];
                }
            }
        }
        foreach (array_chunk(json_decode($scan->invalid_date_articles ?? '[]', true, flags: JSON_THROW_ON_ERROR), 250) as $articles) {
            $existing = $connection->table('obfuscation_recovery_frontiers')->where('scope_digest', $scope)
                ->whereIn('article_number', $articles)->pluck('article_number')->all();
            $conflicts = [...$conflicts, ...array_map(intval(...), $existing), ...array_values(array_intersect($articles, array_keys($seen)))];
        }
        foreach (array_chunk(array_values(array_unique($conflicts)), 250) as $batch) {
            $connection->table('obfuscation_recovery_frontier_conflicts')->insertOrIgnore(array_map(
                fn (int $article): array => $this->conflict($scope, 'contradiction', $article, $article, self::VERSION), $batch));
        }
        ksort($seen, SORT_NUMERIC);
        $summary = $this->summary($this->usable($connection, $scope, array_values($seen)));
        $this->savePoints($connection, $scope, $summary, $scan->direction === 'Head');
        $this->saveRange($connection, $scope, (int) $scan->requested_first, (int) $scan->requested_last,
            $summary, $scan->direction === 'Head', count($seen) <= self::SUMMARY_POINTS);
        $this->replaceLegacy($connection, $scope, (int) $scan->requested_first, (int) $scan->requested_last);
    }

    /** @param list<array{0:int,1:string,2?:?string}> $points
     * @return list<array{0:int,1:string,2?:?string}>
     */
    public function summary(array $points): array
    {
        if (count($points) <= self::SUMMARY_POINTS) {
            return $points;
        }
        $selected = [];
        foreach (array_chunk($points, (int) ceil(count($points) / 32)) as $chunk) {
            $minimum = $maximum = $chunk[0];
            foreach ($chunk as $point) {
                if ($point[1] < $minimum[1]) {
                    $minimum = $point;
                }
                if ($point[1] > $maximum[1]) {
                    $maximum = $point;
                }
            }
            foreach ([$chunk[0], $chunk[count($chunk) - 1], $minimum, $maximum] as $point) {
                $selected[$point[0]] = $point;
            }
        }
        ksort($selected, SORT_NUMERIC);

        return array_values($selected);
    }

    /** @param list<array{0:int,1:string,2?:?string}> $points */
    public function savePoints(Connection $connection, string $scope, array $points, bool $head): void
    {
        foreach (array_chunk($points, 250) as $batch) {
            $connection->table('obfuscation_recovery_frontiers')->insertOrIgnore(array_map(static fn (array $point): array => [
                'scope_digest' => $scope, 'article_number' => $point[0], 'postdate' => $point[1],
                'head_observed' => $head, 'evidence_version' => self::VERSION, 'observation_digest' => $point[2] ?? null,
            ], $batch));
            if ($head) {
                $connection->table('obfuscation_recovery_frontiers')->where('scope_digest', $scope)
                    ->whereIn('article_number', array_column($batch, 0))->update(['head_observed' => true]);
            }
        }
    }

    /** @param list<array{0:int,1:string,2?:?string}> $points */
    public function saveRange(Connection $connection, string $scope, int $first, int $last, array $points, bool $head, bool $exhaustive): void
    {
        $identity = (new RecoveryIdentity)->digest([$scope, (string) self::VERSION, (string) $first, (string) $last, (string) $head]);
        $connection->table('obfuscation_recovery_frontier_ranges')->upsert([
            'identity' => $identity, 'scope_digest' => $scope, 'evidence_version' => self::VERSION,
            'first_article' => $first, 'last_article' => $last, 'head_observed' => $head,
            'exhaustive' => $exhaustive, 'points' => json_encode($points, JSON_THROW_ON_ERROR), 'observed_at' => now(),
        ], ['identity'], ['points', 'exhaustive', 'observed_at']);
    }

    public function replaceLegacy(Connection $connection, string $scope, int $first, int $last): void
    {
        foreach (['unknown', 'ordering'] as $kind) {
            $rows = RecoveryFrontierConflicts::overlapping($connection, $scope, $first, $last, [$kind])
                ->limit(100)->lockForUpdate()->get();
            foreach ($rows as $row) {
                foreach (RecoveryCoverage::holes((int) $row->first_article, (int) $row->last_article, [[$first, $last]]) as [$start, $end]) {
                    $connection->table('obfuscation_recovery_frontier_conflicts')->insertOrIgnore($this->conflict($scope, $kind, $start, $end, 1));
                }
                $connection->table('obfuscation_recovery_frontier_conflicts')->where('identity', $row->identity)->delete();
            }
        }
    }

    /** @return array{identity:string,scope_digest:string,kind:string,first_article:int,last_article:int,evidence_version:int} */
    private function conflict(string $scope, string $kind, int $first, int $last, int $version): array
    {
        return ['identity' => (new RecoveryIdentity)->digest([$scope, $kind, (string) $first, (string) $last]),
            'scope_digest' => $scope, 'kind' => $kind, 'first_article' => $first, 'last_article' => $last, 'evidence_version' => $version];
    }
}
