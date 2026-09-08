<?php

declare(strict_types=1);

namespace App\Services\Par2Sidecar;

use App\Models\Release;
use App\Models\Settings;
use App\Services\Nzb\NzbService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/** Bounded, indexed admission independent of all legacy rename flags. */
final class SidecarWork
{
    public function __construct(private readonly NzbService $nzb, private readonly SidecarCombiner $combiner) {}

    public static function pendingCount(): int
    {
        if (! Schema::hasTable('payload_prefix_hashes')) {
            return 0;
        }

        return self::due(DB::table('payload_prefix_hashes')->where('state', 'pending'))->count()
            + self::due(DB::table('par2_sidecar_operations')->where('phase', '<>', 'done'))->count();
    }

    public function run(?string $leftGuid = null, int $limit = 25, bool $show = false): void
    {
        if (! Schema::hasTable('payload_prefix_hashes')) {
            return;
        }
        $limit = max(1, min(100, $limit));
        $expired = DB::table('payload_prefix_hashes')->where('state', 'pending')->whereNull('operation_id')
            ->where('captured_at', '<=', now()->subHours(72))->orderBy('captured_at')->limit($limit)->pluck('id');
        DB::table('payload_prefix_hashes')->whereIn('id', $expired)->update(['state' => 'expired', 'reason' => 'capture_window_expired', 'evaluated_at' => now()]);
        $operations = self::due(DB::table('par2_sidecar_operations')->where('phase', '<>', 'done'))
            ->when($leftGuid !== null, fn (Builder $q) => $q->where('leftguid', $leftGuid))
            ->orderBy('retry_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($operations as $id) {
            $this->combiner->resume((int) $id, $show);
        }
        $pending = self::due(DB::table('payload_prefix_hashes')->where('state', 'pending')->whereNull('operation_id'))
            ->when($leftGuid !== null, fn (Builder $q) => $q->where('leftguid', $leftGuid))
            ->where('captured_at', '>', now()->subHours(72))->orderBy('retry_at')->orderBy('id')->limit($limit)->get();
        foreach ($pending as $row) {
            try {
                $id = $this->select((int) $row->releases_id);
                if ($id !== null) {
                    $this->combiner->resume($id, $show);
                }
            } catch (Throwable $error) {
                $this->evaluate((int) $row->releases_id, 'pending', 'retry:'.substr($error->getMessage(), 0, 80));
            }
        }
    }

    private static function due(Builder $query): Builder
    {
        return $query->where(static fn (Builder $due) => $due->whereNull('retry_at')->orWhere('retry_at', '<=', now()));
    }

    private function select(int $targetId): ?int
    {
        $target = Release::query()->find($targetId);
        if ($target === null || (int) $target->isrenamed !== 0) {
            $this->evaluate($targetId, 'declined', 'already_named');

            return null;
        }
        $payloads = DB::table('payload_prefix_hashes')->where('releases_id', $targetId)->where('state', 'pending')->whereNull('operation_id')
            ->where('captured_at', '>', now()->subHours(72))->limit(1025)->get()->map($this->prefix(...))->all();
        $descriptors = DB::table('par2_file_descriptors')->whereIn('hash16k', array_column($payloads, 'prefix_hash'))
            ->where('releases_id', '<>', $targetId)->orderBy('id')->limit(33)->get()->map(static fn (object $row): array => (array) $row)->all();
        $eligibility = $inventories = $xmls = [];
        foreach (array_unique([$targetId, ...array_column($descriptors, 'releases_id')]) as $releaseId) {
            $release = Release::query()->find($releaseId);
            if ($release === null) {
                continue;
            }
            $xml = $this->nzb->readNzbContents($release->guid);
            if ($xml === false) {
                throw new RuntimeException('nzb_unavailable');
            }
            $xmls[$releaseId] = $xml;
            $eligibility[$releaseId] = ['eligible' => (new SidecarEligibility)->allows((int) $releaseId),
                'completion' => (float) $release->completion, 'postdate' => (string) $release->postdate,
                'fingerprint' => hash('sha256', $xml), 'nzb_complete' => (new SidecarNzb)->complete($xml)];
            $inventory = DB::table('par2_sidecar_inventories')->where('releases_id', $releaseId)->first();
            if ($inventory !== null) {
                $members = DB::table('par2_file_descriptors')->where('releases_id', $releaseId)->where('fingerprint', hash('sha256', $xml))->limit(1025)->get()
                    ->map(static fn (object $row): array => (array) $row)->all();
                $owners = [];
                foreach (array_chunk($members, 128) as $page) {
                    $matching = DB::table('payload_prefix_hashes')->where(static function (Builder $pairs) use ($page): void {
                        foreach ($page as $member) {
                            $pairs->orWhere(static fn (Builder $pair) => $pair->where('prefix_hash', $member['hash16k'])
                                ->where('raw_size', $member['raw_size']));
                        }
                    });
                    foreach ($matching->distinct()->limit(2)->pluck('releases_id') as $owner) {
                        $owners[(int) $owner] = (int) $owner;
                    }
                    if (count($owners) > 1) {
                        break;
                    }
                }
                sort($owners);
                $inventories[$releaseId] = ['pure' => (bool) $inventory->pure, 'complete' => (bool) $inventory->complete && count($members) <= 1024,
                    'unambiguous' => ! $inventory->naming_ambiguous && array_all(json_decode($inventory->files, true, flags: JSON_THROW_ON_ERROR),
                        static fn (array $file): bool => ! in_array($file['inventory']['reason'] ?? null,
                            ['descriptor_overflow', 'conflicting_descriptor', 'invalid_descriptor', 'invalid_main_inventory', 'invalid_packet'], true)),
                    'fingerprint' => $inventory->fingerprint, 'descriptors' => $members, 'owners' => $owners];
            }
        }
        $decision = (new SidecarLinkResolver)->resolve($payloads, $descriptors, $inventories, $eligibility);
        if ($decision->filename === null || $decision->sourceId === null) {
            $this->evaluate($targetId, $decision->reason === 'no_match' ? 'pending' : 'declined', $decision->reason);

            return null;
        }

        return $this->combiner->select($targetId, $decision, $xmls[$targetId], $xmls[$decision->sourceId],
            $inventories[$decision->sourceId]['descriptors'] ?? $descriptors,
            in_array(Settings::settingValue('par2_sidecar_absorb'), [null, '', '1', 1], true));
    }

    /** @return array<string, mixed> */
    private function prefix(object $row): array
    {
        $values = (array) $row;
        foreach (['releases_id', 'nzb_file_index', 'raw_size', 'decoded_length', 'segment_number', 'segment_offset', 'observed_segments', 'declared_segments'] as $key) {
            $values[$key] = (int) $values[$key];
        }
        $values['segment_numbers'] = json_decode($values['segment_numbers'], true, flags: JSON_THROW_ON_ERROR);

        return $values;
    }

    private function evaluate(int $releaseId, string $state, string $reason): void
    {
        DB::table('payload_prefix_hashes')->where('releases_id', $releaseId)->whereNull('operation_id')->where('state', 'pending')
            ->update(['state' => $state, 'reason' => $reason, 'evaluated_at' => now(), 'retry_at' => now()->addMinutes(5)]);
    }
}
