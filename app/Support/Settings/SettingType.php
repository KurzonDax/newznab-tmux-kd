<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Support\SizeUnit;
use Illuminate\Validation\Rule;

/**
 * The control a registry entry renders as, and the shape its posted value takes.
 *
 * The type decides three things at once: which Blade partial draws the field, which form
 * fields the card is allowed to receive for it, and what the value has to look like before
 * it reaches the settings table. Keeping those together is what lets a card be validated
 * and saved from its registry entries alone.
 */
enum SettingType: string
{
    /** Yes/no stored as 1/0. Labels are overridable through the definition's options. */
    case Bool = 'bool';

    /** Whole number, optionally with a static unit suffix beside the input. */
    case Int = 'int';

    /** Byte count edited as a value plus a MB/GB unit select. */
    case Size = 'size';

    /** Calendar date stored as Y-m-d. */
    case Date = 'date';

    /** One of a fixed set of stored values. */
    case Enum = 'enum';

    /** Single-line free text. */
    case Text = 'text';

    /** Multi-line free text. */
    case Textarea = 'textarea';

    /** Many-of-N stored as a comma-separated list. */
    case CheckboxSet = 'checkbox_set';

    /**
     * Per-root-category flags. These do not live in the settings table at all -- they are
     * columns on `root_categories` -- so the writer routes them instead of upserting them.
     */
    case RootToggles = 'root_toggles';

    /**
     * Whether values of this type are stored on `root_categories` rather than in `settings`.
     */
    public function isRootToggle(): bool
    {
        return $this === self::RootToggles;
    }

    /**
     * Whether the control needs the full width of a card rather than half of a two-up grid.
     */
    public function spansFullWidth(): bool
    {
        return in_array($this, [self::Textarea, self::CheckboxSet, self::RootToggles], true);
    }

    /**
     * The rules applied when a definition does not state its own.
     *
     * @return list<string>
     */
    public function defaultRules(): array
    {
        return match ($this) {
            self::Bool => ['required', 'in:0,1'],
            self::Int => ['required', 'integer'],
            self::Size => ['required', 'numeric', 'min:0'],
            self::Date => ['nullable', 'date_format:Y-m-d'],
            self::Enum => ['required'],
            self::Text => ['present', 'nullable', 'string'],
            self::Textarea => ['present', 'nullable', 'string'],
            self::CheckboxSet, self::RootToggles => ['nullable', 'array'],
        };
    }

    /**
     * Whether a control of this type posts nothing at all when nothing is selected.
     *
     * HTML omits unchecked boxes entirely, so for the checkbox-shaped controls an absent key
     * is a legal value meaning "none". Every other control always posts, and an absent key
     * there means the payload is not the form that was rendered.
     */
    public function postsNothingWhenEmpty(): bool
    {
        return in_array($this, [self::CheckboxSet, self::RootToggles], true);
    }

    /**
     * Rules the type enforces whatever the definition declares, so a stored value can never
     * be something the reader does not accept.
     *
     * @param  array<array-key, string>  $options
     * @return array<string, list<mixed>>
     */
    public function guardRules(string $key, array $options): array
    {
        return match ($this) {
            self::Bool, self::Enum => [$key => [Rule::in(array_map('strval', array_keys($options)))]],
            self::Size => [$key.'_unit' => ['required', Rule::in(SizeUnit::UNITS)]],
            self::CheckboxSet => [$key.'.*' => [Rule::in(array_map('strval', array_keys($options)))]],
            self::RootToggles => [$key.'.*' => ['in:1']],
            default => [],
        };
    }
}
