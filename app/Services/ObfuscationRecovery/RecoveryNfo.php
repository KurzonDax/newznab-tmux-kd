<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Models\Release;
use App\Models\ReleaseNfo;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\NfoService;
use Illuminate\Support\Facades\DB;

final class RecoveryNfo
{
    public function __construct(private readonly RecoveryHeadIndex $index, private readonly RecoveryCachedReader $reader,
        private readonly RecoveryHeads $heads, private readonly ArchiveExtractionService $archives) {}

    public function read(object $publication, NfoService $nfo): string|false|RecoveryEvidencePending
    {
        return $this->inspect($publication, $nfo, false);
    }

    public function process(object $publication, NfoService $nfo): string|false|RecoveryEvidencePending
    {
        return $this->inspect($publication, $nfo, true);
    }

    private function inspect(object $publication, NfoService $nfo, bool $persist): string|false|RecoveryEvidencePending
    {
        if ($publication->state !== 'published' || $publication->deleted_at !== null) {
            return false;
        }
        $inspection = RecoveryInspection::acquire($publication);
        if ($inspection === null) {
            return new RecoveryEvidencePending(now()->addMinute()->toIso8601String());
        }
        try {
            $content = $this->readOwned($publication, $nfo, $inspection);
            if ($persist && is_string($content)) {
                $compressed = "\x1f\x8b\x08\x00".gzcompress($content);
                $inspection->mutate(function () use ($publication, $compressed): void {
                    ReleaseNfo::query()->insertOrIgnore(['releases_id' => $publication->releases_id, 'nfo' => $compressed]);
                    Release::query()->whereKey($publication->releases_id)->where('guid', $publication->guid)
                        ->update(['nfostatus' => NfoService::NFO_FOUND]);
                });
            }

            return $content;
        } finally {
            $inspection->release();
        }
    }

    private function readOwned(object $publication, NfoService $nfo, RecoveryInspection $inspection): string|false|RecoveryEvidencePending
    {
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        $outcome = 'no_associated_nfo';
        if ($plan->algorithm === RecoveryAlgorithm::Rar) {
            $records = $this->index->forPublication($publication, $inspection);
            foreach ($plan->files as $file) {
                if ($file->role !== RecoveryFileRole::RarVolume) {
                    continue;
                }
                $prefix = $this->reader->readRecords($records[$file->identity], $file);
                $content = $this->archives->recoveredStoredNfo($prefix->data);
                if ($content !== null && $nfo->isNFO($content, $publication->guid)) {
                    $inspection->mutate(fn () => $this->settle($publication, 'cached_nfo_available', false));

                    return $content;
                }
                foreach ($this->archives->listRecoveredPrefix($prefix->data)['files'] as $entry) {
                    if (is_string($entry['name'] ?? null) && $this->archives->isNfoFile($entry['name'])) {
                        $head = $this->heads->read((int) $publication->releases_id, $file->identity, min(2097152, strlen($prefix->data) + 1), $inspection);
                        $outcome = $head->pending() ? 'recovery_evidence_pending' : 'bounded_nfo_unavailable';
                        if ($head->pending()) {
                            break 2;
                        }
                    }
                }
            }
        }
        $inspection->mutate(fn () => $this->settle($publication, $outcome, $outcome !== 'recovery_evidence_pending'));

        return $outcome === 'recovery_evidence_pending' ? new RecoveryEvidencePending(now()->addMinute()->toIso8601String()) : false;
    }

    private function settle(object $publication, string $outcome, bool $terminal): void
    {
        DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->where('state', 'published')
            ->update(['nfo_outcome' => $outcome, 'updated_at' => now()]);
        if ($terminal) {
            Release::query()->where('id', $publication->releases_id)->where('guid', $publication->guid)
                ->where('nfostatus', '<>', NfoService::NFO_FOUND)->update(['nfostatus' => NfoService::NFO_NONFO]);
        }
    }
}
