<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\TvInfo.
 *
 * @property int $videos_id FK to video.id
 * @property string $summary Description/summary of the show.
 * @property string $publisher The channel/network of production/release (ABC, BBC, Showtime, etc.).
 * @property string $localzone The linux tz style identifier
 * @property bool $image Does the video have a cover image?
 * @property bool $banner Does the video have a series banner?
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvInfo whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvInfo whereLocalzone($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvInfo wherePublisher($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvInfo whereSummary($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvInfo whereVideosId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvInfo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvInfo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvInfo query()
 */
class TvInfo extends Model
{
    /**
     * @var string
     */
    protected $table = 'tv_info';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @var bool
     */
    public $timestamps = false;

    protected $dateFormat = false;

    /**
     * @var string
     */
    protected $primaryKey = 'videos_id';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'image' => 'boolean',
            'banner' => 'boolean',
        ];
    }

    public static function markImageAvailable(int $videoId): bool
    {
        return self::markArtworkAvailable($videoId, 'image');
    }

    public static function markBannerAvailable(int $videoId): bool
    {
        return self::markArtworkAvailable($videoId, 'banner');
    }

    private static function markArtworkAvailable(int $videoId, string $column): bool
    {
        $updated = self::query()
            ->where('videos_id', $videoId)
            ->where($column, false)
            ->update([$column => true]);

        if ($updated > 0) {
            Video::invalidateSeriesListCache();
        }

        return $updated > 0;
    }
}
