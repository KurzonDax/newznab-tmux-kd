<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;

final class RecoveryVerifiedMembership
{
    private function plan(object $bundle): ?RecoveryPlan
    {
        if ($bundle->profile !== RecoveryAlgorithm::Media->value || $bundle->sealed_plan === null
            || $bundle->manifest_verified_at === null || ! in_array($bundle->state, ['ready', 'publishing', 'published'], true)) {
            return null;
        }

        return RecoveryPlan::fromArray(json_decode($bundle->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
    }

    /** @param list<array<string,mixed>> $rows */
    public function unrelated(Connection $connection, object $bundle, array $rows): bool
    {
        $plan = $this->plan($bundle);
        if ($plan === null) {
            return false;
        }
        $totals = array_map(static fn (RecoveryFilePlan $file): int => $file->totalParts, $plan->files);
        foreach ($rows as $row) {
            if (! isset($row['advertised_total'], $row['message_id'], $row['article_number'])) {
                return false;
            }
            if ((int) $row['embedded_timestamp_ms'] < (int) $bundle->start_ms - 30000
                || (int) $row['embedded_timestamp_ms'] > (int) $bundle->end_ms + 30000) {
                continue;
            }
            if ((int) $row['embedded_timestamp_ms'] < (int) $bundle->start_ms
                || (int) $row['embedded_timestamp_ms'] > (int) $bundle->end_ms
                || in_array((int) $row['advertised_total'], $totals, true) || ($row['metadata_conflict'] ?? false)) {
                return false;
            }
            $conflict = $connection->table('obfuscation_recovery_headers')->where('source_epoch', $bundle->source_epoch)
                ->where('groups_id', $bundle->groups_id)->where('capture_generation', $bundle->capture_generation)
                ->where(fn ($query) => $query->where('message_id', $row['message_id'])->orWhere('article_number', $row['article_number']))
                ->where(fn ($query) => $query->where('metadata_conflict', true)->orWhereIn('advertised_total', $totals))->exists();
            if ($conflict) {
                return false;
            }
        }

        $coverage = json_decode($bundle->coverage_evidence ?? 'null', true, flags: JSON_THROW_ON_ERROR);
        if (is_array($coverage) && ! isset($coverage['selected_runs'])) {
            $runs = $connection->table('obfuscation_recovery_runs')
                ->whereIn('id', json_decode($bundle->candidate_runs ?? '[]', true, flags: JSON_THROW_ON_ERROR))
                ->orderBy('start_ms')->orderBy('id')->get()->all();
            if (! $this->unchanged($bundle, $runs)) {
                return false;
            }
            $coverage['selected_runs'] = $this->selected($plan, $runs);
            $connection->table('obfuscation_recovery_bundles')->where('id', $bundle->id)
                ->update(['coverage_evidence' => json_encode($coverage, JSON_THROW_ON_ERROR)]);
        }

        return true;
    }

    /** @param list<object> $runs
     * @return array<int,string>
     */
    public function selected(RecoveryPlan $plan, array $runs): array
    {
        $totals = array_map(static fn (RecoveryFilePlan $file): int => $file->totalParts, $plan->files);
        $selected = [];
        foreach ($runs as $run) {
            if (in_array((int) $run->partition_value, $totals, true)) {
                $selected[(int) $run->id] = $run->scope_digest.$run->membership_digest;
            }
        }
        ksort($selected, SORT_NUMERIC);

        return $selected;
    }

    /** @param list<object> $runs */
    public function unchanged(object $bundle, array $runs): bool
    {
        $plan = $this->plan($bundle);
        if ($plan === null) {
            return false;
        }
        $coverage = json_decode($bundle->coverage_evidence ?? 'null', true, flags: JSON_THROW_ON_ERROR);
        $ids = json_decode($bundle->candidate_runs ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $original = [];
        $totals = array_map(static fn (RecoveryFilePlan $file): int => $file->totalParts, $plan->files);
        foreach ($runs as $run) {
            if ((int) $run->capture_generation !== (int) $bundle->capture_generation
                || (int) $run->start_ms < (int) $bundle->start_ms || (int) $run->end_ms > (int) $bundle->end_ms
                || json_decode($run->summary, true, flags: JSON_THROW_ON_ERROR)['metadata_conflict']) {
                return false;
            }
            if (in_array((int) $run->id, $ids, true)) {
                $original[(int) $run->id] = $run;
            } elseif (in_array((int) $run->partition_value, $totals, true)) {
                return false;
            }
        }
        if (isset($coverage['selected_runs'])) {
            return $this->selected($plan, $runs) === $coverage['selected_runs'];
        }
        if (count($original) !== count($ids)) {
            return false;
        }
        $snapshot = (new RecoveryIdentity)->digest(array_map(static fn (int $id): string => $original[$id]->scope_digest.$original[$id]->membership_digest, $ids));

        return $snapshot === $bundle->snapshot_digest;
    }
}
