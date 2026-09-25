<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A broadcaster or streaming service a show airs on.
 *
 * @property int $id
 * @property string $name The spelling first stored; matched case-insensitively after trimming.
 */
class Network extends Model
{
    public const int NAME_LENGTH = 80;

    /**
     * @var string
     */
    protected $table = 'networks';

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
     * The id of the network with this name, created if missing. Names are matched in PHP on
     * their trimmed, lower-cased form; the unique key only guards against duplicates.
     */
    public static function idForName(string $name): ?int
    {
        $name = mb_substr(trim($name), 0, self::NAME_LENGTH);
        if ($name === '') {
            return null;
        }

        $key = mb_strtolower($name);
        foreach (self::query()->orderBy('id')->pluck('name', 'id') as $id => $existing) {
            if (mb_strtolower(trim((string) $existing)) === $key) {
                return (int) $id;
            }
        }

        self::query()->insertOrIgnore(['name' => $name]);

        return (int) self::query()->where('name', $name)->value('id');
    }
}
