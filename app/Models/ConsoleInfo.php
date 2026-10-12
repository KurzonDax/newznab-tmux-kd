<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\ConsoleInfo.
 *
 * @property int $id
 * @property string $title
 * @property string|null $asin
 * @property string|null $url
 * @property int|null $salesrank
 * @property string|null $platform
 * @property string|null $publisher
 * @property int|null $genres_id
 * @property string|null $esrb
 * @property string|null $releasedate
 * @property string|null $review
 * @property bool $cover
 * @property string|null $storyline
 * @property int|null $critic_score
 * @property int|null $user_score
 * @property string|null $website
 * @property Carbon|null $details_refreshed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereAsin($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereCover($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereEsrb($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereGenresId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo wherePlatform($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo wherePublisher($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereReleasedate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereReview($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereSalesrank($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereUrl($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo query()
 */
class ConsoleInfo extends Model
{
    /**
     * @var string
     */
    protected $table = 'consoleinfo';

    protected $dateFormat = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details_refreshed_at' => 'datetime',
            'critic_score' => 'integer',
            'user_score' => 'integer',
        ];
    }

    // ========================================
    // Relationships
    // ========================================

    /**
     * Get the genre for the console info.
     *
     * @return BelongsTo<Genre, $this>
     */
    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class, 'genres_id');
    }

    /**
     * Get the releases for the console info.
     *
     * @return HasMany<Release, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class, 'consoleinfo_id');
    }
}
