<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\AudioTagExtractor;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Releases\PreviewGenerationPolicy;

/**
 * Rebuilds an unidentified music release's name from its own tags.
 *
 * Carried over from the shared path's `MediaExtractionService::getAudioInfo()`
 * when the audio work moved here; the rule is unchanged. Only a release with no
 * predb match, that post-processing has not already renamed, carrying both an
 * album and a performer tag, is touched, and only when `rename_music_mediainfo`
 * is on.
 */
final class AudioTagRenamer
{
    public function __construct(
        private readonly AudioProcessingConfiguration $config,
        private readonly CategorizationService $categorize,
        private readonly ReleaseSearchSyncCoordinator $searchSyncCoordinator,
        private readonly PreviewGenerationPolicy $previewPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $tags  Attributes from {@see AudioTagExtractor}.
     * @return bool Whether the release was renamed.
     */
    public function rename(Release $release, array $tags, string $fileExtension): bool
    {
        if (! $this->config->renameMusicMediaInfo
            || ($tags['album'] ?? null) === null
            || ($tags['performer'] ?? null) === null
            || (int) ($release->predb_id ?? 0) !== 0
            || (int) ($release->proc_pp ?? 0) !== 0
        ) {
            return false;
        }

        $extension = strtoupper($fileExtension);

        $newName = $tags['performer'].' - '.$tags['album'];
        $newName .= ($tags['recorded_year'] ?? null) !== null
            ? ' ('.$tags['recorded_year'].') '.$extension
            : ' '.$extension;

        $newCategory = match ($extension) {
            'MP3' => Category::MUSIC_MP3,
            'FLAC' => Category::MUSIC_LOSSLESS,
            default => $this->categorize->determineCategory(
                $release->groups_id,
                $newName,
                (string) ($release->fromname ?? ''),
                releaseId: (int) $release->id,
            ),
        };

        $newTitle = substr($newName, 0, 255);
        $releaseId = (int) $release->id;

        Release::query()->whereKey($releaseId)->update([
            'searchname' => $newTitle,
            'categories_id' => is_array($newCategory) ? $newCategory['categories_id'] : $newCategory,
            'iscategorized' => 1,
            'isrenamed' => 1,
            'proc_pp' => 1,
        ]);

        // The new category may sit under a root with preview generation enabled
        // where the old one did not (ADR 0004 owed regeneration).
        $this->previewPolicy->restoreOwedPreviews([$releaseId]);
        $this->searchSyncCoordinator->request($releaseId);

        if ($this->config->echoCLI) {
            $releaseInfo = (object) [
                'groups_id' => $release->groups_id,
                'categories_id' => $release->categories_id,
                'searchname' => $release->searchname,
                'name' => $release->searchname,
                'releases_id' => $releaseId,
                'filename' => '',
            ];
            (new ReleaseUpdateService)->echoReleaseInfo(
                $releaseInfo,
                $newTitle,
                is_array($newCategory) ? $newCategory : ['categories_id' => $newCategory],
                '',
                'AudioReleaseProcessor->recordTags',
            );
        }

        return true;
    }
}
