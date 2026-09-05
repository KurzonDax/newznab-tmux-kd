<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * One admin-editable setting, described once and used everywhere.
 *
 * The registry is the only whitelist of legitimate setting keys, so a definition has to carry
 * everything three consumers need: the hub page that renders the control, the save path that
 * validates and stores the value, and the sidebar search that has to find the setting from a
 * fragment of its key or its help text.
 */
final readonly class SettingDefinition
{
    /**
     * @param  string  $key  Settings-table row name, or the form key for a per-root toggle set.
     * @param  array<array-key, string>  $options  Stored value => label, for the picker types.
     * @param  string|null  $unit  Static suffix drawn beside an integer input ("seconds", "%").
     * @param  list<mixed>  $rules  Validation rules replacing the type defaults; guard rules
     *                              derived from the type are always applied on top.
     * @param  list<int>|null  $eligibleRootIds  Root categories a toggle set may flip; null means
     *                                           every root.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $help,
        public SettingType $type,
        public array $options = [],
        public ?string $unit = null,
        public array $rules = [],
        public ?string $icon = null,
        public ?array $eligibleRootIds = null,
        public ?string $placeholder = null,
    ) {}

    /**
     * Convenience constructor for the commonest case: a yes/no switch.
     *
     * @param  array<array-key, string>|null  $options  Overrides the Yes/No labels where the
     *                                                  page reads better as Enabled/Disabled.
     */
    public static function bool(string $key, string $label, string $help, ?string $icon = null, ?array $options = null): self
    {
        return new self(
            key: $key,
            label: $label,
            help: $help,
            type: SettingType::Bool,
            options: $options ?? [1 => 'Yes', 0 => 'No'],
            icon: $icon,
        );
    }

    /**
     * Every form field this definition owns. A card accepts these and nothing else.
     *
     * @return list<string>
     */
    public function formFields(): array
    {
        return $this->type === SettingType::Size
            ? [$this->key, $this->key.'_unit']
            : [$this->key];
    }

    /**
     * Validation rules keyed by form field.
     *
     * @return array<string, list<mixed>>
     */
    public function validationRules(): array
    {
        $base = $this->rules !== [] ? $this->rules : $this->type->defaultRules();
        $rules = [$this->key => $base];

        foreach ($this->type->guardRules($this->key, $this->resolvedOptions()) as $field => $guard) {
            $rules[$field] = array_merge($rules[$field] ?? [], $guard);
        }

        return $rules;
    }

    /**
     * Human names for validation messages, keyed by form field.
     *
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        $attributes = [$this->key => $this->label];

        if ($this->type === SettingType::Size) {
            $attributes[$this->key.'_unit'] = $this->label.' unit';
        }

        return $attributes;
    }

    /**
     * Options with the bool default filled in, so callers never have to special-case it.
     *
     * @return array<array-key, string>
     */
    public function resolvedOptions(): array
    {
        if ($this->options !== []) {
            return $this->options;
        }

        return $this->type === SettingType::Bool ? [1 => 'Yes', 0 => 'No'] : [];
    }

    /**
     * The text the sidebar search matches against: key, label, help, and option labels.
     */
    public function searchHaystack(): string
    {
        return mb_strtolower(implode(' ', [
            $this->key,
            $this->label,
            $this->help,
            implode(' ', $this->resolvedOptions()),
        ]));
    }
}
