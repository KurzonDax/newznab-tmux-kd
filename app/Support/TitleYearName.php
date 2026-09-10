<?php

declare(strict_types=1);

namespace App\Support;

final class TitleYearName
{
    /** @return array{title: string, year: string}|null */
    public static function parse(string $name): ?array
    {
        if (preg_match('/(?:^|[^a-z0-9])(?:\d{4}[.\/-]\d{2}[.\/-]\d{2}|\d{2}[.\/-]\d{2}[.\/-]\d{4})(?:$|[^a-z0-9])/i', $name)) {
            return null;
        }

        if (! preg_match('/^(?<title>.+?)(?:[._ -](?<year>(?:19|20)\d{2})|\((?<bracket_year>(?:19|20)\d{2})\))(?:[._ -]\d{1,2})?(?:[._ -](?:mkv|mp4|avi))?$/iu', $name, $matches)) {
            return null;
        }

        $title = trim(str_replace(['.', '_', '-'], ' ', $matches['title']));
        if (! preg_match('/[\p{L}\p{N}]/u', $title)) {
            return null;
        }

        return [
            'title' => preg_replace('/\s+/u', ' ', $title) ?? $title,
            'year' => $matches['year'] !== '' ? $matches['year'] : $matches['bracket_year'],
        ];
    }
}
