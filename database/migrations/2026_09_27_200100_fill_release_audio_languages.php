<?php

declare(strict_types=1);

use App\Services\Releases\ReleaseDerivedFacts;
use Illuminate\Database\Migrations\Migration;

/**
 * Writes the audio languages of every release with media info through the same step as
 * ReleaseDerivedFacts::refresh(). Releases are read in primary-key chunks and a release
 * whose rows already match is left alone, so a failed run can be re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(ReleaseDerivedFacts::class)->fillAudioLanguages();
    }

    public function down(): void
    {
        // The rows stay: they match the media info, and the storage migration's down() drops them.
    }
};
