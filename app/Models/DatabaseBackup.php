<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DatabaseBackup extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'offsite_at' => 'datetime',
        ];
    }
}
