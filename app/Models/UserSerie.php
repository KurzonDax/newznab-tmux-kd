<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\UserSerie.
 *
 * @property int $id
 * @property int $users_id
 * @property int $videos_id FK to videos.id
 * @property string|null $categories List of categories for user tv shows
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie whereCategories($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie whereUsersId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie whereVideosId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie query()
 */
class UserSerie extends Model
{
    /**
     * @var array<string>
     */
    protected $guarded = [];

    protected $dateFormat = false;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'users_id');
    }
}
