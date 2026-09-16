<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecoveryHistoryRetention
{
    /** @return array<string,int> */
    public function step(RecoveryConfig $config, int $limit = 1000): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('invalid_history_retention_batch');
        }
        $cutoff = now()->subHours(min($config->retentionHours, 876000))->subHour();
        $report = [];
        foreach (['scans' => 'id', 'scan_batches' => 'scan_id'] as $table => $key) {
            $query = DB::table('obfuscation_recovery_'.$table)->where('created_at', '<', $cutoff);
            $report['expired_'.$table] = $this->delete($query, $key, 'created_at', $limit);
        }
        $incomplete = DB::table('obfuscation_recovery_scans')->where('complete', false)
            ->where('created_at', '<', now()->subDay())->whereNotNull('date_points');
        $ids = (clone $incomplete)->orderBy('created_at')->orderBy('id')->limit($limit)->pluck('id');
        $report['compacted_incomplete_scans'] = $incomplete->whereIn('id', $ids)->update([
            'date_points' => null, 'date_conflicts' => null, 'invalid_date_articles' => null,
        ]);

        $report += ['expired_frontiers' => 0, 'expired_frontier_conflicts' => 0, 'expired_frontier_ranges' => 0];
        // Retained coverage and scan windows outlive raw headers and scan details.
        $columns = ['source_epoch', 'groups_id', 'capture_generation'];
        $scopes = DB::table('obfuscation_recovery_coverage')->select($columns)->distinct()
            ->union(DB::table('obfuscation_recovery_scan_windows')->select($columns)->distinct())->get();
        foreach ($scopes as $scope) {
            $digest = RecoveryPositiveCoverage::scope($scope->source_epoch, (int) $scope->groups_id, (int) $scope->capture_generation);
            // Compute once, before any mutations, without holding a group or coverage lock.
            $floor = $this->floor($scope, $digest, $config);
            if ($floor === null) {
                continue;
            }
            $articles = [];
            foreach ([false, true] as $head) {
                $article = DB::table('obfuscation_recovery_frontiers')->where('scope_digest', $digest)
                    ->where('head_observed', $head)->where('postdate', '>=', $floor['postdate'])
                    ->orderBy('postdate')->orderBy('article_number')->value('article_number');
                if ($article !== null) {
                    $articles[] = (int) $article;
                }
            }
            foreach ([false, true] as $head) {
                $query = DB::table('obfuscation_recovery_frontiers')->where('scope_digest', $digest)
                    ->where('head_observed', $head)->where('postdate', '<', $floor['postdate']);
                $report['expired_frontiers'] += $this->delete($query, 'article_number', 'postdate', $limit);
            }
            if ($articles === [] && ! $floor['empty']) {
                continue;
            }
            // With no live owner/header or surviving point there is no article boundary to retain.
            $floorArticle = $articles === [] ? PHP_INT_MAX : min($articles);
            // Dates can run backwards. Do not remove conflicts for surviving points or raw headers,
            // including old points still awaiting their bounded delete. This lookup uses the primary key.
            $remaining = DB::table('obfuscation_recovery_frontiers')->where('scope_digest', $digest)
                ->orderBy('article_number')->value('article_number');
            $floorArticle = min($floorArticle, (int) ($remaining ?? PHP_INT_MAX), $floor['header_article'] ?? PHP_INT_MAX);
            foreach (['unknown', 'ordering', 'contradiction'] as $kind) {
                $query = DB::table('obfuscation_recovery_frontier_conflicts')->where('scope_digest', $digest)
                    ->where('kind', $kind)->where('last_article', '<', $floorArticle);
                $report['expired_frontier_conflicts'] += $this->delete($query, 'identity', 'last_article', $limit);
            }
            $query = DB::table('obfuscation_recovery_frontier_ranges')->where('scope_digest', $digest)->where('last_article', '<', $floorArticle);
            $report['expired_frontier_ranges'] += $this->delete($query, 'id', 'last_article', $limit);
        }

        return $report;
    }

    /** @return array{postdate:string,empty:bool,header_article:?int}|null */
    private function floor(object $scope, string $digest, RecoveryConfig $config): ?array
    {
        // The header index spans generations; an older live generation must also protect history.
        $header = DB::table('obfuscation_recovery_headers')->where('source_epoch', $scope->source_epoch)
            ->where('groups_id', $scope->groups_id)->orderBy('article_number')->first(['article_number', 'postdate']);
        $date = $header->postdate ?? null;
        $bundles = DB::table('obfuscation_recovery_bundles')->where('source_epoch', $scope->source_epoch)
            ->where('groups_id', $scope->groups_id)->where('capture_generation', $scope->capture_generation)
            ->where('kind', 'posting')->whereNotIn('state', RecoveryOwnership::INACTIVE_STATES)
            ->where(fn (Builder $query) => $query->where('state', '!=', 'published')->orWhereExists(
                fn (Builder $publication) => $publication->selectRaw('1')->from('obfuscation_recovery_publications')
                    ->whereColumn('id', 'obfuscation_recovery_bundles.publication_id')->where('initialization_state', 'pending')));
        foreach ($bundles->cursor() as $bundle) {
            $envelope = (new RecoveryFrontierRebuild)->envelope($bundle);
            if ($envelope === null) {
                return null;
            }
            $left = (new RecoveryFrontierRequirement)->witnesses(DB::connection(), $digest, $envelope)['left'];
            $witnessDate = $left === null ? null : DB::table('obfuscation_recovery_frontiers')
                ->where('scope_digest', $digest)->where('article_number', $left)->value('postdate');
            // An unresolved live candidate has not yet established a safe boundary to retire.
            if ($witnessDate === null) {
                return null;
            }
            $date = $date === null ? $witnessDate : min($date, $witnessDate);
        }

        return ['empty' => $date === null, 'header_article' => $header === null ? null : (int) $header->article_number,
            'postdate' => ($date === null ? now('UTC')->subHours(min($config->retentionHours, 876000)) : Carbon::parse($date, 'UTC'))
                ->subDay()->format('Y-m-d H:i:s')];
    }

    private function delete(Builder $query, string $key, string $order, int $limit): int
    {
        $keys = (clone $query)->orderBy($order)->limit($limit)->pluck($key);

        return $query->whereIn($key, $keys)->delete();
    }
}
