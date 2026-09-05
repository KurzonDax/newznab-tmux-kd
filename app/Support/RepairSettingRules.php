<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validation for the repair and re-scan tunables on the admin site-edit form.
 *
 * These are the only site settings a recovery pass reads as a *budget*: a negative retry window
 * or probe ceiling does not merely misconfigure a screen, it makes the repair engine and the
 * header re-scan behave in ways their callers do not defend against. The rest of the form is
 * free-text by long-standing design, so this validates the fields it owns and nothing else.
 */
final class RepairSettingRules
{
    /**
     * Setting name => human label, in the order they appear on the form.
     *
     * @var array<string, string>
     */
    public const array FIELDS = [
        'repair_retry_after_hours' => 'repair retry window',
        'repair_floor_completion' => 'repair floor completion',
        'repair_stat_sample_per_file' => 'repair samples per file',
        'repair_max_stat_probes' => 'repair probe ceiling per release',
        'repair_limit' => 'repair releases per run',
        'rescan_limit' => 're-scan releases per run',
        'rescan_window_minutes' => 're-scan window',
        'rescan_max_articles_per_release' => 're-scan article ceiling per release',
        'rescan_max_articles_per_run' => 're-scan article ceiling per run',
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (array_keys(self::FIELDS) as $field) {
            $rules[$field] = ['sometimes', 'numeric', 'min:0'];
        }

        return $rules;
    }

    /**
     * The rules for one field, for callers that describe a single setting rather than a form.
     *
     * @return list<string>
     */
    public static function rulesFor(string $field): array
    {
        return self::rules()[$field] ?? [];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return self::FIELDS;
    }
}
