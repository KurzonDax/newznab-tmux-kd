<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;

final class RecoveryFrontiers
{
    public function record(Connection $connection, object $scan): void
    {
        if ($connection->transactionLevel() < 1) {
            throw new \LogicException('frontier_transaction_required');
        }
        $scope = RecoveryPositiveCoverage::scope($scan->source_epoch, (int) $scan->groups_id, (int) $scan->capture_generation);
        $connection->table('obfuscation_recovery_controls')->where('scope', $scope)->lockForUpdate()->first();
        if (! $scan->date_order_consistent) {
            foreach (array_chunk(json_decode($scan->returned_ranges ?? '[]', true, flags: JSON_THROW_ON_ERROR), 250) as $ranges) {
                $conflicts = [];
                foreach ($ranges as [$first, $last]) {
                    $conflicts[] = $this->conflict($scope, 'unknown', $first, $last);
                }
                $connection->table('obfuscation_recovery_frontier_conflicts')->insertOrIgnore($conflicts);
            }

            return;
        }
        $points = json_decode($scan->date_points ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        foreach (array_chunk($points, 250) as $batch) {
            $articles = array_column($batch, 0);
            $query = $connection->table('obfuscation_recovery_frontiers')->where('scope_digest', $scope);
            $existing = (clone $query)->whereIn('article_number', $articles)->pluck('postdate', 'article_number');
            $insert = $conflicts = [];
            foreach ($batch as [$article, $date]) {
                if (isset($existing[$article]) && $existing[$article] !== $date) {
                    $conflicts[] = $this->conflict($scope, 'ordering', $article, $article);
                }
                $insert[] = ['scope_digest' => $scope, 'article_number' => $article, 'postdate' => $date,
                    'head_observed' => $scan->direction === 'Head'];
            }
            $connection->table('obfuscation_recovery_frontiers')->insertOrIgnore($insert);
            if ($scan->direction === 'Head') {
                (clone $query)->whereIn('article_number', $articles)->update(['head_observed' => true]);
            }
            $adjacent = $this->adjacentConflicts($connection, $scope, min($articles), max($articles));
            if ($adjacent !== null) {
                $conflicts = [...$conflicts, ...$adjacent];
            } else {
                $neighbors = null;
                $canonicalDates = [];
                foreach ($batch as [$article, $date]) {
                    $canonicalDates[$article] = $existing[$article] ?? $date;
                    foreach (['previous' => '<', 'next' => '>'] as $side => $operator) {
                        $neighbor = (clone $query)->select(['article_number', 'postdate'])
                            ->selectRaw('? as compared_article, ? as side', [$article, $side])
                            ->where('article_number', $operator, $article)
                            ->orderBy('article_number', $side === 'previous' ? 'desc' : 'asc')->limit(1);
                        if ($connection instanceof MySqlConnection) {
                            $neighbor->forceIndex('PRIMARY');
                        }
                        $branch = $connection->query()->fromSub($neighbor, 'neighbor')->select('*');
                        $neighbors = $neighbors === null ? $branch : $neighbors->unionAll($branch);
                    }
                }
                foreach ($neighbors->get() as $point) {
                    $article = (int) $point->compared_article;
                    if ($point->side === 'previous' && $point->postdate > $canonicalDates[$article]) {
                        $conflicts[] = $this->conflict($scope, 'ordering', (int) $point->article_number, $article);
                    }
                    if ($point->side === 'next' && $point->postdate < $canonicalDates[$article]) {
                        $conflicts[] = $this->conflict($scope, 'ordering', $article, (int) $point->article_number);
                    }
                }
            }
            foreach (array_chunk($conflicts, 250) as $chunk) {
                $connection->table('obfuscation_recovery_frontier_conflicts')->insertOrIgnore($chunk);
            }
        }
    }

    /** @return list<array{identity:string,scope_digest:string,kind:string,first_article:int,last_article:int}>|null */
    private function adjacentConflicts(Connection $connection, string $scope, int $first, int $last): ?array
    {
        $query = $connection->table('obfuscation_recovery_frontiers')->where('scope_digest', $scope);
        $points = (clone $query)->whereBetween('article_number', [$first, $last])->orderBy('article_number')->limit(1001)->get();
        if ($points->count() > 1000) {
            return null;
        }
        $previous = (clone $query)->where('article_number', '<', $first)->orderByDesc('article_number')->first();
        $next = (clone $query)->where('article_number', '>', $last)->orderBy('article_number')->first();
        if ($next !== null) {
            $points->push($next);
        }
        $conflicts = [];
        foreach ($points as $point) {
            if ($previous !== null && $previous->postdate > $point->postdate) {
                $conflicts[] = $this->conflict($scope, 'ordering', (int) $previous->article_number, (int) $point->article_number);
            }
            $previous = $point;
        }

        return $conflicts;
    }

    /** @return array{identity:string,scope_digest:string,kind:string,first_article:int,last_article:int} */
    private function conflict(string $scope, string $kind, int $first, int $last): array
    {
        $digest = (new RecoveryIdentity)->digest([$scope, $kind, (string) $first, (string) $last]);

        return [
            'identity' => $digest, 'scope_digest' => $scope, 'kind' => $kind, 'first_article' => $first, 'last_article' => $last,
        ];
    }
}
