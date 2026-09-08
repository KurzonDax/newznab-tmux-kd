<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Models\Release;
use App\Services\NameFixing\NameFixingService;
use Illuminate\Support\Facades\DB;

final class RecoveryNaming
{
    public function apply(object $publication, RecoveryInventory $inventory, NameFixingService $naming, bool $enabled, bool $show): bool
    {
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        if ($plan->algorithm === RecoveryAlgorithm::Rar && DB::table('obfuscation_recovery_publications')
            ->where('id', $publication->id)->where('multi_media_inventory', true)->exists()) {
            return false;
        }
        $release = Release::query()->where('id', $publication->releases_id)->first();
        if ($release === null || $publication->state !== 'published') {
            return false;
        }
        $names = [];
        foreach ($inventory->files as $file) {
            $names[bin2hex($file->id)] = $file->filename;
        }
        $scope = $plan->algorithm === RecoveryAlgorithm::Rar ? RecoveryNameScope::ArchiveSet
            : ($plan->multiMediaInventory() ? RecoveryNameScope::DescriptiveBundle : RecoveryNameScope::SingleFile);
        $evidence = new RecoveryNameEvidence((int) $publication->id, $plan->manifestDigest, $scope, array_keys($names));
        $decision = (new RecoveryInventoryIdentity)->media($names);
        foreach ($decision['files'] as $file) {
            DB::table('obfuscation_recovery_files')->where('bundle_id', $plan->bundleId)->where('revision', $plan->revision)
                ->where('file_id', $file['file_id'])->update(['identity_evidence' => json_encode([
                    'scope' => 'protected_file', 'name' => base64_encode($file['name']), 'title' => $file['title'],
                    'season' => $file['season'], 'episode' => $file['episode'],
                ], JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        }
        if (in_array($publication->identity_outcome, ['identified', 'descriptive_bundle'], true)) {
            return true;
        }
        $identified = false;
        $outcome = $enabled ? 'identity_unresolved' : 'par2_naming_disabled';
        if ($enabled) {
            if ($scope === RecoveryNameScope::DescriptiveBundle) {
                $outcome = 'identity_unresolved';
                if ($decision['candidate'] !== null) {
                    $identified = $naming->getUpdateService()->renameRecoveredInventory($release, $decision['candidate'], $evidence);
                    $outcome = $identified ? 'descriptive_bundle' : 'identity_unresolved';
                }
            } else {
                $candidate = $scope === RecoveryNameScope::ArchiveSet
                    ? (new RecoveryInventoryIdentity)->archive(array_values($names)) : reset($names);
                if (is_string($candidate) && $candidate !== '') {
                    $release->textstring = $candidate;
                    $release->releases_id = $release->id;
                    $identified = $naming->checkName($release, true, 'PAR2, ', true, $show, recoveryEvidence: $evidence);
                }
                $outcome = $identified ? 'identified' : 'identity_unresolved';
            }
        }
        DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update([
            'identity_outcome' => $outcome,
            'identity_scope' => $identified ? $scope->value : ($plan->algorithm === RecoveryAlgorithm::Rar ? 'unknown_archive' : 'protected_file'),
            'updated_at' => now(),
        ]);

        return $identified;
    }
}
