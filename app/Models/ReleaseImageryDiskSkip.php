<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImagerySkipArtifact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The Imagery disk skip ledger (see CONTEXT.md and ADR 0013): one row per
 * release whose sample/preview imagery the Free-disk guard suppressed, kept
 * until an operator requeues it with `releases:requeue-imagery-disk-skips`.
 *
 * A row means "suppressed", not "imagery existed" -- the guard runs before the
 * sample articles are fetched, so the requeued run is what decides whether the
 * release yields anything. Kept in its own table because `releases` is
 * deliberately slim, and rows cascade away with their release.
 *
 * @property int $id
 * @property int $releases_id FK to releases.id
 * @property string $suppressed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Release|null $release
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseImageryDiskSkip newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseImageryDiskSkip newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseImageryDiskSkip query()
 */
class ReleaseImageryDiskSkip extends Model
{
    /**
     * @var string
     */
    protected $table = 'release_imagery_disk_skips';

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
            'releases_id' => 'integer',
        ];
    }

    /**
     * The artifacts this skip suppressed, ignoring tokens this build does not
     * recognise.
     *
     * @return list<ImagerySkipArtifact>
     */
    public function artifacts(): array
    {
        return ImagerySkipArtifact::fromList((string) $this->suppressed);
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
    }
}
