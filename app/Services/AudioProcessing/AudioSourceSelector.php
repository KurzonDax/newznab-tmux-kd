<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Services\AdditionalProcessing\PostedFileClassifier;
use App\Services\AudioProcessing\DTO\AudioEvidenceFile;
use App\Services\AudioProcessing\DTO\AudioSource;
use App\Services\AudioProcessing\Enums\AudioSourceKind;

/**
 * Picks what to fetch out of a parsed NZB.
 *
 * A posted audio file always wins over an archive: it can be fetched head-first
 * and clipped from a fraction of the release, where an archive has to be pulled
 * a whole volume at a time before it can even be opened. Selection still scans
 * the complete NZB so the evidence seam retains every audio name and sidecar.
 */
final class AudioSourceSelector
{
    /**
     * @param  list<array<string, mixed>>  $nzbContents  Files as parsed by NzbContentParser.
     */
    public function select(array $nzbContents): ?AudioSource
    {
        $archiveParts = [];
        $archiveTitle = '';
        $bareTitle = '';
        $bareExtension = '';
        $bareSegments = [];
        $nzbAudioFiles = [];
        $sidecars = [];

        foreach ($nzbContents as $index => $file) {
            $title = (string) ($file['title'] ?? '');
            $segments = $this->segments($file);

            if ($title === '') {
                continue;
            }

            $filename = $this->filename($title);
            $sidecarKind = $this->sidecarKind($title);
            if ($sidecarKind !== null) {
                $sidecars[] = new AudioEvidenceFile(
                    ordinal: $index + 1,
                    filename: $filename,
                    segmentCount: count($segments),
                    kind: $sidecarKind,
                );

                continue;
            }

            if (PostedFileClassifier::matchesTerminalExtension($title, AudioProcessingConfiguration::IGNORED_FILE_REGEX)) {
                continue;
            }

            if (PostedFileClassifier::matchesTerminalExtension($title, AudioProcessingConfiguration::AUDIO_FILE_REGEX, $matches)) {
                $nzbAudioFiles[] = new AudioEvidenceFile(
                    ordinal: $index + 1,
                    filename: $filename,
                    segmentCount: count($segments),
                    kind: 'audio',
                );

                if ($bareTitle === '' && $segments !== []) {
                    $bareTitle = $title;
                    $bareExtension = strtoupper((string) ($matches[1] ?? ''));
                    $bareSegments = $segments;
                }

                continue;
            }

            if ($segments !== [] && PostedFileClassifier::containsArchiveCandidate($title)) {
                if ($archiveTitle === '') {
                    $archiveTitle = $title;
                }
                $archiveParts[] = $segments;
            }
        }

        if ($bareTitle !== '') {
            return new AudioSource(
                kind: AudioSourceKind::BareFile,
                title: $bareTitle,
                extension: $bareExtension,
                parts: [$bareSegments],
                nzbAudioFiles: $nzbAudioFiles,
                sidecars: $sidecars,
            );
        }

        if ($archiveParts === []) {
            return null;
        }

        // Every volume, in posted order: how many of them are worth fetching is
        // AudioFetcher's call, and belongs in one place.
        return new AudioSource(
            kind: AudioSourceKind::Archive,
            title: $archiveTitle,
            extension: '',
            parts: $archiveParts,
            nzbAudioFiles: $nzbAudioFiles,
            sidecars: $sidecars,
        );
    }

    /**
     * @param  array<string, mixed>  $file
     * @return list<string>
     */
    private function segments(array $file): array
    {
        $segments = $file['segments'] ?? [];
        if (! is_array($segments)) {
            return [];
        }

        $messageIds = [];
        foreach (array_values($segments) as $segment) {
            $messageId = (string) $segment;
            if ($messageId !== '') {
                $messageIds[] = $messageId;
            }
        }

        return $messageIds;
    }

    private function filename(string $title): string
    {
        if (preg_match_all('/"([^"]+)"/', $title, $matches) > 0) {
            return trim((string) $matches[1][array_key_last($matches[1])]);
        }

        return trim((string) preg_replace('/\s+yEnc(?:\s.*)?$/i', '', $title), " \t\n\r\0\x0B\"");
    }

    private function sidecarKind(string $title): ?string
    {
        return match (true) {
            PostedFileClassifier::matchesTerminalExtension($title, '\\.CUE') => 'cue',
            PostedFileClassifier::matchesTerminalExtension($title, '\\.(M3U|M3U8|PLS)') => 'playlist',
            PostedFileClassifier::matchesTerminalExtension($title, '\\.LOG') => 'eac_log',
            default => null,
        };
    }
}
