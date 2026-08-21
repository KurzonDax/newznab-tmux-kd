<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Services\AdditionalProcessing\PostedFileClassifier;
use App\Services\AudioProcessing\DTO\AudioSource;
use App\Services\AudioProcessing\Enums\AudioSourceKind;

/**
 * Picks what to fetch out of a parsed NZB.
 *
 * A posted audio file always wins over an archive: it can be fetched head-first
 * and clipped from a fraction of the release, where an archive has to be pulled
 * a whole volume at a time before it can even be opened. A CD-image rip -- one
 * big .flac/.wv/.ape next to a .cue -- is just a large audio file here; the cue
 * sheet is ignored along with the rest of the side-cars.
 */
final class AudioSourceSelector
{
    public function __construct(private readonly AudioProcessingConfiguration $config) {}

    /**
     * @param  list<array<string, mixed>>  $nzbContents  Files as parsed by NzbContentParser.
     */
    public function select(array $nzbContents): ?AudioSource
    {
        $archiveParts = [];
        $archiveTitle = '';

        foreach ($nzbContents as $file) {
            $title = (string) ($file['title'] ?? '');
            $segments = $this->segments($file);

            if ($title === '' || $segments === []) {
                continue;
            }

            if (PostedFileClassifier::matchesTerminalExtension($title, AudioProcessingConfiguration::IGNORED_FILE_REGEX)) {
                continue;
            }

            if (PostedFileClassifier::matchesTerminalExtension($title, AudioProcessingConfiguration::AUDIO_FILE_REGEX, $matches)) {
                return new AudioSource(
                    kind: AudioSourceKind::BareFile,
                    title: $title,
                    extension: strtoupper((string) ($matches[1] ?? '')),
                    parts: [$segments],
                );
            }

            if (PostedFileClassifier::containsArchiveCandidate($title)) {
                if ($archiveTitle === '') {
                    $archiveTitle = $title;
                }
                $archiveParts[] = $segments;
            }
        }

        if ($archiveParts === []) {
            return null;
        }

        return new AudioSource(
            kind: AudioSourceKind::Archive,
            title: $archiveTitle,
            extension: '',
            parts: array_slice($archiveParts, 0, $this->config->maxRarParts),
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
}
