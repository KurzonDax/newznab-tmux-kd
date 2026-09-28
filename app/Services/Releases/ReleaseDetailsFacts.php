<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\MovieReleaseRow;
use App\Data\TvReleaseRow;
use App\Models\Release;
use App\Support\ReleaseCompletion;

/**
 * The Overview facts grid of the redesigned details pages (TV SPEC 3.4, Movies SPEC 5C.2):
 * Category, Size, Files, Completion, Posted, Added, Grabs, Group, Poster, Password status.
 */
final class ReleaseDetailsFacts
{
    /** @return list<array{string, string}> label, value */
    public static function grid(Release $release, TvReleaseRow|MovieReleaseRow $row, string $category): array
    {
        $status = (int) $release->passwordstatus;

        return [
            ['Category', $category],
            ['Size', $row->size],
            ['Files', (string) $row->files],
            ['Completion', ReleaseCompletion::isMeasured($release->completion) ? ReleaseCompletion::percent($release->completion).'%' : 'Not measured'],
            ['Posted', self::when($release->postdate)],
            ['Added', self::when($release->adddate)],
            ['Grabs', (string) $row->grabs],
            ['Group', $row->group === '' ? '—' : $row->group],
            ['Poster', $row->uploader === '' ? '—' : $row->uploader],
            ['Password status', $status < 0 ? 'Not checked' : ($row->passworded ? 'Detected' : 'None detected')],
        ];
    }

    /** A date as the facts show it, in the user's time zone; an em dash when there is none. */
    public static function when(mixed $date): string
    {
        return $date === null || $date === '' ? '—' : userDate((string) $date, 'M j, Y, g:i A');
    }
}
