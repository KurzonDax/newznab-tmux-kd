<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * A group of settings that save together.
 *
 * The card is the unit of the save path: one form, one Save button, one request carrying that
 * card's fields and no others. Grouping this way is what lets the hub validate a payload
 * against the registry alone -- if a posted key is not one of this card's fields, nothing about
 * the request is trustworthy and none of it is written.
 */
final readonly class SettingCard
{
    /**
     * @param  list<SettingDefinition>  $settings
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public array $settings,
        public ?string $icon = null,
    ) {}

    public function definition(string $key): ?SettingDefinition
    {
        foreach ($this->settings as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Every form field this card accepts, including the unit selects that ride alongside
     * size fields.
     *
     * @return list<string>
     */
    public function formFields(): array
    {
        $fields = [];

        foreach ($this->settings as $definition) {
            foreach ($definition->formFields() as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function validationRules(): array
    {
        $rules = [];

        foreach ($this->settings as $definition) {
            $rules += $definition->validationRules();
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        $attributes = [];

        foreach ($this->settings as $definition) {
            $attributes += $definition->validationAttributes();
        }

        return $attributes;
    }
}
