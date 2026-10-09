<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\Sidecars;

/**
 * The MusicBrainz disc IDs of a CD rip log (issue #313, section C). A rip log is one with a "TOC of
 * the extracted CD" section, which EAC and XLD both print; any other log (a Lossless Audio Checker
 * report, say) has none. Each TOC's rows (track, start, length, start sector, end sector) give the
 * audio tracks: a row starting at least 11,400 sectors after the previous row's end is a data
 * session, dropped with every later row. The disc ID follows musicbrainz.org/doc/Disc_ID_Calculation.
 */
final class RipLog
{
    private const int LEAD_IN_SECTORS = 150;

    private const int DATA_SESSION_GAP_SECTORS = 11_400;

    /**
     * The log's distinct disc IDs, one per TOC, in log order.
     *
     * @return list<string>
     */
    public static function discIds(string $text): array
    {
        $ids = [];
        foreach (self::tocs($text) as $rows) {
            $id = self::discId($rows);
            if ($id !== null && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return list<list<array{track: int, start: int, end: int}>>
     */
    private static function tocs(string $text): array
    {
        $tocs = [];
        $rows = null;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (stripos($line, 'TOC of the extracted CD') !== false) {
                if ($rows !== null && $rows !== []) {
                    $tocs[] = $rows;
                }
                $rows = [];

                continue;
            }
            if ($rows === null) {
                continue;
            }
            if (preg_match('/^\s*(\d{1,2})\s*\|\s*[\d:.]+\s*\|\s*[\d:.]+\s*\|\s*(\d+)\s*\|\s*(\d+)\s*$/', $line, $row) === 1) {
                $rows[] = ['track' => (int) $row[1], 'start' => (int) $row[2], 'end' => (int) $row[3]];

                continue;
            }
            // The table ends at the first other line after its rows; headers and rules come before them.
            if ($rows !== [] && trim($line) !== '') {
                $tocs[] = $rows;
                $rows = null;
            }
        }
        if ($rows !== null && $rows !== []) {
            $tocs[] = $rows;
        }

        return $tocs;
    }

    /** @param list<array{track: int, start: int, end: int}> $rows */
    private static function discId(array $rows): ?string
    {
        $audio = [];
        $leadOut = null;
        foreach ($rows as $row) {
            $previous = $audio === [] ? null : $audio[array_key_last($audio)];
            if ($previous !== null && $row['start'] - $previous['end'] >= self::DATA_SESSION_GAP_SECTORS) {
                $leadOut = $row['start'] + self::LEAD_IN_SECTORS - self::DATA_SESSION_GAP_SECTORS;
                break;
            }
            $audio[] = $row;
        }
        if ($audio === []) {
            return null;
        }

        $first = $audio[0]['track'];
        $last = $audio[array_key_last($audio)]['track'];
        $leadOut ??= $audio[array_key_last($audio)]['end'] + 1 + self::LEAD_IN_SECTORS;
        if ($first < 1 || $last > 99 || $first > $last) {
            return null;
        }
        $offsets = array_fill(1, 99, 0);
        foreach ($audio as $row) {
            $offsets[$row['track']] = $row['start'] + self::LEAD_IN_SECTORS;
        }

        $toc = sprintf('%02X%02X%08X', $first, $last, $leadOut);
        foreach ($offsets as $offset) {
            $toc .= sprintf('%08X', $offset);
        }

        return strtr(base64_encode(sha1($toc, true)), ['+' => '.', '/' => '_', '=' => '-']);
    }
}
