<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

/**
 * The attributes every `<file>` in a stored NZB shares.
 *
 * The writer stamps poster, date and groups from the collection row, once per file, so they are
 * collection-wide facts rather than per-file ones. A file the header re-scan recovers has to
 * carry the same ones to sit alongside its siblings -- in particular the date, which is the
 * collection's single date and not the article's own.
 */
final readonly class NzbFileEnvelope
{
    /**
     * @param  list<string>  $groups
     */
    public function __construct(
        public string $poster,
        public string $date,
        public array $groups,
    ) {}
}
