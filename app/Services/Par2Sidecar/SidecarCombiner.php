<?php

declare(strict_types=1);

namespace App\Services\Par2Sidecar;

use App\Facades\Search;
use App\Models\Release;
use App\Models\ReleaseFile;
use App\Services\NameFixing\NameFixingService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\ReleaseRepair\RecoveryLease;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** The journal owns the irreversible handoff, including retries after either release is renamed. */
class SidecarCombiner
{
    public function __construct(private readonly NzbService $nzb, private readonly ReleaseManagementService $releases,
        private readonly ReleaseImageService $images) {}

    /** @param list<array<string,mixed>> $descriptors */
    public function select(int $targetId, SidecarLinkDecision $decision, string $targetXml, string $sourceXml, array $descriptors, bool $absorb): ?int
    {
        $leases = $this->leases([$targetId, $decision->sourceId]);
        try {
            $id = DB::transaction(function () use ($targetId, $decision, $targetXml, $sourceXml, $descriptors, $absorb, $leases): ?int {
                $rows = $this->locked([$targetId, $decision->sourceId], $leases);
                $target = $rows[$targetId];
                $source = $rows[$decision->sourceId];
                $this->assertXml($target, hash('sha256', $targetXml));
                $this->assertXml($source, hash('sha256', $sourceXml));
                if ((int) $target->isrenamed !== 0 || DB::table('par2_sidecar_operations')->where('target_id', $targetId)->exists()) {
                    return null;
                }
                if (DB::table('payload_prefix_hashes')->where('releases_id', $targetId)->where('state', 'pending')
                    ->whereNull('operation_id')->where('captured_at', '>', now()->subHours(72))->count() === 0) {
                    return null;
                }
                $accounting = ['target' => $this->accounting($target), 'source' => $this->accounting($source),
                    'add_files' => (bool) config('nntmux_settings.add_par2')];
                $id = DB::table('par2_sidecar_operations')->insertGetId([
                    'target_id' => $targetId, 'source_id' => $source->id, 'target_guid' => $target->guid, 'source_guid' => $source->guid,
                    'leftguid' => substr($target->guid, 0, 1), 'phase' => 'selected', 'reason' => $absorb ? $decision->reason : 'absorb_disabled',
                    'absorb' => $absorb && $decision->combine, 'filename' => $decision->filename,
                    'target_fingerprint' => hash('sha256', $targetXml), 'source_fingerprint' => hash('sha256', $sourceXml),
                    'target_xml' => $targetXml, 'source_xml' => $sourceXml,
                    'accounting' => json_encode($accounting, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                    'descriptors' => json_encode($descriptors, JSON_THROW_ON_ERROR),
                    'hashes' => json_encode(DB::table('par_hashes')->where('releases_id', $source->id)->pluck('hash')->all(), JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('payload_prefix_hashes')->where('releases_id', $targetId)->whereNull('operation_id')
                    ->update(['operation_id' => $id, 'state' => 'selected', 'evaluated_at' => now()]);

                return (int) $id;
            });
        } finally {
            $this->releaseLeases($leases);
        }
        $this->checkpoint('selected');

        return $id;
    }

    public function resume(int $id, bool $show = false): void
    {
        $leases = [];
        try {
            $operation = DB::table('par2_sidecar_operations')->where('id', $id)->first();
            if ($operation === null || $operation->phase === 'done') {
                return;
            }
            $ids = [(int) $operation->target_id];
            if ($operation->phase !== 'delete_pending' || Release::query()->whereKey($operation->source_id)->exists()) {
                $ids[] = (int) $operation->source_id;
            }
            $leases = $this->leases($ids, $id);
            if ($operation->phase === 'selected') {
                $operation = $this->mutate($id, $leases, function (object $operation, array $rows) use ($show): void {
                    $target = $rows[$operation->target_id];
                    $this->assertOriginal($operation, $rows);
                    if ((int) $target->isrenamed !== 0) {
                        throw new RuntimeException('target_name_changed');
                    }
                    $target->textstring = $operation->filename;
                    $target->releases_id = $target->id;
                    $matched = (new NameFixingService)->checkName($target, true, 'PAR2, ', true, $show);
                    if (! $matched) {
                        $this->progress($operation->id, 'done', ['reason' => 'name_declined']);

                        return;
                    }
                    $target->refresh();
                    $this->progress($operation->id, 'named', ['named_state' => json_encode([
                        'searchname' => $target->searchname, 'isrenamed' => (int) $target->isrenamed,
                        'is_trusted_name' => (int) $target->is_trusted_name,
                    ], JSON_THROW_ON_ERROR)]);
                });
                $this->checkpoint('named');
            }
            if ($operation->phase === 'done') {
                return;
            }
            if ($operation->phase === 'named') {
                if (! $operation->absorb) {
                    $this->progress($id, 'done');

                    return;
                }
                $operation = $this->mutate($id, $leases, function (object $operation, array $rows): void {
                    $this->assertOriginal($operation, $rows);
                    $named = json_decode($operation->named_state, true, flags: JSON_THROW_ON_ERROR);
                    foreach ($named as $column => $value) {
                        if ((string) $rows[$operation->target_id]->{$column} !== (string) $value) {
                            throw new RuntimeException('target_name_changed');
                        }
                    }
                    $xml = (new SidecarNzb)->append($operation->target_xml, $operation->source_xml);
                    $this->progress($operation->id, 'nzb_writing', ['combined_fingerprint' => hash('sha256', $xml)]);
                });
            }
            if (in_array($operation->phase, ['nzb_writing', 'nzb_written'], true)) {
                $operation = $this->mutate($id, $leases, function (object $operation, array $rows) use ($leases): void {
                    $target = $rows[$operation->target_id];
                    $this->assertXml($rows[$operation->source_id], $operation->source_fingerprint);
                    $accounting = json_decode($operation->accounting, true, flags: JSON_THROW_ON_ERROR);
                    if ($this->accounting($target) !== $accounting['target']
                        || $this->accounting($rows[$operation->source_id]) !== $accounting['source']) {
                        throw new RuntimeException('accounting_changed');
                    }
                    $actual = $this->read($target->guid);
                    $fingerprint = hash('sha256', $actual);
                    if ($fingerprint === $operation->target_fingerprint) {
                        $xml = (new SidecarNzb)->append($operation->target_xml, $operation->source_xml);
                        $result = $this->nzb->replaceNzbContentsWithLease($target->guid, $xml, $leases[$target->id], $operation->target_fingerprint);
                        if (! $result->success) {
                            throw new RuntimeException('nzb_write_failed');
                        }
                        $this->checkpoint('nzb_written');
                    } elseif ($fingerprint !== $operation->combined_fingerprint) {
                        throw new RuntimeException('incompatible_nzb_membership');
                    }
                    $this->assertXml($target, $operation->combined_fingerprint);
                    $this->account($operation, $target, $accounting);
                    $this->progress($operation->id, 'delete_pending');
                });
                $this->checkpoint('delete_pending');
            }
            if ($operation->phase === 'delete_pending') {
                $this->mutate($id, $leases, function (object $operation, array $rows): void {
                    $target = $rows[$operation->target_id];
                    $this->assertXml($target, $operation->combined_fingerprint);
                    $accounting = json_decode($operation->accounting, true, flags: JSON_THROW_ON_ERROR);
                    if ($this->accounting($target) !== $this->combinedAccounting($accounting)) {
                        throw new RuntimeException('committed_target_changed');
                    }
                }, allowMissingSource: true);
                Search::updateRelease((int) $operation->target_id);
                if (isset($leases[$operation->source_id])) {
                    $leases[$operation->source_id]->release();
                    unset($leases[$operation->source_id]);
                }
                $this->checkpoint('source_lease_released');
                if (Release::query()->whereKey($operation->source_id)->exists()) {
                    $deleted = $this->releases->deleteSingleIfUnclaimed(['i' => (int) $operation->source_id, 'g' => $operation->source_guid],
                        $this->nzb, $this->images, 'par2_sidecar_combined', function (Release $source) use ($operation): array {
                            $accounting = json_decode($operation->accounting, true, flags: JSON_THROW_ON_ERROR);
                            $valid = (new SidecarEligibility)->allows((int) $source->id)
                                && $this->accounting($source) === $accounting['source']
                                && hash('sha256', $this->read($source->guid)) === $operation->source_fingerprint;

                            return ['eligible' => $valid, 'reason' => 'sidecar_identity_changed'];
                        });
                    if (! $deleted) {
                        throw new RuntimeException('protected_deletion_deferred');
                    }
                }
                $this->checkpoint('source_deleted');
                $this->progress($id, 'done', ['reason' => 'combined']);
            }
        } catch (Throwable $error) {
            DB::table('par2_sidecar_operations')->where('id', $id)->where('phase', '<>', 'done')
                ->update(['reason' => substr($error->getMessage(), 0, 100), 'retry_at' => now()->addMinute(), 'updated_at' => now()]);
        } finally {
            $this->releaseLeases($leases);
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, RecoveryLease>
     */
    private function leases(array $ids, ?int $operationId = null): array
    {
        sort($ids, SORT_NUMERIC);
        $leases = [];
        try {
            foreach (array_unique($ids) as $id) {
                $release = Release::query()->find($id);
                if ($release === null || ! (new SidecarEligibility)->allows($id)) {
                    throw new RuntimeException('release_unavailable');
                }
                $lease = RecoveryLease::acquire($release, $operationId);
                if ($lease === null) {
                    throw new RuntimeException('claim_contention');
                }
                $leases[$id] = $lease;
            }
        } catch (Throwable $error) {
            $this->releaseLeases($leases);
            throw $error;
        }

        return $leases;
    }

    /** @param array<int, RecoveryLease> $leases */
    private function releaseLeases(array $leases): void
    {
        foreach (array_reverse($leases, true) as $lease) {
            $lease->release();
        }
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int,RecoveryLease>  $leases
     * @return array<int,Release>
     */
    private function locked(array $ids, array $leases): array
    {
        $rows = Release::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id')->all();
        foreach ($ids as $id) {
            if (! isset($rows[$id], $leases[$id]) || ! $leases[$id]->owns($id) || ! (new SidecarEligibility)->allows($id)) {
                throw new RuntimeException('ownership_lost');
            }
        }

        return $rows;
    }

    /**
     * @param  array<int,RecoveryLease>  $leases
     * @param  callable(object,array<int,Release>):void  $callback
     */
    private function mutate(int $id, array $leases, callable $callback, bool $allowMissingSource = false): object
    {
        return DB::transaction(function () use ($id, $leases, $callback, $allowMissingSource): object {
            $rows = $this->locked(array_keys($leases), $leases);
            $operation = DB::table('par2_sidecar_operations')->where('id', $id)->lockForUpdate()->first();
            if ($operation === null || $rows[$operation->target_id]->guid !== $operation->target_guid
                || (! $allowMissingSource && ($rows[$operation->source_id]->guid ?? null) !== $operation->source_guid)) {
                throw new RuntimeException('release_identity_changed');
            }
            $callback($operation, $rows);

            return DB::table('par2_sidecar_operations')->where('id', $id)->first();
        });
    }

    /** @param array<int,Release> $rows */
    private function assertOriginal(object $operation, array $rows): void
    {
        $this->assertXml($rows[$operation->target_id], $operation->target_fingerprint);
        $this->assertXml($rows[$operation->source_id], $operation->source_fingerprint);
        if ($operation->absorb && ((float) $rows[$operation->target_id]->completion !== 100.0 || (float) $rows[$operation->source_id]->completion !== 100.0)) {
            throw new RuntimeException('completion_changed');
        }
    }

    private function assertXml(Release $release, string $fingerprint): void
    {
        if (! hash_equals($fingerprint, hash('sha256', $this->read($release->guid)))) {
            throw new RuntimeException('nzb_fingerprint_changed');
        }
    }

    private function read(string $guid): string
    {
        $xml = $this->nzb->readNzbContents($guid);
        if ($xml === false) {
            throw new RuntimeException('nzb_unavailable');
        }

        return $xml;
    }

    /** @return array{size:int,totalpart:int,firstarticle:int,lastarticle:int,declaredfiles:int,completion:float} */
    private function accounting(Release $release): array
    {
        return ['size' => (int) $release->size, 'totalpart' => (int) $release->totalpart,
            'firstarticle' => (int) $release->firstarticle, 'lastarticle' => (int) $release->lastarticle,
            'declaredfiles' => (int) $release->declaredfiles, 'completion' => (float) $release->completion];
    }

    /**
     * @param  array<string,mixed>  $inputs
     * @return array<string,int|float>
     */
    private function combinedAccounting(array $inputs): array
    {
        $target = $inputs['target'];
        $source = $inputs['source'];

        return ['size' => $target['size'] + $source['size'], 'totalpart' => $target['totalpart'] + $source['totalpart'],
            'firstarticle' => min($target['firstarticle'], $source['firstarticle']), 'lastarticle' => max($target['lastarticle'], $source['lastarticle']),
            'declaredfiles' => $target['declaredfiles'] + $source['declaredfiles'], 'completion' => (float) $target['completion']];
    }

    /** @param array<string,mixed> $inputs */
    private function account(object $operation, Release $target, array $inputs): void
    {
        $descriptors = json_decode($operation->descriptors, true, flags: JSON_THROW_ON_ERROR);
        foreach (json_decode($operation->hashes, true, flags: JSON_THROW_ON_ERROR) as $hash) {
            DB::table('par_hashes')->insertOrIgnore(['releases_id' => $target->id, 'hash' => $hash]);
        }
        (new SidecarEvidence)->storeDescriptors((int) $target->id, $descriptors, (int) $operation->source_id, $operation->combined_fingerprint);
        if ($inputs['add_files']) {
            foreach ($descriptors as $file) {
                if (strlen($file['filename']) <= 255) {
                    ReleaseFile::addReleaseFiles((int) $target->id, $file['filename'], (string) $file['raw_size'], now(), 0, $file['hash16k']);
                }
            }
        }
        $values = $this->combinedAccounting($inputs);
        unset($values['completion']);
        $values['rarinnerfilecount'] = DB::table('release_files')->where('releases_id', $target->id)->count();
        Release::query()->whereKey($target->id)->update($values);
        DB::table('par2_sidecar_inventories')->where('releases_id', $target->id)->update(['pure' => false,
            'complete' => false, 'reason' => 'combined_payload', 'fingerprint' => $operation->combined_fingerprint]);
    }

    /** @param array<string,mixed> $values */
    private function progress(int $id, string $phase, array $values = []): void
    {
        DB::table('par2_sidecar_operations')->where('id', $id)->update($values + ['phase' => $phase, 'retry_at' => null, 'updated_at' => now()]);
    }

    /** Failure-injection seam for the crash boundaries in the operation's contract. */
    protected function checkpoint(string $phase): void {}
}
