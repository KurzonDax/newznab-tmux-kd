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
use App\Services\AudioProcessing\Exceptions\WavPackDecoderUnavailable;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
    private const string CRC_FAILURE_REASON = 'Source articles failed CRC verification.';

    private const int ARCHIVE_FETCH_CHUNK_SEGMENTS = 64;

    private const int PARTIAL_MARGIN_SECONDS = 2;

    private const int NON_AUDIO_LISTING_VOLUME_LIMIT = 2;

    private int $crcFailures = 0;

    private bool $sourceDamaged = false;

    public function __construct(
        private readonly AudioProcessingConfiguration $config,
        private readonly UsenetDownloadService $downloadService,
        private readonly ArchiveExtractionService $archiveService,
        private readonly MediaTools $mediaTools,
        private readonly AudioDecodableLengthProbe $decodableLengthProbe,
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
        $this->crcFailures = 0;
        $this->sourceDamaged = false;
        $incompleteReason = $this->incompleteSourceReason($release);
        if ($incompleteReason !== null) {
            return AudioFetchResult::failed($incompleteReason);
        }

        $result = match ($source->kind) {
            AudioSourceKind::BareFile => $this->fetchBareFile($release, $source, $tmpPath, $groupName, $onProbe),
            AudioSourceKind::Archive => $this->fetchFromArchive($release, $source, $tmpPath, $groupName, $onProbe),
        };

        return $result->withCrcFailures($this->crcFailures);
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
            return AudioFetchResult::failed(
                $this->sourceDamaged
                    ? self::CRC_FAILURE_REASON
                    : 'The first article of the audio file could not be downloaded.'
            );
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
            } elseif ($this->sourceDamaged) {
                File::delete($path);

                return AudioFetchResult::failed(self::CRC_FAILURE_REASON);
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
        $archiveBytes = 0;
        $volumes = $source->parts;
        $firstArchivePath = $this->archiveVolumePath($tmpPath, 1);
        $knownAudioFile = $this->knownAudioFile($release);
        $fetchedVolumes = 0;
        $volumeIndex = 0;
        $firstVolumeBytes = null;
        $carvedPath = null;
        $carvedName = null;
        $keepCarvedPath = false;
        $seekTargetBeyondBudget = false;
        $sequentialFallback = false;
        $sequentialFallbackThroughIndex = -1;
        $compressedBackfillRequired = false;
        $nonAudioListingVolumes = 0;

        /** @var array<int, true> $completedVolumeIndexes */
        $completedVolumeIndexes = [];

        /** @var array<string, array{bytes: int, volume: int}> $storeProgress */
        $storeProgress = [];

        /** @var array<string, array<string, mixed>> $listedFiles */
        $listedFiles = [];

        try {
            while ($volumeIndex < count($volumes) && $fetchedVolumes < $this->config->maxRarParts) {
                $volume = $volumes[$volumeIndex];
                $archivePath = $this->archiveVolumePath($tmpPath, $volumeIndex + 1);
                File::put($archivePath, '');
                $fetchedVolumes++;
                if ($sequentialFallback) {
                    $sequentialFallbackThroughIndex = max($sequentialFallbackThroughIndex, $volumeIndex);
                }

                /** @var list<array<string, mixed>> $currentFiles */
                $currentFiles = [];

                foreach (array_chunk($volume, self::ARCHIVE_FETCH_CHUNK_SEGMENTS) as $chunk) {
                    $data = $this->download($chunk, $groupName, $release, $source->title);
                    if ($data === null) {
                        if ($this->sourceDamaged) {
                            return AudioFetchResult::failed(self::CRC_FAILURE_REASON);
                        }

                        continue;
                    }

                    $chunkBytes = strlen($data);
                    if ($this->config->maxArchiveBytes !== null
                        && $archiveBytes + $chunkBytes > $this->config->maxArchiveBytes
                    ) {
                        $ceilingMb = max(1, (int) ceil($this->config->maxArchiveBytes / 1024 / 1024));

                        return AudioFetchResult::failed(
                            'The archive exceeded the '.$ceilingMb.' MB fetch ceiling before a whole audio file was found.'
                        );
                    }

                    File::append($archivePath, $data);
                    $archiveBytes += $chunkBytes;
                    $listing = $this->archiveService->listArchiveContentsAtPath($archivePath);

                    if ($listing['hasPassword']) {
                        return AudioFetchResult::failed(
                            'The archive is password protected.',
                            archivePassworded: true,
                        );
                    }

                    if ($fetchedVolumes === 1 && ($listing['isFirstVolume'] ?? null) === false) {
                        return AudioFetchResult::failed(
                            'Archive set starts mid-volume; the first volume is not in this release.'
                        );
                    }

                    $currentFiles = array_values($listing['files']);
                    foreach ($currentFiles as $file) {
                        $name = (string) ($file['name'] ?? '');
                        if ($name !== '' && ! array_key_exists($name, $listedFiles)) {
                            $listedFiles[$name] = $file;
                        }
                    }

                    $entry = $this->enrichAudioEntry(
                        $this->firstAudioEntry(array_values($listedFiles), $knownAudioFile),
                        $knownAudioFile,
                    );
                    if ($entry === null) {
                        continue;
                    }

                    if ($this->isCarvableStoredEntry($entry)) {
                        continue;
                    }

                    if ((int) ($entry['compressed'] ?? 0) === 1) {
                        $sequentialFallback = true;
                        $sequentialFallbackThroughIndex = max($sequentialFallbackThroughIndex, $volumeIndex);
                        $missingVolumeIndex = $this->firstMissingVolumeIndex(
                            $completedVolumeIndexes,
                            $sequentialFallbackThroughIndex,
                        );
                        if ($missingVolumeIndex !== null && $missingVolumeIndex !== $volumeIndex) {
                            $compressedBackfillRequired = true;
                        }

                        if ($compressedBackfillRequired) {
                            continue;
                        }
                    }

                    $result = $this->extractListedAudioEntry(
                        $entry,
                        $firstArchivePath,
                        $tmpPath,
                        $onProbe,
                    );
                    if ($result !== null) {
                        return $result;
                    }
                }

                $completedVolumeIndexes[$volumeIndex] = true;

                if ($knownAudioFile === null
                    && $currentFiles !== []
                    && $this->firstAudioEntry(array_values($listedFiles), $knownAudioFile) === null
                ) {
                    $nonAudioListingVolumes++;
                    if ($nonAudioListingVolumes >= self::NON_AUDIO_LISTING_VOLUME_LIMIT) {
                        return $this->noAudioArchiveResult(array_values($listedFiles));
                    }
                }

                if ($firstVolumeBytes === null && File::isFile($archivePath)) {
                    $firstVolumeBytes = max(1, File::size($archivePath));
                }

                if ($sequentialFallback) {
                    $missingVolumeIndex = $this->firstMissingVolumeIndex(
                        $completedVolumeIndexes,
                        $sequentialFallbackThroughIndex,
                    );
                    if ($missingVolumeIndex !== null) {
                        $volumeIndex = $missingVolumeIndex;

                        continue;
                    }

                    if ($compressedBackfillRequired) {
                        $entry = $this->enrichAudioEntry(
                            $this->firstAudioEntry(array_values($listedFiles), $knownAudioFile),
                            $knownAudioFile,
                        );
                        $result = $entry === null ? null : $this->extractListedAudioEntry(
                            $entry,
                            $firstArchivePath,
                            $tmpPath,
                            $onProbe,
                        );
                        if ($result !== null) {
                            return $result;
                        }
                    }

                    $volumeIndex = $sequentialFallbackThroughIndex + 1;

                    continue;
                }

                $currentAudioEntry = $carvedName === null
                    ? $this->firstAudioEntry($currentFiles, $knownAudioFile)
                    : $this->entryNamed($currentFiles, $carvedName);
                $currentAudioEntry = $this->enrichAudioEntry($currentAudioEntry, $knownAudioFile);

                if ($currentAudioEntry !== null && $this->isCarvableStoredEntry($currentAudioEntry)) {
                    $name = (string) $currentAudioEntry['name'];
                    $carvedPath ??= $tmpPath.basename($name);
                    $append = $carvedName !== null;
                    $carvedName ??= $name;

                    if (! $this->archiveService->carveStoredFileChunkToPath(
                        $archivePath,
                        $currentAudioEntry,
                        $carvedPath,
                        $append,
                    )) {
                        if (File::isFile($carvedPath)) {
                            File::delete($carvedPath);
                        }

                        return AudioFetchResult::failed(
                            'The stored audio entry '.basename($name).' was found, but its payload could not be carved.'
                        );
                    }

                    $result = $this->usableAudioResult(
                        $carvedPath,
                        $currentAudioEntry,
                        $onProbe,
                        deleteWhenTooShort: false,
                    );
                    if ($result !== null) {
                        $keepCarvedPath = $result->succeeded();

                        return $result;
                    }

                    $volumeIndex++;

                    continue;
                }

                $seekDecision = $this->nextArchiveVolumeIndex(
                    $currentFiles,
                    $volumeIndex,
                    $firstVolumeBytes ?? 1,
                    $storeProgress,
                );
                if ($knownAudioFile !== null
                    && $seekDecision['storeSeekComputed']
                    && $fetchedVolumes >= $this->config->maxRarParts
                ) {
                    $seekTargetBeyondBudget = true;
                }
                $volumeIndex = $seekDecision['index'];
            }

            if ($carvedPath !== null) {
                File::delete($carvedPath);

                return AudioFetchResult::failed(
                    'The stored audio entry '.basename((string) $carvedName)
                    .' was found, but it could not be carved into a usable preview within '
                    .$fetchedVolumes.' fetched archive volume(s).'
                );
            }

            if ($seekTargetBeyondBudget && $knownAudioFile !== null) {
                return AudioFetchResult::failed(
                    'Release metadata identifies '.basename($knownAudioFile['name'])
                    .', but its stored data starts beyond the '.$fetchedVolumes.' fetched volume budget.'
                );
            }

            if ($volumeIndex >= count($volumes)
                && $knownAudioFile === null
                && $listedFiles !== []
                && $this->firstAudioEntry(array_values($listedFiles)) === null
            ) {
                return $this->noAudioArchiveResult(array_values($listedFiles));
            }

            return AudioFetchResult::failed(
                $this->sourceDamaged
                    ? self::CRC_FAILURE_REASON
                    :
                'No usable audio file was found within '.$fetchedVolumes.' fetched archive volume(s).'
            );
        } finally {
            if (! $keepCarvedPath && $carvedPath !== null && File::isFile($carvedPath)) {
                File::delete($carvedPath);
            }

            foreach (File::glob($tmpPath.'audio-archive.part*.rar') as $archivePartPath) {
                if (File::isFile($archivePartPath)) {
                    File::delete($archivePartPath);
                }
            }
        }
    }

    private function incompleteSourceReason(Release $release): ?string
    {
        $completion = (float) ($release->completion ?? 0);
        if ($completion <= 0.0
            || $this->config->minimumCompletionPercent === 0.0
            || $completion >= $this->config->minimumCompletionPercent
        ) {
            return null;
        }

        $formattedCompletion = rtrim(rtrim(number_format($completion, 2, '.', ''), '0'), '.');

        return 'Source is only '.$formattedCompletion.'% complete.';
    }

    /**
     * @param  list<array<string, mixed>>  $files
     */
    private function noAudioArchiveResult(array $files): AudioFetchResult
    {
        $extensions = [];
        $containsVideo = false;

        foreach ($files as $file) {
            $name = (string) ($file['name'] ?? '');
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($extension !== '') {
                $extensions[$extension] = true;
            }
            if (PostedFileClassifier::matchesTerminalExtension(
                $name,
                PostedFileClassifier::VIDEO_FILE_REGEX,
            )) {
                $containsVideo = true;
            }
        }

        $found = array_keys($extensions);
        sort($found);
        $found = $found === [] ? ['unknown'] : $found;
        $reason = 'The archive holds no audio files (found: '.implode(', ', $found).').';

        return $containsVideo
            ? AudioFetchResult::declined($reason)
            : AudioFetchResult::failed($reason);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  Closure(MediaInfoContainer, string, string): void  $onProbe
     */
    private function usableAudioResult(
        string $path,
        array $entry,
        Closure $onProbe,
        bool $deleteWhenTooShort = true,
    ): ?AudioFetchResult {
        if (! File::isFile($path)) {
            return null;
        }

        $name = (string) ($entry['name'] ?? basename($path));
        $declaredSize = (int) ($entry['size'] ?? 0);
        $isComplete = $declaredSize > 0 && File::size($path) >= $declaredSize;
        $requiredSeconds = $this->config->previewStartSeconds
            + $this->config->previewSeconds
            + self::PARTIAL_MARGIN_SECONDS;

        try {
            $hasPreviewWindow = $isComplete || $this->decodableLengthProbe->demuxedSeconds(
                $path,
                $requiredSeconds,
            ) >= $requiredSeconds;
        } catch (WavPackDecoderUnavailable $exception) {
            File::delete($path);

            return AudioFetchResult::failed($exception->getMessage());
        }

        if (! $hasPreviewWindow) {
            if ($deleteWhenTooShort) {
                File::delete($path);
            }

            return null;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'audio';
        $probe = $this->probe($path, basename($name), $extension, $onProbe);
        if ($probe instanceof AudioFetchResult) {
            File::delete($path);

            return $probe;
        }

        return AudioFetchResult::fetched($path, $extension, $probe);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  Closure(MediaInfoContainer, string, string): void  $onProbe
     */
    private function extractListedAudioEntry(
        array $entry,
        string $firstArchivePath,
        string $tmpPath,
        Closure $onProbe,
    ): ?AudioFetchResult {
        $path = $this->archiveService->extractSpecificFileToPath(
            $firstArchivePath,
            (string) $entry['name'],
            $tmpPath,
            keepBroken: true,
        );

        return $path === null ? null : $this->usableAudioResult($path, $entry, $onProbe);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isCarvableStoredEntry(array $entry): bool
    {
        return (int) ($entry['compressed'] ?? 1) === 0
            && preg_match('/^\d+-\d+$/', (string) ($entry['range'] ?? '')) === 1;
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @param  array<string, array{bytes: int, volume: int}>  $storeProgress
     * @return array{index: int, storeSeekComputed: bool}
     */
    private function nextArchiveVolumeIndex(
        array $files,
        int $currentVolumeIndex,
        int $volumeBytes,
        array &$storeProgress,
    ): array {
        foreach ($files as $file) {
            if ((int) ($file['compressed'] ?? 0) === 1) {
                return ['index' => $currentVolumeIndex + 1, 'storeSeekComputed' => false];
            }
        }

        $entry = $files === [] ? null : $files[array_key_last($files)];
        if ($entry === null || ! $this->isCarvableStoredEntry($entry) || empty($entry['split_after'])) {
            return ['index' => $currentVolumeIndex + 1, 'storeSeekComputed' => false];
        }

        $name = (string) ($entry['name'] ?? '');
        $declaredSize = (int) ($entry['size'] ?? 0);
        if ($name === '' || $declaredSize < 1) {
            return ['index' => $currentVolumeIndex + 1, 'storeSeekComputed' => false];
        }

        preg_match('/^(\d+)-(\d+)$/', (string) $entry['range'], $matches);
        $chunkBytes = (int) $matches[2] - (int) $matches[1] + 1;
        $progress = $storeProgress[$name] ?? ['bytes' => 0, 'volume' => $currentVolumeIndex];
        $skippedVolumes = max(0, $currentVolumeIndex - $progress['volume'] - 1);
        $progress['bytes'] += ($skippedVolumes * $volumeBytes) + $chunkBytes;
        $progress['volume'] = $currentVolumeIndex;
        $storeProgress[$name] = $progress;

        $remainingBytes = max(0, $declaredSize - $progress['bytes']);
        $volumesToAdvance = max(1, intdiv($remainingBytes + $volumeBytes - 1, $volumeBytes));

        return [
            'index' => $currentVolumeIndex + $volumesToAdvance,
            'storeSeekComputed' => true,
        ];
    }

    /**
     * @param  array<int, true>  $downloadedVolumeIndexes
     */
    private function firstMissingVolumeIndex(array $downloadedVolumeIndexes, int $throughIndex): ?int
    {
        for ($volumeIndex = 0; $volumeIndex <= $throughIndex; $volumeIndex++) {
            if (! isset($downloadedVolumeIndexes[$volumeIndex])) {
                return $volumeIndex;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @return array<string, mixed>|null
     */
    private function entryNamed(array $files, string $name): ?array
    {
        foreach ($files as $file) {
            if ((string) ($file['name'] ?? '') === $name) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array{name: string, size: int}|null
     */
    private function knownAudioFile(Release $release): ?array
    {
        if (! $release->relationLoaded('file')
            && (! $release->exists || ! Schema::hasTable('release_files'))
        ) {
            return null;
        }

        $files = $release->relationLoaded('file')
            ? $release->getRelation('file')
            : $release->file()->get(['name', 'size']);

        foreach ($files as $file) {
            $name = (string) $file->name;
            if (PostedFileClassifier::matchesTerminalExtension($name, AudioProcessingConfiguration::AUDIO_FILE_REGEX)) {
                return [
                    'name' => $name,
                    'size' => (int) $file->size,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $entry
     * @param  array{name: string, size: int}|null  $knownAudioFile
     * @return array<string, mixed>|null
     */
    private function enrichAudioEntry(?array $entry, ?array $knownAudioFile): ?array
    {
        if ($entry === null || $knownAudioFile === null || $knownAudioFile['size'] < 1) {
            return $entry;
        }

        if (strcasecmp(basename((string) ($entry['name'] ?? '')), basename($knownAudioFile['name'])) === 0
            && (int) ($entry['size'] ?? 0) < 1
        ) {
            $entry['size'] = $knownAudioFile['size'];
        }

        return $entry;
    }

    private function archiveVolumePath(string $tmpPath, int $volume): string
    {
        return $tmpPath.'audio-archive.part'.sprintf('%03d', $volume).'.rar';
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
     * @param  array{name: string, size: int}|null  $knownAudioFile
     * @return array<string, mixed>|null
     */
    private function firstAudioEntry(array $files, ?array $knownAudioFile = null): ?array
    {
        if ($knownAudioFile !== null) {
            foreach ($files as $file) {
                if (strcasecmp(
                    basename((string) ($file['name'] ?? '')),
                    basename($knownAudioFile['name']),
                ) === 0) {
                    return $file;
                }
            }
        }

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

        $this->crcFailures += (int) ($result['crcFailures'] ?? 0);
        $this->sourceDamaged = $this->sourceDamaged || (bool) ($result['crcFailed'] ?? false);

        return $result['success'] && is_string($result['data']) && $result['data'] !== ''
            ? $result['data']
            : null;
    }
}
