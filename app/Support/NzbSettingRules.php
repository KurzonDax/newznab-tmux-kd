<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Nzb\NzbService;

/**
 * Validation for the NZB storage tunables on the settings hub.
 *
 * The storage depth names a directory level rather than a display preference: 0 is the
 * legal "store flat" depth, and the write path fans out at most NzbService::MAX_SPLIT_LEVEL
 * GUID characters, so a value outside that range cannot address a path any release was
 * ever written to. A blank value is left alone here -- the service reads it as "unset"
 * and resolves it to its coded default.
 */
final class NzbSettingRules
{
    /**
     * Setting name => human label, in the order they appear on the form.
     *
     * @var array<string, string>
     */
    public const array FIELDS = [
        'nzbsplitlevel' => 'NZB storage depth',
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'nzbsplitlevel' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.NzbService::MAX_SPLIT_LEVEL],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return self::FIELDS;
    }
}
