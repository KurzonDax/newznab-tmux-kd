<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Models\Video;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TvArtifactIdentityResolver
{
    public function __construct(
        private readonly NfoProviderReferenceExtractor $referenceExtractor = new NfoProviderReferenceExtractor,
    ) {}

    /**
     * @param  list<int>  $candidateVideoIds
     * @param  Closure(string): int  $resolveArtifactName
     */
    public function resolve(int $releaseId, array $candidateVideoIds, Closure $resolveArtifactName): ?int
    {
        $nfoText = $this->nfoText($releaseId);
        if ($nfoText !== null) {
            $references = $this->referenceExtractor->extract($nfoText);
            $resolvedVideoIds = $this->resolveReferences($references, $candidateVideoIds);

            if (count($resolvedVideoIds) > 1) {
                return null;
            }

            if ($resolvedVideoIds !== []) {
                return $resolvedVideoIds[0];
            }
        }

        $resolvedVideoIds = [];
        foreach ($this->artifactNames($releaseId) as $artifactName) {
            if (! $this->isUsefulArtifactName($artifactName)) {
                continue;
            }

            $videoId = $resolveArtifactName($artifactName);
            if ($videoId > 0 && in_array($videoId, $candidateVideoIds, true)) {
                $resolvedVideoIds[] = $videoId;
            }
        }

        $resolvedVideoIds = array_values(array_unique($resolvedVideoIds));

        return count($resolvedVideoIds) === 1 ? $resolvedVideoIds[0] : null;
    }

    private function nfoText(int $releaseId): ?string
    {
        if (! Schema::hasTable('release_nfos')) {
            return null;
        }

        $compressedNfo = DB::table('release_nfos')
            ->where('releases_id', $releaseId)
            ->value('nfo');

        if (! is_string($compressedNfo) || $compressedNfo === '') {
            return null;
        }

        $decoded = strlen($compressedNfo) > 4
            ? @gzuncompress(substr($compressedNfo, 4))
            : false;
        if (is_string($decoded)) {
            return $decoded;
        }

        $decoded = @gzuncompress($compressedNfo);

        return is_string($decoded) ? $decoded : $compressedNfo;
    }

    /**
     * @param  list<array{provider: 'imdb'|'tvdb'|'tmdb'|'tvmaze'|'tvrage', id: non-empty-string}>  $references
     * @param  list<int>  $candidateVideoIds
     * @return list<int>
     */
    private function resolveReferences(array $references, array $candidateVideoIds): array
    {
        if ($references === []) {
            return [];
        }

        $candidates = Video::query()
            ->whereKey($candidateVideoIds)
            ->get(['id', 'imdb', 'tvdb', 'tmdb', 'tvmaze', 'tvrage']);
        $resolvedVideoIds = [];

        foreach ($references as $reference) {
            foreach ($candidates as $candidate) {
                if ((int) $candidate->getAttribute($reference['provider']) === (int) $reference['id']) {
                    $resolvedVideoIds[] = (int) $candidate->id;
                }
            }
        }

        return array_values(array_unique($resolvedVideoIds));
    }

    /** @return list<string> */
    private function artifactNames(int $releaseId): array
    {
        $fileNames = Schema::hasTable('release_files')
            ? DB::table('release_files')
                ->where('releases_id', $releaseId)
                ->orderBy('name')
                ->pluck('name')
            : collect();
        $mediaNames = Schema::hasTable('media_infos')
            ? DB::table('media_infos')
                ->where('releases_id', $releaseId)
                ->whereNotNull('movie_name')
                ->orderBy('id')
                ->pluck('movie_name')
            : collect();

        return $fileNames
            ->concat($mediaNames)
            ->filter(static fn (mixed $name): bool => is_string($name))
            ->map(static fn (mixed $name): string => (string) $name)
            ->values()
            ->all();
    }

    private function isUsefulArtifactName(string $name): bool
    {
        $name = trim($name);
        if ($name === '' || strcasecmp($name, 'media') === 0) {
            return false;
        }

        return str_contains($name, '.') || preg_match('/\s/', $name) === 1;
    }
}
