<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecoveryConstructionTargets
{
    /** @param list<array{kind:string,file_id:?string,message_id:string}> $targets */
    public function register(RecoveryWorkClaim $claim, array $targets): void
    {
        if (count($targets) > 97 || $claim->stage === RecoveryStage::Publish) {
            throw new InvalidArgumentException('invalid_construction_target_plan');
        }
        DB::transaction(function () use ($claim, $targets): void {
            $bundle = (new RecoveryOwnership)->locked($claim);
            if ($bundle === null) {
                throw new InvalidArgumentException('obsolete_work_revision');
            }
            $algorithm = RecoveryAlgorithm::tryFrom($bundle->profile ?? '') ?? throw new InvalidArgumentException('invalid_construction_profile');
            $saved = json_decode($bundle->construction_targets ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            $combined = self::combine($algorithm, [...$saved, ...$targets]);
            $index = array_values(array_filter($combined, static fn (array $target): bool => $target['kind'] === 'index'))[0]['message_id'];
            $identity = new RecoveryIdentity;
            $indexDigest = $identity->digest(['construction-index', $index]);
            DB::table('obfuscation_recovery_index_owners')->upsert([
                'index_digest' => $indexDigest, 'owner_digest' => $indexDigest,
                'construction_targets' => '[]', 'created_at' => now(), 'updated_at' => now(),
            ], ['index_digest'], ['index_digest']);
            $canonical = DB::table('obfuscation_recovery_index_owners')->where('index_digest', $indexDigest)->lockForUpdate()->first();
            $combined = self::combine($algorithm, [...json_decode($canonical->construction_targets, true, flags: JSON_THROW_ON_ERROR), ...$combined]);
            app(RecoveryBudgetOwners::class)->merge([$bundle->owner_digest, $canonical->owner_digest]);
            DB::table('obfuscation_recovery_index_owners')->where('index_digest', $indexDigest)->update([
                'construction_targets' => json_encode($combined, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->update([
                'construction_targets' => json_encode($combined, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                'inactive_since' => null, 'detail_retired_at' => null,
            ]);
            foreach ($combined as $target) {
                (new RecoveryReferences)->retain('bundle', (string) $bundle->id, 'evidence', hash('sha256', $target['message_id']));
            }
        }, 1);
    }

    /** @param list<array{kind:string,file_id:?string,message_id:string}> $targets
     * @return list<array{kind:string,file_id:?string,message_id:string}>
     */
    public static function combine(RecoveryAlgorithm $algorithm, array $targets): array
    {
        $byId = [];
        foreach ($targets as $target) {
            self::validate($target, $algorithm);
            $target = ['kind' => $target['kind'], 'file_id' => $target['file_id'], 'message_id' => $target['message_id']];
            $id = $target['message_id'];
            if (isset($byId[$id]) && $byId[$id] !== $target) {
                throw new InvalidArgumentException('conflicting_target_association');
            }
            $byId[$id] = $target;
            if (count($byId) > 97) {
                throw new InvalidArgumentException('bundle_target_limit');
            }
        }
        $indexes = $anchors = $terminals = 0;
        $files = [];
        foreach ($byId as $target) {
            if ($target['kind'] === 'index') {
                $indexes++;

                continue;
            }
            $file = $target['file_id'];
            $files[$file] ??= ['anchor' => 0, 'terminal' => 0];
            $files[$file][$target['kind']]++;
            $anchors += $target['kind'] === 'anchor' ? 1 : 0;
            $terminals += $target['kind'] === 'terminal' ? 1 : 0;
            if ($files[$file]['anchor'] > 8) {
                throw new InvalidArgumentException('file_anchor_target_limit');
            }
            if ($files[$file]['terminal'] > 1) {
                throw new InvalidArgumentException('file_terminal_target_limit');
            }
        }
        if ($indexes !== 1) {
            throw new InvalidArgumentException('index_target_limit');
        }
        if ($anchors > 64 || $terminals > 32 || count($files) > 32) {
            throw new InvalidArgumentException('bundle_target_limit');
        }
        ksort($byId, SORT_STRING);

        return array_values($byId);
    }

    /** @return array{kind:string,file_id:?string,message_id:string}|null */
    public static function find(object $bundle, string $messageId): ?array
    {
        foreach (json_decode($bundle->construction_targets ?? '[]', true, flags: JSON_THROW_ON_ERROR) as $target) {
            if ($target['message_id'] === $messageId) {
                return $target;
            }
        }

        return null;
    }

    /** @return array{decoded:int,prefix:bool,reservation:int,close:int,declaration:bool} */
    public static function allowance(string $kind, RecoveryAlgorithm $algorithm): array
    {
        if ($kind === 'index') {
            return ['decoded' => 1048576, 'prefix' => false, 'reservation' => 2097152, 'close' => 65536, 'declaration' => false];
        }
        if (! in_array($kind, ['anchor', 'terminal'], true) || ($kind === 'terminal' && $algorithm !== RecoveryAlgorithm::Rar)) {
            throw new InvalidArgumentException('unsupported_construction_target');
        }

        return ['decoded' => 16384, 'prefix' => true,
            'reservation' => $algorithm === RecoveryAlgorithm::Media ? 131072 : 196608,
            'close' => $algorithm === RecoveryAlgorithm::Media ? 32768 : 65536, 'declaration' => $kind === 'terminal'];
    }

    /** @param array{kind:string,file_id:?string,message_id:string} $target */
    private static function validate(array $target, RecoveryAlgorithm $algorithm): void
    {
        if (count($target) !== 3 || ! isset($target['kind'], $target['message_id']) || ! array_key_exists('file_id', $target)
            || (new RecoveryIdentity)->messageId($target['message_id']) !== $target['message_id']) {
            throw new InvalidArgumentException('invalid_construction_target');
        }
        self::allowance($target['kind'], $algorithm);
        if (($target['kind'] === 'index' && $target['file_id'] !== null)
            || ($target['kind'] !== 'index' && preg_match('/^[a-f0-9]{32}$/D', $target['file_id'] ?? '') !== 1)) {
            throw new InvalidArgumentException('invalid_target_file_id');
        }
    }
}
