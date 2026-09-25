<?php

declare(strict_types=1);

use App\Services\Releases\ReleaseDerivedFacts;
use Illuminate\Database\Migrations\Migration;

/**
 * Refills `release_tv_episodes` for every TV release with a show, now that the name
 * parser also reads separated (`S01.E06`), four-digit-season, fansub (`S3 - 13`),
 * `COMBINED` and bare-season names.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(ReleaseDerivedFacts::class)->fillTvEpisodes();
    }

    public function down(): void
    {
        // The earlier rows are not kept; the table holds what the current parser reads.
    }
};
