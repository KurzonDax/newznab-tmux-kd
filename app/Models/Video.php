<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * App\Models\Video.
 *
 * @property int $id Show ID to be used in other tables as reference
 * @property bool $type 0 = TV, 1 = Film, 2 = Anime
 * @property string $title Name of the video.
 * @property string $countries_id Two character country code (FK to countries table).
 * @property string $started Date (UTC) of production's first airing.
 * @property int $anidb ID number for anidb site
 * @property string $imdb ID number for IMDB site (without the 'tt' prefix).
 * @property int $tmdb ID number for TMDB site.
 * @property int $trakt ID number for TraktTV site.
 * @property int $tvdb ID number for TVDB site
 * @property int $tvmaze ID number for TVMaze site.
 * @property int $tvrage ID number for TVRage site.
 * @property bool $source Which site did we use for info?
 * @property-read Collection|VideoAlias[] $alias
 * @property-read Collection|TvEpisode[] $episode
 * @property-read Collection|Release[] $release
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereAnidb($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereCountriesId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereImdb($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereStarted($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereTmdb($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereTrakt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereTvdb($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereTvmaze($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereTvrage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereType($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video query()
 */
class Video extends Model
{
    protected $dateFormat = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return HasMany<VideoAlias, $this>
     */
    public function alias(): HasMany
    {
        return $this->hasMany(VideoAlias::class, 'videos_id');
    }

    /**
     * @return HasMany<Release, $this>
     */
    public function release(): HasMany
    {
        return $this->hasMany(Release::class, 'videos_id');
    }

    /**
     * @return HasMany<TvEpisode, $this>
     */
    public function episode(): HasMany
    {
        return $this->hasMany(TvEpisode::class, 'videos_id');
    }

    /**
     * @return HasOne<TvInfo, $this>
     */
    public function tvInfo(): HasOne
    {
        return $this->hasOne(TvInfo::class, 'videos_id');
    }

    /**
     * Get info from tables for the provided ID.
     *
     *
     * @return Model|null|static
     */
    public static function getByVideoID(mixed $id)
    {
        return self::query()
            ->select(['videos.*', 'tv_info.summary', 'tv_info.publisher', 'tv_info.image', 'tv_info.banner'])
            ->where('videos.id', $id)
            ->join('tv_info', 'videos.id', '=', 'tv_info.videos_id')
            ->first();
    }

    /**
     * Retrieves a range of all shows for the show-edit admin list.
     */
    public static function getRange(string $showname = ''): LengthAwarePaginator // @phpstan-ignore missingType.generics
    {
        $sql = self::query()
            ->select(['videos.*', 'tv_info.summary', 'tv_info.publisher', 'tv_info.image'])
            ->join('tv_info', 'videos.id', '=', 'tv_info.videos_id');

        if ($showname !== '') {
            $sql->where('videos.title', 'like', '%'.$showname.'%');
        }

        return $sql->paginate(config('nntmux.items_per_page'));
    }

    /**
     * Returns a count of all shows -- usually used by pager.
     */
    public static function getCount(string $showname = ''): int
    {
        $res = self::query()->join('tv_info', 'videos.id', '=', 'tv_info.videos_id');

        if ($showname !== '') {
            $res->where('videos.title', 'like', '%'.$showname.'%');
        }

        return $res->count('videos.id');
    }
}
