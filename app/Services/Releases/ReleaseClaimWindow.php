<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

/**
 * The one claim timeout shared by every claim stored on the `releases` row.
 *
 * Additional post-processing (`additional_pp_claimed_at`), NZB creation
 * (`nzb_creation_claimed_at`) and the recovery lease (`recovery_claimed_at`) all
 * stamp a "claimed at" time, and a claim is free once that time is NULL or
 * strictly older than {@see self::staleBefore()}. Freshness checks elsewhere read
 * the same cut-off, so a crashed worker stops shielding a release at the same
 * moment for every reader.
 *
 * The column checks exist because hand-built test fixtures leave claim columns
 * out; they are remembered per connection so hot candidate queries do not ask the
 * database about `releases` on every call.
 */
final class ReleaseClaimWindow
{
    /** @var list<string>|null Lowercased `releases` column names. */
    private static ?array $releasesColumns = null;

    private static ?PDO $releasesColumnsPdo = null;

    private static ?string $releasesColumnsDatabase = null;

    public static function ttlSeconds(): int
    {
        $timeout = (int) (Settings::settingValue('releaseprocessingtimeout') ?: 120);

        return max(300, $timeout * 2);
    }

    public static function staleBefore(): Carbon
    {
        return now()->subSeconds(self::ttlSeconds());
    }

    /**
     * Keep only rows whose claim in `$column` is free: never taken, or stale.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function applyFree(Builder $query, string $column): Builder
    {
        $staleBefore = self::staleBefore();

        return $query->where(static function (Builder $claimQuery) use ($column, $staleBefore): void {
            $claimQuery
                ->whereNull($column)
                ->orWhere($column, '<', $staleBefore);
        });
    }

    public static function releasesHasColumn(string $column): bool
    {
        return in_array(strtolower($column), self::releasesColumns(), true);
    }

    /**
     * Discard the remembered `releases` column list. Only needed when the schema
     * changes inside a single process, such as within one test.
     */
    public static function flushColumnCache(): void
    {
        self::$releasesColumns = null;
        self::$releasesColumnsPdo = null;
        self::$releasesColumnsDatabase = null;
    }

    /**
     * @return list<string>
     */
    private static function releasesColumns(): array
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $database = $connection->getName().':'.$connection->getDatabaseName();

        if (self::$releasesColumns !== null && self::$releasesColumnsPdo === $pdo && self::$releasesColumnsDatabase === $database) {
            return self::$releasesColumns;
        }

        self::$releasesColumnsPdo = $pdo;
        self::$releasesColumnsDatabase = $database;

        // A missing table lists no columns.
        return self::$releasesColumns = array_values(array_map(
            strtolower(...),
            Schema::getColumnListing('releases'),
        ));
    }
}
