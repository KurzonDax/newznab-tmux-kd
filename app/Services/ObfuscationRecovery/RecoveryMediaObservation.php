<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;
use Mhor\MediaInfo\Container\MediaInfoContainer;

final class RecoveryMediaObservation
{
    public function record(object $publication, string $fileId, MediaInfoContainer $container, int $bytes, RecoveryInspection $inspection): bool
    {
        if ($publication->state !== 'published' || $publication->deleted_at !== null || $bytes < 1 || $bytes > 4194304) {
            return false;
        }
        $inspection->assertPublication($publication);
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        $fields = [];
        $general = $container->getGeneral();
        foreach (['movie_name', 'file_name', 'unique_id', 'format', 'duration', 'overall_bit_rate'] as $key) {
            $value = $general?->get($key);
            if (is_scalar($value)) {
                $fields[$key] = mb_substr((string) $value, 0, 1024);
            }
        }

        return $inspection->mutate(function () use ($plan, $fileId, $bytes, $fields): bool {
            $query = DB::table('obfuscation_recovery_files')->where('bundle_id', $plan->bundleId)->where('revision', $plan->revision)
                ->where('file_id', $fileId)->where('role', RecoveryFileRole::Media->value);
            if (! $query->exists()) {
                return false;
            }
            $query->update(['media_evidence' => json_encode([
                'scope' => 'protected_file', 'file_id' => $fileId, 'completeness' => 'bounded_head', 'observed_bytes' => $bytes,
                'fields' => $fields,
            ], JSON_THROW_ON_ERROR), 'enrichment_outcome' => 'media_evidence_available', 'enrichment_reason' => null, 'updated_at' => now()]);

            return true;
        });
    }
}
