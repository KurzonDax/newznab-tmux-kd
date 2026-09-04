<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validation for the backfill tunables on the admin site-edit form.
 *
 * The safe backfill date is the one field on this form whose stored text is parsed with a
 * strict format on the read side. The backfill pass cannot reinterpret a typo -- the coded
 * default is the earliest stop date there is, so substituting it would schedule maximal
 * backfill -- so it fails closed and warns, and every pass stays a no-op until an operator
 * notices. Rejecting the bad value here keeps that state hard to reach in the first place.
 *
 * A blank field is left alone: the service reads it as "unset" and resolves it to its coded
 * default. The rest of the form is free-text by long-standing design.
 */
final class BackfillSettingRules
{
    /**
     * Setting name => human label, in the order they appear on the form.
     *
     * @var array<string, string>
     */
    public const array FIELDS = [
        'safebackfilldate' => 'safe backfill date',
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'safebackfilldate' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
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
