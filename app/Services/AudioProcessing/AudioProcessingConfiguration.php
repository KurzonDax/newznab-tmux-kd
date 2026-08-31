<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Models\Settings;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;

/**
 * Settings for the dedicated audio post-processing path.
 *
 * Separate from {@see ProcessingConfiguration}
 * because the two paths want opposite things from the same knobs: the shared
 * path fetches two segments of a video file to read its header, while an audio
 * preview needs enough contiguous bytes at the head of the file to clip from.
 */
final readonly class AudioProcessingConfiguration
{
    /**
     * Extensions that identify a standalone audio file worth previewing.
     *
     * Deliberately excludes AC3/DTS/MKA/MKS: those are posted as side-files of
     * video releases, not as music. DFF/DSF, WV, M4A, OGA, OPUS and TTA are
     * here because they are what the lossless groups actually post.
     */
    public const string AUDIO_FILE_REGEX = '\\.(AAC|AIFF|APE|ASF|DFF|DSF|FLAC|M4A|MP2|MP3|OGA|OGG|OGM|OPUS|RA|TTA|W64|WAV|WMA|WV)';

    /** Side-car files that are never a preview source, whatever else is in the NZB. */
    public const string IGNORED_FILE_REGEX = '\\.(CUE|M3U|M3U8|LOG|JPE?G|PNG|NFO|SFV|PAR2|TXT|DIZ)';

    /** Image and text side-cars that may precede audio in a multi-volume archive. */
    public const string ARCHIVE_SUPPORT_FILE_REGEX = '\\.(?:JPE?G|PNG|GIF|BMP|NFO|SFV|TXT|MD5|M3U|CUE|LOG|PDF|URL|DIZ)';

    public bool $echoCLI;

    public bool $debugMode;

    /** Head articles fetched for a bare audio file, article 1 included. */
    public int $segmentsToDownload;

    /** Archive parts fetched before giving up on a complete audio file. */
    public int $maxRarParts;

    /** Bytes fetched into one archive before giving up; null means unlimited. */
    public ?int $maxArchiveBytes;

    /** Releases below this source completion percentage are skipped; zero disables the gate. */
    public float $minimumCompletionPercent;

    public int $previewSeconds;

    public int $previewStartSeconds;

    public bool $spectrogram;

    /** Releases claimed per worker batch. */
    public int $queryLimit;

    public int $maxSizeBytes;

    public string $savePath;

    public string $tmpUnrarPath;

    public string|false $mediaInfoPath;

    public bool $renameMusicMediaInfo;

    public function __construct()
    {
        $this->echoCLI = (bool) config('nntmux.echocli');
        $this->debugMode = (bool) config('app.debug');
        $this->segmentsToDownload = max(1, (int) (Settings::settingValue('audio_segments_to_download') ?: 12));
        $this->maxRarParts = max(1, (int) (Settings::settingValue('audio_max_rar_parts') ?: 6));
        $maxArchiveMb = Settings::settingValue('audio_max_archive_mb');
        $maxArchiveMb = ($maxArchiveMb === '' || $maxArchiveMb === null) ? 1024 : max(0, (int) $maxArchiveMb);
        $this->maxArchiveBytes = $maxArchiveMb === 0 ? null : $maxArchiveMb * 1024 * 1024;
        $minimumCompletionPercent = Settings::settingValue('audio_min_completion_percent');
        $minimumCompletionPercent = ($minimumCompletionPercent === '' || $minimumCompletionPercent === null)
            ? 95.0
            : (float) $minimumCompletionPercent;
        $this->minimumCompletionPercent = min(100.0, max(0.0, $minimumCompletionPercent));
        $this->previewSeconds = max(1, (int) (Settings::settingValue('audio_preview_seconds') ?: 30));
        // An explicit '0' is a legitimate "clip from the very start".
        $this->previewStartSeconds = max(0, (int) Settings::settingValue('audio_preview_start_seconds'));
        $this->spectrogram = (int) (Settings::settingValue('audio_spectrogram') ?? 1) !== 0;
        $this->queryLimit = (int) (Settings::settingValue('maxaddprocessed') ?: 25);
        // No minimum: see AudioCandidateQuery. The global maximum still applies.
        $this->maxSizeBytes = AdditionalCandidateQuery::maxSizeBytes();
        $this->savePath = config('nntmux_settings.covers_path').'/audiosample/';
        $this->tmpUnrarPath = (string) config('nntmux.tmp_unrar_path');
        $this->mediaInfoPath = config('nntmux_settings.mediainfo_path') ?: false;
        $this->renameMusicMediaInfo = (bool) config('nntmux.rename_music_mediainfo');
    }
}
