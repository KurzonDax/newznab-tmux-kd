<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\Sidecars;

/**
 * A CUE sheet's album and track list (issue #313, section C), read by a pure parser. Keywords are
 * case-insensitive and values quoted or bare. Before the first TRACK, TITLE is the album,
 * PERFORMER the album artist and CATALOG the barcode (12 or 13 digits, not all zeros); REM
 * DISCNUMBER gives the disc. FILE starts a file group and TRACK nn AUDIO a track (other track
 * types are skipped), which reads TITLE, PERFORMER, ISRC and INDEX 01 at 75 frames a second
 * (INDEX 00 is ignored). A sheet is valid with 1-99 audio tracks, each with an INDEX 01 that
 * strictly increases within its file; an invalid sheet reads as nothing.
 *
 * @phpstan-type CueTrack array{number: int, title: string|null, performer: string|null, isrc: string|null, start: float}
 * @phpstan-type CueFile array{name: string, tracks: list<CueTrack>}
 */
final readonly class CueSheet
{
    private const int FRAMES_PER_SECOND = 75;

    /** @param list<CueFile> $files only the files holding an audio track */
    private function __construct(
        public ?string $album,
        public ?string $albumArtist,
        public ?string $barcode,
        public ?int $discNumber,
        public array $files,
    ) {}

    public static function parse(string $text): ?self
    {
        $album = $albumArtist = $barcode = null;
        $discNumber = null;
        /** @var list<array{name: string, tracks: list<array{number: int, title: string|null, performer: string|null, isrc: string|null, start: float|null}>}> $files */
        $files = [];
        $inTrack = false;
        $audioTrack = false;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^(\S+)\s*(.*)$/u', $line, $match) !== 1) {
                continue;
            }
            $keyword = strtoupper($match[1]);
            $rest = trim($match[2]);
            $file = array_key_last($files);

            switch ($keyword) {
                case 'FILE':
                    $files[] = ['name' => self::fileName($rest), 'tracks' => []];
                    $inTrack = false;
                    break;
                case 'TRACK':
                    if (preg_match('/^(\d{1,2})\s+(\S+)/', $rest, $track) !== 1 || $file === null) {
                        return null;
                    }
                    $inTrack = true;
                    $audioTrack = strtoupper($track[2]) === 'AUDIO';
                    if ($audioTrack) {
                        $files[$file]['tracks'][] = ['number' => (int) $track[1], 'title' => null, 'performer' => null, 'isrc' => null, 'start' => null];
                    }
                    break;
                case 'TITLE':
                case 'PERFORMER':
                    $value = self::value($rest);
                    if (! $inTrack) {
                        $keyword === 'TITLE' ? $album = $value : $albumArtist = $value;
                    } elseif ($audioTrack && $file !== null) {
                        $files[$file]['tracks'][array_key_last($files[$file]['tracks'])][strtolower($keyword)] = $value;
                    }
                    break;
                case 'ISRC':
                    if ($inTrack && $audioTrack && $file !== null) {
                        $files[$file]['tracks'][array_key_last($files[$file]['tracks'])]['isrc'] = self::value($rest);
                    }
                    break;
                case 'INDEX':
                    if ($inTrack && $audioTrack && $file !== null && preg_match('/^0*1\s+(\d+):(\d{1,2}):(\d{1,2})$/', $rest, $index) === 1) {
                        $files[$file]['tracks'][array_key_last($files[$file]['tracks'])]['start']
                            = (int) $index[1] * 60 + (int) $index[2] + (int) $index[3] / self::FRAMES_PER_SECOND;
                    }
                    break;
                case 'CATALOG':
                    $value = (string) self::value($rest);
                    if (! $inTrack && preg_match('/^\d{12,13}$/', $value) === 1 && trim($value, '0') !== '') {
                        $barcode = $value;
                    }
                    break;
                case 'REM':
                    if (preg_match('/^DISCNUMBER\s+"?(\d{1,3})"?$/i', $rest, $disc) === 1 && (int) $disc[1] > 0) {
                        $discNumber = (int) $disc[1];
                    }
                    break;
            }
        }

        return self::valid($album, $albumArtist, $barcode, $discNumber, $files);
    }

    public function trackCount(): int
    {
        return array_sum(array_map(static fn (array $file): int => count($file['tracks']), $this->files));
    }

    /**
     * @param  list<array{name: string, tracks: list<array{number: int, title: string|null, performer: string|null, isrc: string|null, start: float|null}>}>  $files
     */
    private static function valid(?string $album, ?string $albumArtist, ?string $barcode, ?int $discNumber, array $files): ?self
    {
        $kept = [];
        $count = 0;
        foreach ($files as $file) {
            if ($file['tracks'] === []) {
                continue;
            }
            $tracks = [];
            $previous = null;
            foreach ($file['tracks'] as $track) {
                $start = $track['start'];
                if ($start === null || ($previous !== null && $start <= $previous)) {
                    return null;
                }
                $previous = $start;
                $tracks[] = ['number' => $track['number'], 'title' => $track['title'], 'performer' => $track['performer'], 'isrc' => $track['isrc'], 'start' => $start];
            }
            $count += count($tracks);
            $kept[] = ['name' => $file['name'], 'tracks' => $tracks];
        }

        return $count < 1 || $count > 99 ? null : new self($album, $albumArtist, $barcode, $discNumber, $kept);
    }

    /** A FILE line's name: the quoted text, else everything but the trailing file type. */
    private static function fileName(string $rest): string
    {
        if (preg_match('/^"(.*)"/u', $rest, $quoted) === 1) {
            return trim($quoted[1]);
        }

        $name = trim((string) preg_replace('/\s+\S+$/u', '', $rest));

        return $name === '' ? $rest : $name;
    }

    private static function value(string $rest): ?string
    {
        if (preg_match('/^"(.*)"$/u', $rest, $quoted) === 1) {
            $rest = $quoted[1];
        }
        $rest = trim($rest);

        return $rest === '' ? null : $rest;
    }
}
