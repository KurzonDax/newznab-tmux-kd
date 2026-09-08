<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Models\Release;
use App\Services\NameFixing\NameFixingService;
use App\Services\Par2Processor;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Support\Facades\DB;

final class RecoveryBootstrap
{
    public function run(int $publicationId, ?RecoveryInspection $inspection = null): string
    {
        $publication = DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->first();
        if ($publication === null || $publication->state !== 'published' || $publication->initialization_state !== 'pending') {
            return 'not_pending';
        }
        $release = Release::query()->where('id', $publication->releases_id)->where('guid', $publication->guid)->first();
        if ($release === null) {
            return 'release_missing';
        }
        $owned = $inspection === null;
        $inspection ??= RecoveryInspection::acquire($publication);
        if ($inspection === null) {
            return 'busy';
        }
        try {
            $inspection->assertPublication($publication);
            app(RecoveryHeadIndex::class)->forPublication($publication, $inspection);
            $index = app(RecoveryEvidence::class)->get($publication->index_message_id);
            if ($index === null) {
                $inspection->mutate(fn () => $this->settle($publicationId, 'failed', 'cached_index_unavailable'));

                return 'cached_index_unavailable';
            }
            $processor = new Par2Processor(app(NameFixingService::class), new Par2Info, (bool) config('nntmux_settings.add_par2'));
            $processor->parseData($index->data, (int) $release->id, inspection: $inspection);
            $inspection->mutate(fn () => $this->settle($publicationId, 'complete', null));

            return 'complete';
        } catch (\Throwable) {
            try {
                $inspection->mutate(fn () => $this->settle($publicationId, 'failed', 'cached_identification_failed'));
            } catch (\RuntimeException) {
                return 'inspection_claim_lost';
            }

            return 'cached_identification_failed';
        } finally {
            if ($owned) {
                $inspection->release();
            }
        }
    }

    private function settle(int $publicationId, string $state, ?string $reason): void
    {
        DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->where('state', 'published')
            ->where('initialization_state', 'pending')->update([
                'initialization_state' => $state, 'reason' => $reason, 'updated_at' => now(),
            ]);
    }
}
