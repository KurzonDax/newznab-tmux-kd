<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaInfoProbe extends Model
{
    protected $guarded = [];

    protected $hidden = ['diagnostic_raw', 'diagnostic_filtered', 'diagnostic_truncated'];

    protected function casts(): array
    {
        return [
            'captured_at' => 'immutable_datetime',
            'music_tags' => 'array',
            'diagnostic_raw' => 'array',
            'diagnostic_filtered' => 'boolean',
            'diagnostic_truncated' => 'boolean',
        ];
    }

    /** @return HasMany<MediaInfoTrack, $this> */
    public function tracks(): HasMany
    {
        return $this->hasMany(MediaInfoTrack::class);
    }
}
