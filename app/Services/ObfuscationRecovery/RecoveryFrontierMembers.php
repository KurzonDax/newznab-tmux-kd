<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final class RecoveryFrontierMembers
{
    private function progress(object $bundle): string
    {
        return (new RecoveryIdentity)->digest(['frontier-members', (string) $bundle->id, (string) $bundle->revision, hash('sha256', $bundle->sealed_plan)]);
    }

    public function complete(Connection $connection, object $bundle): bool
    {
        $plan = json_decode($bundle->sealed_plan, true, flags: JSON_THROW_ON_ERROR);

        return (int) $connection->table('obfuscation_recovery_frontier_progress')->where('scope', $this->progress($bundle))->value('cursor')
            === (int) $plan['manifest_bytes'];
    }

    public function advance(Connection $connection, object $bundle): bool
    {
        if ($this->complete($connection, $bundle)) {
            return true;
        }
        $scope = $this->progress($bundle);
        $connection->table('obfuscation_recovery_frontier_progress')->insertOrIgnore(['scope' => $scope]);
        $progress = $connection->table('obfuscation_recovery_frontier_progress')->where('scope', $scope)->lockForUpdate()->first();
        $plan = json_decode($bundle->sealed_plan, true, flags: JSON_THROW_ON_ERROR);
        $page = app(RecoveryManifest::class)->page(new RecoveryArtifact($plan['manifest_digest'], $plan['manifest_bytes']), (int) $progress->cursor);
        $rows = [];
        foreach ($page['records'] as $record) {
            if ($record['source_epoch'] !== $bundle->source_epoch || $record['group_id'] !== (int) $bundle->groups_id
                || $record['capture_generation'] !== (int) $bundle->capture_generation) {
                throw new \InvalidArgumentException('frontier_manifest_scope');
            }
            $rows[] = ['bundle_id' => $bundle->id, 'revision' => $bundle->revision, 'article_number' => $record['article_number'],
                'postdate' => $record['postdate'], 'embedded_timestamp_ms' => $record['embedded_timestamp_ms'],
                'observation_digest' => RecoveryCoverage::observationDigest(['Message-ID' => base64_decode($record['source_message_id'], true),
                    'Subject' => base64_decode($record['raw_subject'], true), 'From' => base64_decode($record['poster_identity'], true), 'Bytes' => $record['advertised_bytes']])];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            $connection->table('obfuscation_recovery_frontier_members')->insertOrIgnore($chunk);
        }
        $connection->table('obfuscation_recovery_frontier_progress')->where('scope', $scope)->update(['cursor' => $page['offset']]);

        return $page['offset'] === $plan['manifest_bytes'];
    }

    public function raw(Connection $connection, object $bundle): Builder
    {
        $ids = json_decode($bundle->candidate_runs ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $query = $connection->table('obfuscation_recovery_headers')->where('source_epoch', $bundle->source_epoch)
            ->where('groups_id', $bundle->groups_id)->where('capture_generation', $bundle->capture_generation)->where('profile', $bundle->profile);
        if (count($ids) > 256) {
            throw new \InvalidArgumentException('invalid_candidate_snapshot');
        }

        return $query->whereExists(function (Builder $runs) use ($ids, $bundle): void {
            $runs->selectRaw('1')->from('obfuscation_recovery_runs as member_run')->whereIn('member_run.id', $ids)
                ->whereColumn('member_run.start_ms', '<=', 'obfuscation_recovery_headers.embedded_timestamp_ms')
                ->whereColumn('member_run.end_ms', '>=', 'obfuscation_recovery_headers.embedded_timestamp_ms');
            if ($bundle->profile === RecoveryAlgorithm::Media->value) {
                $runs->whereColumn('member_run.partition_value', 'obfuscation_recovery_headers.advertised_total');
            } else {
                $runs->whereColumn('member_run.partition_value', 'obfuscation_recovery_headers.key_digest');
            }
        });
    }

    /** @param array<string,mixed> $coverage */
    public function invalidateRaw(Connection $connection, RecoveryScanContext $context, array $coverage): void
    {
        $points = array_column($coverage['date_points'], null, 0);
        $invalid = array_fill_keys([...$coverage['invalid_date_articles'], ...$coverage['date_conflicts']], true);
        $changed = [];
        foreach (array_chunk(array_unique([...array_keys($points), ...array_keys($invalid)]), 250) as $articles) {
            $records = $connection->table('obfuscation_recovery_headers')->where('source_epoch', $context->sourceEpoch)
                ->where('groups_id', $context->groupId)->whereIn('article_number', $articles)->limit(501)->get();
            if ($records->count() > 500) {
                throw new \InvalidArgumentException('frontier_raw_identity_cap');
            }
            foreach ($records as $record) {
                $point = $points[$record->article_number] ?? null;
                $digest = RecoveryCoverage::observationDigest(['Message-ID' => $record->source_message_id, 'Subject' => $record->raw_subject,
                    'From' => $record->poster_identity, 'Bytes' => $record->advertised_bytes]);
                if (! $record->metadata_conflict && (isset($invalid[$record->article_number])
                    || ($point !== null && ($point[1] !== $record->postdate || ($point[2] ?? null) !== $digest)))) {
                    $connection->table('obfuscation_recovery_headers')->where('id', $record->id)->update(['metadata_conflict' => true]);
                    $changed[(int) $record->capture_generation][] = (array) $record;
                }
            }
        }
        foreach ($changed as $rows) {
            RecoveryLateCapture::invalidate($connection, $rows);
            RecoveryDirty::mark($connection, $rows);
        }
    }

    /** @param list<object> $targets */
    public function validate(Connection $connection, object $scan, array $targets): bool
    {
        $points = array_column(json_decode($scan->date_points, true, flags: JSON_THROW_ON_ERROR), null, 0);
        $invalid = array_fill_keys(json_decode($scan->invalid_date_articles, true, flags: JSON_THROW_ON_ERROR), true);
        $contradictions = array_fill_keys(json_decode($scan->date_conflicts, true, flags: JSON_THROW_ON_ERROR), true);
        $valid = true;
        foreach ($targets as $target) {
            if ($target->plan_digest === null) {
                continue;
            }
            $bundle = $connection->table('obfuscation_recovery_bundles')->where('id', $target->bundle_id)->first();
            $members = $connection->table('obfuscation_recovery_frontier_members')->where('bundle_id', $target->bundle_id)->where('revision', $target->revision)
                ->whereBetween('article_number', [(int) $target->first_article, (int) $target->last_article])->limit(20001)->get();
            if ($members->count() > 20000) {
                throw new \InvalidArgumentException('frontier_member_range_cap');
            }
            $changed = [];
            foreach ($members as $member) {
                $point = $points[$member->article_number] ?? null;
                if (isset($invalid[$member->article_number]) || isset($contradictions[$member->article_number])
                    || ($point !== null && ($point[1] !== $member->postdate || ($point[2] ?? null) !== $member->observation_digest))) {
                    $changed[] = ['source_epoch' => $bundle->source_epoch, 'groups_id' => $bundle->groups_id, 'capture_generation' => $bundle->capture_generation,
                        'profile' => $bundle->profile, 'key_digest' => $bundle->key_digest, 'embedded_timestamp_ms' => $member->embedded_timestamp_ms];
                }
            }
            if ($changed !== []) {
                RecoveryLateCapture::invalidate($connection, $changed);
                $valid = false;
            }
        }

        return $valid;
    }
}
