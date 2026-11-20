<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Models\Release;
use App\Services\TvProcessing\Pipes\AbstractTvProviderPipe;
use App\Services\TvProcessing\Pipes\LocalDbPipe;
use App\Services\TvProcessing\Pipes\ParseInfoPipe;
use App\Services\TvProcessing\Pipes\TmdbPipe;
use App\Services\TvProcessing\Pipes\TraktPipe;
use App\Services\TvProcessing\Pipes\TvdbPipe;
use App\Services\TvProcessing\Pipes\TvMazePipe;
use App\Services\TvProcessing\Providers\LocalDbProvider;
use Illuminate\Pipeline\Pipeline;

/**
 * The tag-free pass: a release whose name starts with a group tag ("aaf-dexters.laboratory.s01e13")
 * and whose full name found no show is looked up once more without the tag, through the lookup
 * pipes in priority order. A show found that way is bound only together with the episode.
 *
 * Keep one instance for a whole run, so each pipe's failed-title cache spans its releases.
 */
final class TagFreeShowLookup
{
    private const TYPE_TV = 0;

    /** @var list<AbstractTvProviderPipe> */
    private array $pipes;

    private ?LocalDbProvider $localDb = null;

    /**
     * @param  iterable<AbstractTvProviderPipe>|null  $pipes  the lookup pipes; ParseInfoPipe is left out
     */
    public function __construct(?iterable $pipes = null)
    {
        $this->pipes = collect($pipes ?? [new LocalDbPipe, new TvdbPipe, new TvMazePipe, new TmdbPipe, new TraktPipe])
            ->reject(static fn (AbstractTvProviderPipe $pipe): bool => $pipe instanceof ParseInfoPipe)
            ->sortBy(static fn (AbstractTvProviderPipe $pipe): int => $pipe->getPriority())
            ->values()
            ->all();
    }

    public function setEchoOutput(bool $echo): self
    {
        foreach ($this->pipes as $pipe) {
            $pipe->setEchoOutput($echo);
        }

        return $this;
    }

    /**
     * Run the pass for a release whose full-name lookup has finished without a show.
     *
     * Returns null when the release gets no pass: its name has no group tag in front of a
     * different show name, or the release has a show. With $resolveFullName the full name is
     * first resolved locally, and a show or an ambiguous result also means no pass.
     *
     * @param  array<string, mixed>|object  $release
     * @param  array<string, mixed>  $fullParse  parseInfo() of the full release name
     */
    public function run(array|object $release, array $fullParse, bool $resolveFullName = false, bool $debug = false): ?TvProcessingPassable
    {
        $context = TvReleaseContext::fromRelease($release);
        $tagFreeParse = $this->localDb()->tagFreeParse($context->searchName, $fullParse);
        if ($tagFreeParse === null) {
            return null;
        }

        $videosId = Release::query()->whereKey($context->releaseId)->value('videos_id');
        if ($videosId === null || (int) $videosId > 0) {
            return null;
        }

        if ($resolveFullName) {
            $resolution = $this->localDb()->resolveByRelease($fullParse, self::TYPE_TV, releaseId: $context->releaseId);
            if ($resolution->videoId > 0 || $resolution->isAmbiguous()) {
                return null;
            }
        }

        $passable = new TvProcessingPassable(new TvReleaseContext(
            releaseId: $context->releaseId,
            searchName: $context->searchName,
            groupsId: $context->groupsId,
            categoriesId: $context->categoriesId,
            videosId: 0,
            tvEpisodesId: $context->tvEpisodesId,
            guid: $context->guid,
            leftGuid: $context->leftGuid,
        ), $debug);
        $passable->setParsedInfo($tagFreeParse);
        $passable->requireEpisode();

        /** @var TvProcessingPassable */
        return app(Pipeline::class)
            ->send($passable)
            ->through($this->pipes)
            ->thenReturn();
    }

    private function localDb(): LocalDbProvider
    {
        return $this->localDb ??= new LocalDbProvider;
    }
}
