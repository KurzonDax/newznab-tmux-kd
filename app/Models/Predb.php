<?php

declare(strict_types=1);

namespace App\Models;

use App\Facades\Search;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;

/**
 * App\Models\Predb.
 *
 * @property mixed $release
 * @property mixed $hash
 * @property int $id Primary key
 * @property string $title
 * @property string|null $nfo
 * @property string|null $size
 * @property string|null $category
 * @property string|null $predate
 * @property string $source
 * @property int $requestid
 * @property int $groups_id FK to groups
 * @property bool $nuked Is this pre nuked? 0 no 2 yes 1 un nuked 3 mod nuked
 * @property string|null $nukereason If this pre is nuked, what is the reason?
 * @property string|null $files How many files does this pre have ?
 * @property string $filename
 * @property int $searched
 * @property string|null $next_predb_search_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb whereCategory($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb whereFilename($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb whereFiles($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb whereGroupsId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb whereNfo($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb whereNuked($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb whereNukereason($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb wherePredate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb whereRequestid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb whereSearched($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb whereTitle($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Predb query()
 */
class Predb extends Model
{
    // Nuke status.
    public const PRE_NONUKE = 0; // Pre is not nuked.

    public const PRE_UNNUKED = 1; // Pre was un nuked.

    public const PRE_NUKED = 2; // Pre is nuked.

    public const PRE_MODNUKE = 3; // Nuke reason was modified.

    public const PRE_RENUKED = 4; // Pre was re nuked.

    public const PRE_OLDNUKE = 5; // Pre is nuked for being old.

    /**
     * @var string
     */
    protected $table = 'predb';

    /**
     * @var bool
     */
    public $timestamps = false;

    protected $dateFormat = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @return HasMany<PredbHash, $this>
     */
    public function hash(): HasMany
    {
        return $this->hasMany(PredbHash::class, 'predb_id');
    }

    /**
     * @return HasMany<Release, $this>
     */
    public function release(): HasMany
    {
        return $this->hasMany(Release::class, 'predb_id');
    }

    /**
     * Attempts to match PreDB titles to releases.
     *
     *
     * @throws \RuntimeException
     */
    public static function checkPre(bool|int|string $dateLimit = false): void
    {
        $updated = 0;

        if (config('nntmux.echocli')) {
            cli()->header('Querying DB for release search names not matched with PreDB titles.');
        }

        $query = self::query()
            ->where('releases.predb_id', '<', 1)
            ->join('releases', 'predb.title', '=', 'releases.searchname')
            ->select(['predb.id as predb_id', 'releases.id as releases_id']);
        if ($dateLimit !== false && (int) $dateLimit > 0) {
            $query->where('adddate', '>', now()->subDays((int) $dateLimit));
        }

        $res = $query->get();

        if ($res !== null) {
            $total = \count($res);
            cli()->primary(number_format($total).' releases to match.');
            $releaseUpdates = app(ReleaseUpdateService::class);

            foreach ($res as $row) {
                $releaseUpdates->attachPredbId(
                    (int) $row['releases_id'],
                    (int) $row['predb_id'],
                );

                if (config('nntmux.echocli')) {
                    cli()->overWritePrimary(
                        'Matching up preDB titles with release searchnames: '.cli()->percentString(++$updated, $total)
                    );
                }
            }
            if (config('nntmux.echocli')) {
                echo PHP_EOL;
            }

            if (config('nntmux.echocli')) {
                cli()->header(
                    'Matched '.number_format(($updated > 0) ? $updated : 0).' PreDB titles to release search names.'
                );
            }
        }
    }

    /**
     * Try to match a single release to a PreDB title when the release is created.
     *
     * @return array<string, mixed>|false Array with title/id from PreDB if found, false if not found.
     */
    public static function matchPre(string $cleanerName)
    {
        if (empty($cleanerName)) {
            return false;
        }

        $titleCheck = self::query()->where('title', $cleanerName)->first(['id']);

        if ($titleCheck !== null) {
            return [
                'title' => $cleanerName,
                'predb_id' => $titleCheck['id'],
            ];
        }

        // Check if clean name matches a PreDB filename.
        $fileCheck = self::query()->where('filename', $cleanerName)->first(['id', 'title']);

        if ($fileCheck !== null) {
            return [
                'title' => $fileCheck['title'],
                'predb_id' => $fileCheck['id'],
            ];
        }

        return false;
    }

    /**
     * @return mixed
     *
     * @throws \Exception
     */
    public static function getAll(string $search = '')
    {
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));
        $cacheKey = self::listingCacheKey($search, Paginator::resolveCurrentPage());
        $predb = Cache::get($cacheKey);
        if ($predb !== null) {
            return $predb;
        }
        $sql = self::query()
            ->leftJoin('releases', 'releases.predb_id', '=', 'predb.id')
            ->select('predb.*', 'releases.guid')
            ->orderByDesc('predb.predate');
        if (! empty($search)) {
            $ids = Search::searchPredb($search);
            $sql->whereIn('predb.id', $ids);
        }

        $predb = $sql->paginate(config('nntmux.items_per_page'));
        $predb->withPath(url('admin/predb'));
        Cache::put($cacheKey, $predb, $expiresAt);

        return $predb;
    }

    /**
     * Cache key for one page of the PreDB listing.
     *
     * The page number is part of the key because a paginator resolves its page at
     * construction: keyed on the search term alone, the first page fetched is replayed for
     * every later one until the entry expires. The prefix keeps the entry out of the global
     * keyspace, where a bare md5() of the search term collides with any other caller that
     * hashes the same string.
     */
    private static function listingCacheKey(string $search, int $page): string
    {
        return 'predb.listing.'.md5($search).'.page.'.$page;
    }

    /**
     * Get all PRE's for a release.
     */
    public static function getForRelease(mixed $preID): mixed
    {
        return self::query()->where('id', $preID)->get();
    }

    /**
     * Return a single PRE for a release.
     *
     *
     * @return Model|null|static
     */
    public static function getOne(mixed $preID)
    {
        return self::query()->where('id', $preID)->first();
    }
}
