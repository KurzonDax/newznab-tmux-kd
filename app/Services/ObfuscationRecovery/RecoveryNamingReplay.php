<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Models\Release;
use App\Models\Settings;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\NameFixing\NameFixingService;
use App\Services\Par2Processor;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Support\Facades\DB;

final class RecoveryNamingReplay
{
    public function step(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        $query = Release::query()->join('obfuscation_recovery_publications as recovery', 'recovery.releases_id', '=', 'releases.id')
            ->whereColumn('recovery.guid', 'releases.guid')->where('recovery.state', 'published')
            ->where('recovery.initialization_state', 'complete')->where('recovery.identity_outcome', 'par2_naming_disabled')
            ->whereNull('recovery.deleted_at')->where('releases.isrenamed', 0);
        ReleaseClaimant::applyClaimWindow($query, 'releases');
        $publication = $query->orderBy('recovery.updated_at')->orderBy('recovery.id')->toBase()->first(['recovery.*']);
        if ($publication === null) {
            return null;
        }
        $inspection = RecoveryInspection::acquire($publication);
        if ($inspection === null) {
            return 'busy';
        }
        try {
            return $inspection->mutate(function () use ($publication, $inspection): string {
                $current = DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->first();
                if (! $this->enabled() || $current->initialization_state !== 'complete' || $current->identity_outcome !== 'par2_naming_disabled') {
                    return 'not_pending';
                }
                $release = Release::query()->whereKey($publication->releases_id)->first();
                if (RecoveryNaming::hasExistingName($release, $publication->identity)) {
                    return $this->settle((int) $publication->id, 'existing_name_preserved');
                }
                $index = app(RecoveryEvidence::class)->get($publication->index_message_id);
                if (! $this->enabled()) {
                    return 'not_pending';
                }
                if ($index === null) {
                    return $this->settle((int) $publication->id, 'cached_index_unavailable');
                }
                $processor = new Par2Processor(app(NameFixingService::class), new Par2Info, (bool) config('nntmux_settings.add_par2'));
                $named = $processor->parseData($index->data, (int) $release->id, allowNaming: fn (): bool => $this->enabled(lockForUpdate: true), inspection: $inspection);
                if (! $this->enabled()) {
                    return 'not_pending';
                }
                $this->settle((int) $publication->id, 'identity_unresolved');

                return $named ? 'renamed' : 'identity_unresolved';
            });
        } catch (RecoveryNamingDisabled) {
            return 'not_pending';
        } catch (\Throwable) {
            try {
                return $inspection->mutate(fn (): string => $this->settle((int) $publication->id, 'cached_identification_failed'));
            } catch (\RuntimeException) {
                return 'inspection_claim_lost';
            }
        } finally {
            $inspection->release();
        }
    }

    /** @phpstan-impure */
    private function enabled(bool $lockForUpdate = false): bool
    {
        return (int) Settings::settingValue('fix_names', $lockForUpdate) === 1 && Settings::isPar2NamingEnabled($lockForUpdate);
    }

    private function settle(int $publicationId, string $outcome): string
    {
        DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->where('identity_outcome', 'par2_naming_disabled')
            ->update(['identity_outcome' => $outcome, 'updated_at' => now()]);

        return $outcome;
    }
}
