<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `releases.is_imported` and fills it for the rows that predate it. Header formation
 * writes a `release_regexes` row for each release it creates and pending reconciliation a
 * `reconciled_postings` row with no `review_digest`; import writes neither. A posting whose
 * `review_digest` is set was attached by historical reconciliation to a release that already
 * existed, whatever created it, so it says nothing about the release's origin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->boolean('is_imported')
                ->default(false)
                ->after('is_trusted_name')
                ->comment('NZB import created this release');
        });

        DB::table('releases')
            ->whereNotExists(static function (Builder $query): void {
                $query->selectRaw('1')->from('release_regexes')
                    ->whereColumn('release_regexes.releases_id', 'releases.id');
            })
            ->whereNotExists(static function (Builder $query): void {
                $query->selectRaw('1')->from('reconciled_postings')
                    ->whereColumn('reconciled_postings.release_id', 'releases.id')
                    ->whereNull('reconciled_postings.review_digest');
            })
            ->update(['is_imported' => true]);
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropColumn('is_imported');
        });
    }
};
