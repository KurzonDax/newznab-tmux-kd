<?php

use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Imports used to keep segment message-IDs as the source NZB wrote them, so an
     * ID inside angle brackets reached NZBGet as `<<id>>` and was hashed into
     * collectionhash with its brackets. Rewrite each imported release's stored NZB
     * with bare IDs, and recompute its collectionhash the way import now does.
     */
    public function up(): void
    {
        $nzbService = app(NzbService::class);

        DB::table('releases')
            ->where('is_imported', 1)
            ->select(['id', 'guid', 'collectionhash'])
            ->orderBy('id')
            ->chunkById(250, function ($releases) use ($nzbService): void {
                foreach ($releases as $release) {
                    $this->normalizeRelease($nzbService, (int) $release->id, (string) $release->guid, $release->collectionhash);
                }
            }, 'id');
    }

    private function normalizeRelease(NzbService $nzbService, int $releaseId, string $guid, ?string $storedHash): void
    {
        $contents = $nzbService->readNzbContents($guid);
        if ($contents === false) {
            return;
        }

        $nzbXml = @simplexml_load_string($contents);
        if ($nzbXml === false || strtolower($nzbXml->getName()) !== 'nzb') {
            return;
        }

        if (NzbImportService::normalizeSegmentMessageIds($nzbXml)) {
            $normalized = $nzbXml->asXML();
            $result = $normalized === false ? null : $nzbService->replaceNzbContents($guid, $normalized);
            if ($result === null || ! $result->success) {
                Log::warning('Could not rewrite the stored NZB of an imported release with bare segment message-IDs.', [
                    'release_id' => $releaseId,
                    'reason' => $result === null ? 'The normalised NZB could not be serialised.' : $result->reason,
                ]);
            }
        }

        $hash = NzbImportService::computeSegmentMessageIdHash(NzbImportService::segmentMessageIds($nzbXml));
        if ($hash === null || $hash === $storedHash) {
            return;
        }

        $conflictingReleaseId = DB::table('releases')
            ->where('collectionhash', $hash)
            ->where('id', '<>', $releaseId)
            ->value('id');
        if ($conflictingReleaseId !== null) {
            Log::warning('Kept the collectionhash of an imported release: its bare segment message-ID hash belongs to another release.', [
                'release_id' => $releaseId,
                'conflicting_release_id' => (int) $conflictingReleaseId,
            ]);

            return;
        }

        DB::table('releases')->where('id', $releaseId)->update(['collectionhash' => $hash]);
    }

    /**
     * Nothing to undo: bracketed message-IDs are not restored.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
};
