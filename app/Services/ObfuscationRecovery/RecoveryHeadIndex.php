<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecoveryHeadIndex
{
    public function __construct(private readonly RecoveryManifest $manifest) {}

    /** @return array<string,list<array<string,mixed>>> */
    public function forPublication(object $publication, ?RecoveryInspection $inspection = null): array
    {
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        if ($publication->head_membership !== null) {
            return $this->validate(json_decode($publication->head_membership, true, flags: JSON_THROW_ON_ERROR), $plan);
        }
        $records = [];
        foreach ($this->manifest->read(new RecoveryArtifact($plan->manifestDigest, $plan->manifestBytes)) as $record) {
            if ($record['ordinal'] <= 16) {
                $records[$record['file']][] = $record;
            }
        }
        $records = $this->validate($records, $plan);
        if ($inspection !== null) {
            $inspection->assertPublication($publication);
            $inspection->mutate(fn (): int => DB::table('obfuscation_recovery_publications')->where('id', $publication->id)
                ->where('manifest_digest', $plan->manifestDigest)->whereNull('head_membership')
                ->update(['head_membership' => json_encode($records, JSON_THROW_ON_ERROR), 'updated_at' => now()]));
        }

        return $records;
    }

    /** @param array<string,list<array<string,mixed>>> $records
     * @return array<string,list<array<string,mixed>>>
     */
    private function validate(array $records, RecoveryPlan $plan): array
    {
        if (count($records) !== count($plan->files)) {
            throw new InvalidArgumentException('invalid_head_membership');
        }
        foreach ($plan->files as $file) {
            $entries = $records[$file->identity] ?? [];
            if (count($entries) !== min(16, $file->totalParts)) {
                throw new InvalidArgumentException('incomplete_head_membership');
            }
            foreach ($entries as $i => $record) {
                if ($record['file'] !== $file->identity || $record['role'] !== $file->role->value || $record['ordinal'] !== $i + 1
                    || $record['group'] !== $plan->group || (new RecoveryIdentity)->messageId($record['message_id']) !== $record['message_id']) {
                    throw new InvalidArgumentException('invalid_head_membership');
                }
            }
        }

        return $records;
    }
}
