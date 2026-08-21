<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Models\Release;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\MediaTools;
use App\Services\AdditionalProcessing\PostedFileClassifier;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\AudioProcessing\DTO\AudioFetchResult;
use App\Services\AudioProcessing\DTO\AudioSource;
use App\Services\AudioProcessing\Enums\AudioSourceKind;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mhor\MediaInfo\Container\MediaInfoContainer;

/**
 * Pulls enough of a release off Usenet to clip a preview from it.
 *
 * The shape of the bare-file fetch is the whole point of this path. The shared
 * pipeline took `$segments[0]` and asked ffmpeg for a clip starting at 30
 * seconds -- past the end of the one article it had. Here article 1 is fetched
 * on its own and probed first: if it is video, or has no audio stream, nothing
 * further is fetched at all. Only once the probe agrees is the rest of the head
 * pulled, contiguously, in one request.
 *
 * Tags come out of that first article too. ID3v2, Vorbis comments and FLAC
 * metadata blocks all live at the head of the file, so the caller's $onProbe
 * hook can persist them before a single extra byte is fetched -- which is what
 * makes a declined or half-fetched release still leave its metadata behind.
 */
final class AudioFetcher
{
    public function __construct(
        private readonly AudioProcessingConfiguration $config,
        private readonly UsenetDownloadService $downloadService,
        private readonly ArchiveExtractionService $archiveService,
        private readonly MediaTools $mediaTools,
    ) {}

    /**
     * @param  Closure(MediaInfoContainer, string, string): void  $onProbe  Called with the
     *                                                                      MediaInfo read off the probed
     *                                                                      bytes, the source filename and
     *                                                                      its extension, before the rest
     *                                                                      is fetched.
     */
    public function fetch(
        Release $release,
        AudioSource $source,
        string $tmpPath,
        string $groupName,
        Closure $onProbe,
    ): AudioFetchResult {
        return match ($source->kind) {
            AudioSourceKind::BareFile => $this->fetchBareFile($release, $source, $tmpPath, $groupName, $onProbe),
            AudioSourceKind::Archive => $this->fetchFromArchive($release, $source, $tmpPath, $groupName, $onProbe),
        };
    }

    /**
     * @param  Closure(MediaInfoContainer, string, string): void  $onProbe
     */
    private function fetchBareFile(
        Release $release,
        AudioSource $source,
        string $tmpPath,
        string $groupName,
        Closure $onProbe,
    ): AudioFetchResult {
        $segments = $source->firstPartSegments();
        if ($segments === []) {
            return AudioFetchResult::failed('The chosen audio file has no segments.');
        }

        $extension = strtolower($source->extension) ?: 'audio';
        $path = $tmpPath.'audio.'.$extension;

        $head = $this->download([$segments[0]], $groupName, $release, $source->title);
        if ($head === null) {
            return AudioFetchResult::failed('The first article of the audio file could not be downloaded.');
        }

        File::put($path, $head);

        $probe = $this->probe($path, basename($source->title), $extension, $onProbe);
        if ($probe instanceof AudioFetchResult) {
            File::delete($path);

            return $probe;
        }

        // Articles 2..n, contiguous and in one request: the clip is taken from
        // whatever this leaves on disk, however short that turns out to be.
        $rest = array_slice($segments, 1, max(0, $this->config->segmentsToDownload - 1));
        if ($rest !== []) {
            $body = $this->download($rest, $groupName, $release, $source->title);
            if ($body !== null) {
                File::append($path, $body);
            }
        }

        return AudioFetchResult::fetched($path, $extension, $probe);
    }

    /**
     * Fetch archive volumes until one audio file inside is whole.
     *
     * Completeness is decided by the listing's declared size against the bytes
     * extraction actually yields, not by a part count: scene music is store-mode
     * RAR, so the first track is usually intact after one or two volumes, and
     * counting parts would either stop short or fetch far more than needed.
     *
     * @param  Closure(MediaInfoContainer, string, string): void  $onProbe
     */
    private function fetchFromArchive(
        Release $release,
        AudioSource $source,
        string $tmpPath,
        string $groupName,
        Closure $onProbe,
    ): AudioFetchResult {
        $accumulated = '';
        $volumes = array_slice($source->parts, 0, $this->config->maxRarParts);

        foreach ($volumes as $volume) {
            $data = $this->download($volume, $groupName, $release, $source->title);
            if ($data === null) {
                continue;
            }

            $accumulated .= $data;
            $listing = $this->archiveService->listArchiveContents($accumulated);

            if ($listing['hasPassword']) {
                return AudioFetchResult::failed('The archive is password protected.');
            }

            $entry = $this->firstAudioEntry($listing['files']);
            if ($entry === null) {
                continue;
            }

            $name = (string) $entry['name'];
            $declaredSize = (int) ($entry['size'] ?? 0);
            $extracted = $this->archiveService->extractSpecificFile($accumulated, $name, $tmpPath);

            if ($extracted === null || $declaredSize <= 0 || strlen($extracted) < $declaredSize) {
                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'audio';
            $path = $tmpPath.'audio.'.$extension;
            File::put($path, $extracted);

            $probe = $this->probe($path, basename($name), $extension, $onProbe);
            if ($probe instanceof AudioFetchResult) {
                File::delete($path);

                return $probe;
            }

            return AudioFetchResult::fetched($path, $extension, $probe);
        }

        return AudioFetchResult::failed('No complete audio file was found within '.count($volumes).' archive volume(s).');
    }

    /**
     * Read what is on disk and decide whether it is worth going on with.
     *
     * Returns the MediaInfo container to continue with, or the terminal
     * {@see AudioFetchResult} to return instead.
     *
     * @param  Closure(MediaInfoContainer, string, string): void  $onProbe
     */
    private function probe(
        string $path,
        string $sourceFilename,
        string $extension,
        Closure $onProbe,
    ): MediaInfoContainer|AudioFetchResult {
        try {
            $container = $this->mediaTools->mediaInfo()->getInfo($path, false);
        } catch (\Throwable $e) {
            if ($this->config->debugMode) {
                Log::debug('Audio probe failed: '.$e->getMessage());
            }

            return AudioFetchResult::failed('MediaInfo could not read the fetched audio.');
        }

        if ($container->getVideos() !== []) {
            return AudioFetchResult::declined('The probed file carries a video stream.');
        }

        if ($container->getAudios() === []) {
            return AudioFetchResult::declined('The probed file carries no audio stream.');
        }

        $onProbe($container, $sourceFilename, $extension);

        return $container;
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @return array<string, mixed>|null
     */
    private function firstAudioEntry(array $files): ?array
    {
        foreach ($files as $file) {
            $name = (string) ($file['name'] ?? '');
            if ($name === '') {
                continue;
            }

            if (PostedFileClassifier::matchesTerminalExtension($name, AudioProcessingConfiguration::AUDIO_FILE_REGEX)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $messageIds
     */
    private function download(array $messageIds, string $groupName, Release $release, string $title): ?string
    {
        $result = $this->downloadService->download(
            DownloadKind::Audio,
            $messageIds,
            $groupName,
            (int) $release->id,
            $title,
        );

        return $result['success'] && is_string($result['data']) && $result['data'] !== ''
            ? $result['data']
            : null;
    }
}
