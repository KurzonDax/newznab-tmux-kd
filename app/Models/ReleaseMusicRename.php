<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\MusicIdentity\Enums\MusicRenameDeclineReason;
use App\Services\MusicIdentity\Enums\MusicRenameOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One canonical-rename outcome for one accepted album decision (issue #309).
 *
 * @property int $id
 * @property int $releases_id
 * @property int $release_music_identification_id
 * @property MusicRenameOutcome $outcome
 * @property MusicRenameDeclineReason|null $reason why the rename gate declined the decision
 * @property array<string, scalar|null>|null $before the prior search name and source, and the prior value of every other field the rename changed
 * @property array<string, scalar|null>|null $after the value the rename wrote to each of those fields
 * @property list<string>|null $restored the fields a reversal restored
 * @property Carbon|null $applied_at
 * @property Carbon|null $reverted_at
 * @property-read ReleaseMusicIdentification|null $identification
 *
 * @mixin \Eloquent
 */
class ReleaseMusicRename extends Model
{
    /** @var array<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'releases_id' => 'integer',
            'release_music_identification_id' => 'integer',
            'outcome' => MusicRenameOutcome::class,
            'reason' => MusicRenameDeclineReason::class,
            'before' => 'array',
            'after' => 'array',
            'restored' => 'array',
            'applied_at' => 'datetime',
            'reverted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ReleaseMusicIdentification, $this> */
    public function identification(): BelongsTo
    {
        return $this->belongsTo(ReleaseMusicIdentification::class, 'release_music_identification_id');
    }
}
