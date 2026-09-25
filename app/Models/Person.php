<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A cast member, identified by TMDB person id when known.
 *
 * @property int $id
 * @property string $name
 * @property int|null $tmdb_id
 */
class Person extends Model
{
    public const int NAME_LENGTH = 120;

    /**
     * @var string
     */
    protected $table = 'people';

    /**
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * @var bool
     */
    public $timestamps = false;

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
            'tmdb_id' => 'integer',
        ];
    }
}
