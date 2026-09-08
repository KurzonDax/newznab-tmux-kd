<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Events\ReleaseNameFixed;
use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\Releases\ExecutableReleaseDiscardService;
use App\Services\Releases\ReleaseBrowseService;
use Illuminate\Support\Facades\DB;

final class RecoveryArchiveInspection
{
    public function __construct(private readonly ArchiveExtractionService $archives, private readonly ExecutableReleaseDiscardService $discard) {}

    public function inspect(object $publication, RecoveryFilePlan $file, RecoveryCachedPrefix $prefix, ?RecoveryInspection $inspection = null): string
    {
        if ($publication->state !== 'published' || $file->role !== RecoveryFileRole::RarVolume || $prefix->data === '') {
            return 'archive_head_unavailable';
        }
        $owned = $inspection === null;
        $inspection ??= RecoveryInspection::acquire($publication);
        if ($inspection === null) {
            return 'inspection_busy';
        }
        try {
            $inspection->assertPublication($publication);

            return $this->apply($publication, $file, $prefix, $inspection);
        } finally {
            if ($owned) {
                $inspection->release();
            }
        }
    }

    private function apply(object $publication, RecoveryFilePlan $file, RecoveryCachedPrefix $prefix, RecoveryInspection $inspection): string
    {
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        $listing = $this->archives->listRecoveredPrefix($prefix->data);
        $release = Release::query()->where('id', $publication->releases_id)->where('guid', $publication->guid)->first();
        if ($release === null) {
            return 'release_missing';
        }
        $executable = $this->discard->firstDiscardableFileName($listing['files'], (int) $release->categories_id);
        if ($executable !== null && $inspection->mutate(fn (): bool => $this->discard->discardById((int) $release->id, $executable))) {
            return 'discarded';
        }
        $observations = [];
        foreach (array_slice($listing['files'], 0, 256) as $entry) {
            $name = $entry['name'] ?? '';
            if (! is_string($name) || $name === '' || strlen($name) > 1024) {
                continue;
            }
            $observations[] = ['name' => base64_encode($name), 'declared_size' => (int) ($entry['size'] ?? 0),
                'crc32' => is_scalar($entry['crc32'] ?? null) ? substr((string) $entry['crc32'], 0, 8) : null];
        }

        return $inspection->mutate(function () use ($plan, $publication, $file, $prefix, $listing, $observations, $release): string {
            if (Release::query()->whereKey($release->id)->where('guid', $publication->guid)->lockForUpdate()->first() === null) {
                return 'release_missing';
            }
            $files = DB::table('obfuscation_recovery_files')->where('bundle_id', $plan->bundleId)->where('revision', $plan->revision);
            (clone $files)->where('file_id', $file->identity)->update(['contained_observations' => json_encode([
                'scope' => 'partial_volume_listing', 'volume_file_id' => $file->identity, 'complete' => false,
                'observed_bytes' => strlen($prefix->data), 'truncated_listing' => count($listing['files']) > 256, 'files' => $observations,
            ], JSON_THROW_ON_ERROR), 'enrichment_outcome' => $observations === [] ? 'archive_listing_unavailable' : 'partial_archive_listing', 'updated_at' => now()]);
            $mainNames = [];
            foreach ((clone $files)->whereNotNull('contained_observations')->pluck('contained_observations') as $json) {
                foreach (json_decode($json, true, flags: JSON_THROW_ON_ERROR)['files'] as $entry) {
                    $name = base64_decode($entry['name'], true);
                    if (is_string($name) && preg_match('/\\.(?:mkv|mp4|avi|mov|webm)$/iD', $name) === 1
                        && preg_match('/(?:^|[. _\\/\\\\-])sample(?:[. _\\/\\\\-]|$)/i', $name) !== 1) {
                        $mainNames[mb_strtolower($name)] = true;
                    }
                }
            }
            if (count($mainNames) > 1) {
                $alreadyScoped = (bool) DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->value('multi_media_inventory');
                DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->where('state', 'published')->update([
                    'multi_media_inventory' => true, 'identity_scope' => 'contained_files', 'identity_outcome' => 'identity_unresolved', 'updated_at' => now(),
                ]);
                $neutralName = 'Recovered.'.substr($publication->identity, 0, 24);
                Release::query()->whereKey($release->id)->update([
                    ...Release::searchNameValues($neutralName), 'isrenamed' => 0, 'is_trusted_name' => false,
                    'videos_id' => 0, 'tv_episodes_id' => 0, 'movieinfo_id' => null, 'imdbid' => null,
                    'musicinfo_id' => null, 'consoleinfo_id' => null, 'bookinfo_id' => null, 'anidbid' => null,
                    'gamesinfo_id' => 0, 'predb_id' => 0, 'categories_id' => Category::OTHER_MISC, 'iscategorized' => 1,
                ]);
                if (! $alreadyScoped) {
                    event(new ReleaseNameFixed((int) $release->id, $release->searchname, $neutralName, (int) $release->categories_id,
                        $release->groups_id, (string) $release->fromname, Category::OTHER_MISC));
                    Release::syncSearchIndexAfterCommit((int) $release->id);
                }
            }
            if ($listing['hasPassword']) {
                Release::query()->whereKey($release->id)->update(['passwordstatus' => ReleaseBrowseService::PASSWD_RAR]);
            }

            return $observations === [] ? 'archive_listing_unavailable' : 'partial_archive_listing';
        });
    }
}
