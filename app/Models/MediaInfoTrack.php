<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaInfoTrack extends Model
{
    protected $guarded = [];

    protected $hidden = ['diagnostic_raw', 'diagnostic_filtered', 'diagnostic_truncated'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_forced' => 'boolean',
            'frame_rate' => 'float',
            'diagnostic_raw' => 'array',
            'diagnostic_filtered' => 'boolean',
            'diagnostic_truncated' => 'boolean',
        ];
    }
}
