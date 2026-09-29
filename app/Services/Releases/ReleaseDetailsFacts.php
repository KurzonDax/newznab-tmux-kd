<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\AdultReleaseRow;
use App\Data\MovieReleaseRow;
use App\Data\TvReleaseRow;
use App\Models\Predb;
use App\Models\Release;
use App\Support\ReleaseCompletion;

/**
 * The Overview facts grid of the redesigned details pages (TV SPEC 3.4, Movies SPEC 5C.2, Adult
 * SPEC 5A.3): Category, Size, Files, Completion, Posted, Added, Grabs, Group, Poster, Password
 * status; and the PreDB block of the Movies and Adult pages.
 */
final class ReleaseDetailsFacts
{
    /** @return list<array{string, string}> label, value */
    public static function grid(Release $release, TvReleaseRow|MovieReleaseRow|AdultReleaseRow $row, string $category): array
    {
        $status = (int) $release->passwordstatus;

        return [
            ['Category', $category],
            ['Size', $row->size],
            // An Adult release with no stored file count reads "—", never 0 (Adult SPEC 5A.2).
            ['Files', $row instanceof AdultReleaseRow ? $row->filesShown() : (string) $row->files],
            ['Completion', ReleaseCompletion::isMeasured($release->completion) ? ReleaseCompletion::percent($release->completion).'%' : 'Not measured'],
            ['Posted', self::when($release->postdate)],
            ['Added', self::when($release->adddate)],
            ['Grabs', (string) $row->grabs],
            ['Group', $row->group === '' ? '—' : $row->group],
            ['Poster', $row->uploader === '' ? '—' : $row->uploader],
            ['Password status', $status < 0 ? 'Not checked' : ($row->passworded ? 'Detected' : 'None detected')],
        ];
    }

    /**
     * Today's PreDB fields, each when known: Title, Source, Pre date, Category.
     *
     * @return list<array{string, string}>
     */
    public static function predb(int $predbId): array
    {
        /** @var Predb|null $pre today's read (DetailsController) */
        $pre = $predbId > 0 ? Predb::getOne($predbId) : null;
        if ($pre === null) {
            return [];
        }

        return array_values(array_filter([
            ['Title', trim((string) $pre->title)],
            ['Source', trim((string) ($pre->source ?? ''))],
            ['Pre date', empty($pre->predate) ? '' : self::when($pre->predate)],
            ['Category', trim((string) ($pre->category ?? ''))],
        ], static fn (array $fact): bool => $fact[1] !== ''));
    }

    /** A date as the facts show it, in the user's time zone; an em dash when there is none. */
    public static function when(mixed $date): string
    {
        return $date === null || $date === '' ? '—' : userDate((string) $date, 'M j, Y, g:i A');
    }
}
