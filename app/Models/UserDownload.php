<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\UserDownload.
 *
 * @property int $id
 * @property int $users_id
 * @property string $hosthash
 * @property string $timestamp
 * @property int $releases_id FK to releases.id
 * @property int|null $count Computed count from aggregate queries
 * @property-read Release $release
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserDownload whereHosthash($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserDownload whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserDownload whereReleasesId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserDownload whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserDownload whereUsersId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserDownload newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserDownload newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserDownload query()
 */
class UserDownload extends Model
{
    protected $dateFormat = false;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'users_id');
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
    }

    /**
     * Get the COUNT of how many NZB's the user has downloaded in the past day.
     * Note: Old request cleanup is no longer done inline to avoid blocking API responses.
     * Use a scheduled command to periodically clean old records instead.
     *
     * @throws \Exception
     */
    public static function getDownloadRequests(int $userID): int
    {
        $value = self::whereUsersId($userID)->where('timestamp', '>', now()->subDay())->count('id');

        return $value ?: 0;
    }

    /**
     * @return Collection<int, self>
     */
    public static function getDownloadRequestsForUser(mixed $userID)
    {
        return self::whereUsersId($userID)->with('release')->orderByDesc('timestamp')->get();
    }

    /**
     * If a user downloads a NZB, log it.
     *
     *
     * @return int|Builder
     */
    public static function addDownloadRequest(mixed $userID, mixed $releaseID) // @phpstan-ignore missingType.generics
    {
        return self::query()
            ->insertGetId(
                [
                    'users_id' => $userID,
                    'releases_id' => $releaseID,
                    'timestamp' => now(),
                ]
            );
    }

    /**
     * Log multiple NZB downloads for a user in a single insert.
     *
     * @param  list<int>  $releaseIds  Release IDs (releases.id)
     */
    public static function addDownloadRequestsBatch(int $userID, array $releaseIds): void
    {
        if ($releaseIds === []) {
            return;
        }
        $now = now();
        $rows = array_map(
            fn (int $releaseId): array => [
                'users_id' => $userID,
                'releases_id' => $releaseId,
                'timestamp' => $now,
            ],
            $releaseIds
        );
        self::query()->insert($rows);
    }
}
