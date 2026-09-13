<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;

final class RecoveryFrontierTargets
{
    /** @param array<string,mixed> $payload
     * @return list<object>|null
     */
    public function authorized(Connection $connection, object $owner, array $payload, bool $lock = false): ?array
    {
        $request = $connection->table('obfuscation_recovery_frontier_requests')->where('bundle_id', $owner->id)->first();
        $source = $connection->table('obfuscation_recovery_controls')->where('scope', 'primary')->first();
        $control = $connection->table('obfuscation_recovery_controls')->where('scope', 'group:'.$owner->groups_id)->first();
        $selection = $connection->table('usenet_groups')->where('id', $owner->groups_id)->value('obfuscation_recovery_profile');
        $settings = $connection->table('settings')->whereIn('name', ['obfuscation_recovery_enabled', 'obfuscation_recovery_media_candidate_mib', 'obfuscation_recovery_rar_candidate_mib'])->pluck('value', 'name')->all();
        if ($request === null || $source === null || $control === null || $owner->kind !== 'frontier'
            || $source->epoch !== $owner->source_epoch || (int) $control->generation !== (int) $owner->capture_generation
            || (int) $request->capture_generation !== (int) $owner->capture_generation || $request->source_epoch !== $owner->source_epoch
            || (int) $request->groups_id !== (int) $owner->groups_id || (int) $request->evidence_version !== RecoveryFrontiers::VERSION
            || ($payload['version'] ?? null) !== RecoveryFrontiers::VERSION
            || ($payload['first'] ?? null) !== (int) $request->requested_first || ($payload['last'] ?? null) !== (int) $request->requested_last
            || ! RecoveryConfig::fromValues($settings)->admits($selection, RecoveryAlgorithm::from($owner->profile)->selection())) {
            return null;
        }
        $targets = $connection->table('obfuscation_recovery_frontier_targets')->where('request_id', $request->id)->orderBy('bundle_id')->limit(17)->get();
        if ($targets->isEmpty() || $targets->count() > 16) {
            return null;
        }
        $valid = [];
        foreach ($targets as $target) {
            $query = $connection->table('obfuscation_recovery_bundles')->where('id', $target->bundle_id);
            $bundle = ($lock ? $query->lockForUpdate() : $query)->first();
            $sealed = $bundle !== null && (new RecoveryFrontierRebuild)->sealed($bundle);
            if ($bundle === null || (int) $bundle->revision !== (int) $target->revision
                || $bundle->source_epoch !== $owner->source_epoch || (int) $bundle->groups_id !== (int) $owner->groups_id
                || (int) $bundle->capture_generation !== (int) $target->capture_generation
                || ($target->plan_digest !== null && (! $sealed || hash('sha256', $bundle->sealed_plan) !== $target->plan_digest))
                || ($target->plan_digest === null && ($sealed || $bundle->state !== 'collecting' || (int) $bundle->capture_generation !== (int) $owner->capture_generation))
                || ! RecoveryConfig::fromValues($settings)->admits($selection, RecoveryAlgorithm::from($bundle->profile)->selection())
                || (int) $target->first_article !== (int) $request->requested_first || (int) $target->last_article !== (int) $request->requested_last
                || ! (new RecoveryFrontierEvidence)->retainedHead($connection, $bundle, (int) $target->first_article, (int) $target->last_article, $sealed)) {
                continue;
            }
            if ($sealed && ! (new RecoveryFrontierMembers)->complete($connection, $bundle)) {
                continue;
            }
            $valid[] = $target;
        }

        return $valid === [] ? null : $valid;
    }

    /** @param array<string,mixed> $payload */
    public function sufficient(Connection $connection, object $owner, array $payload): bool
    {
        $targets = $this->authorized($connection, $owner, $payload);
        if ($targets === null) {
            return false;
        }
        foreach ($targets as $target) {
            $scope = RecoveryPositiveCoverage::scope($owner->source_epoch, (int) $owner->groups_id, (int) $target->capture_generation);
            if ($target->plan_digest !== null && $target->outcome !== 'examined') {
                $unverified = $connection->table('obfuscation_recovery_frontier_members as member')
                    ->leftJoin('obfuscation_recovery_frontiers as point', function ($join) use ($scope): void {
                        $join->on('point.article_number', '=', 'member.article_number')->where('point.scope_digest', $scope);
                    })->where('member.bundle_id', $target->bundle_id)->where('member.revision', $target->revision)
                    ->whereBetween('member.article_number', [(int) $target->first_article, (int) $target->last_article])
                    ->where(fn ($query) => $query->whereNull('point.observation_digest')->orWhereColumn('point.observation_digest', '!=', 'member.observation_digest')
                        ->orWhereColumn('point.postdate', '!=', 'member.postdate'))->exists();
                if ($unverified) {
                    return false;
                }
            }
            if ((new RecoveryFrontierEvidence)->answer($connection, $scope, (int) $target->first_article, (int) $target->last_article,
                json_decode($target->envelope, true, flags: JSON_THROW_ON_ERROR)) !== 'examined') {
                return false;
            }
        }

        return true;
    }

    /** @param iterable<object> $chunks
     * @param  list<object>  $targets
     */
    public function install(Connection $connection, iterable $chunks, array $targets): void
    {
        $template = (new RecoveryFrontiers)->combine($chunks);
        if ($template === null) {
            return;
        }
        $points = json_decode($template->date_points, true, flags: JSON_THROW_ON_ERROR);
        foreach ($targets as $target) {
            $scan = clone $template;
            $scan->capture_generation = $target->capture_generation;
            $scan->direction = 'Head';
            $scan->date_points = json_encode($points, JSON_THROW_ON_ERROR);
            $frontiers = new RecoveryFrontiers;
            $frontiers->record($connection, $scan);
            $envelope = json_decode($target->envelope, true, flags: JSON_THROW_ON_ERROR);
            $scope = RecoveryPositiveCoverage::scope($scan->source_epoch, (int) $scan->groups_id, (int) $target->capture_generation);
            foreach ([[(int) $target->first_article, min((int) $target->last_article, $envelope['first_article'] - 1)],
                [max((int) $target->first_article, $envelope['last_article'] + 1), (int) $target->last_article]] as [$first, $last]) {
                if ($first > $last) {
                    continue;
                }
                $side = array_values(array_filter($points, static fn (array $point): bool => $point[0] >= $first && $point[0] <= $last));
                $summary = $frontiers->summary($frontiers->usable($connection, $scope, $side));
                $frontiers->savePoints($connection, $scope, $summary, true);
                $frontiers->saveRange($connection, $scope, $first, $last, $summary, true, count($side) <= RecoveryFrontiers::SUMMARY_POINTS);
            }
            $connection->table('obfuscation_recovery_frontier_targets')->where('id', $target->id)->update(['outcome' => 'examined']);
            $connection->table('obfuscation_recovery_work')->where('bundle_id', $target->bundle_id)->where('revision', $target->revision)
                ->where('status', 'pending')->whereIn('stage', [RecoveryStage::Discover->value, RecoveryStage::Publish->value])->update(['due_at' => now()]);
        }
    }
}
