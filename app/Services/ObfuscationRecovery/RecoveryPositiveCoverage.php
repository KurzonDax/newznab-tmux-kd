<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;

final class RecoveryPositiveCoverage
{
    public function record(Connection $connection, RecoveryScanContext $context): void
    {
        $scope = $this->lock($connection, $context->sourceEpoch, $context->groupId, $context->generation);
        foreach (['captured', 'retained'] as $kind) {
            $query = $connection->table('obfuscation_recovery_coverage')->where('scope_digest', $scope)
                ->where('kind', $kind)->where('direction', $context->direction->name);
            $rows = (clone $query)->where('first_article', '<=', $context->last + 1)->where('last_article', '>=', $context->first - 1)
                ->orderBy('first_article')->limit(101)->lockForUpdate()->get();
            if ($rows->count() > 100) {
                throw new \RuntimeException('coverage_merge_batch_limit');
            }
            $first = min($context->first, (int) ($rows->min('first_article') ?? $context->first));
            $last = max($context->last, (int) ($rows->max('last_article') ?? $context->last));
            if ($rows->isEmpty()) {
                $connection->table('obfuscation_recovery_coverage')->insert([
                    'scope_digest' => $scope, 'source_epoch' => $context->sourceEpoch, 'groups_id' => $context->groupId,
                    'capture_generation' => $context->generation, 'kind' => $kind, 'direction' => $context->direction->name,
                    'first_article' => $first, 'last_article' => $last,
                ]);
            } else {
                $id = $rows->first()->id;
                (clone $query)->where('id', $id)->update(['first_article' => $first, 'last_article' => $last]);
                (clone $query)->whereIn('id', $rows->pluck('id'))->where('id', '!=', $id)->delete();
            }
        }
    }

    public function expire(Connection $connection, string $epoch, int $group, int $generation, int $article): void
    {
        $scope = $this->lock($connection, $epoch, $group, $generation);
        $rows = $connection->table('obfuscation_recovery_coverage')->where('scope_digest', $scope)->where('kind', 'captured')
            ->where('first_article', '<=', $article)->where('last_article', '>=', $article)->lockForUpdate()->get();
        foreach ($rows as $row) {
            $connection->table('obfuscation_recovery_coverage')->where('id', $row->id)->delete();
            foreach ([[(int) $row->first_article, $article - 1], [$article + 1, (int) $row->last_article]] as [$first, $last]) {
                if ($first <= $last) {
                    $values = (array) $row;
                    unset($values['id']);
                    $connection->table('obfuscation_recovery_coverage')->insert([...$values, 'first_article' => $first, 'last_article' => $last]);
                }
            }
        }
    }

    private function lock(Connection $connection, string $epoch, int $group, int $generation): string
    {
        if ($connection->transactionLevel() < 1) {
            throw new \LogicException('coverage_transaction_required');
        }
        $scope = self::scope($epoch, $group, $generation);
        $connection->table('obfuscation_recovery_controls')->insertOrIgnore([
            'scope' => $scope, 'fingerprint' => $scope, 'epoch' => (string) Str::uuid(), 'generation' => 1, 'updated_at' => now(),
        ]);
        $connection->table('obfuscation_recovery_controls')->where('scope', $scope)->lockForUpdate()->first();

        return $scope;
    }

    public static function scope(string $epoch, int $group, int $generation): string
    {
        return (new RecoveryIdentity)->digest(['positive-coverage', $epoch, (string) $group, (string) $generation]);
    }
}
