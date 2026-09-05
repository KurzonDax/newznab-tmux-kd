<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Http\Controllers\Admin\AdminContentController;
use App\Models\RootCategory;
use App\Models\Settings;
use App\Support\Settings\SettingCard;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingType;
use App\Support\SizeUnit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validates and stores one settings card.
 *
 * Two rules make this safe to point at a browser form. First, the payload must contain only
 * fields the card owns: an unknown or cross-card key means the request does not correspond to
 * the form that was rendered, so the whole thing is rejected and nothing is written -- the same
 * payload-integrity stance {@see AdminContentController::reorder()}
 * takes. Second, values are rejected rather than clamped. The code-side floors are still there
 * as the safety net, but a form that silently substituted a legal value would be lying to the
 * admin about what the engine is going to do.
 */
class SettingsCardUpdater
{
    /**
     * Request keys that ride along with every form post and are never settings.
     *
     * @var list<string>
     */
    private const array ENVELOPE_FIELDS = ['_token', '_method'];

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(SettingCard $card, array $input): void
    {
        $payload = Arr::except($input, self::ENVELOPE_FIELDS);

        $this->rejectForeignKeys($card, $payload);

        $validator = Validator::make($payload, $card->validationRules());
        $validator->setAttributeNames($card->validationAttributes());
        $validator->validate();

        DB::transaction(function () use ($card, $payload): void {
            $values = [];

            foreach ($card->settings as $definition) {
                if ($definition->type->isRootToggle()) {
                    $this->applyRootToggles($definition, $payload);

                    continue;
                }

                // Checkbox-shaped controls post nothing when nothing is selected, so their
                // absence is a legal "none"; any other absent key is not a value at all.
                if (! $definition->type->postsNothingWhenEmpty() && ! array_key_exists($definition->key, $payload)) {
                    continue;
                }

                $values[$definition->key] = $this->storedValue($definition, $payload);
            }

            Settings::settingsUpsert($values);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    private function rejectForeignKeys(SettingCard $card, array $payload): void
    {
        $foreign = array_values(array_diff(array_keys($payload), $card->formFields()));

        if ($foreign === []) {
            return;
        }

        sort($foreign);

        throw ValidationException::withMessages([
            'card' => sprintf(
                'Nothing was saved: %s does not accept %s.',
                $card->title,
                implode(', ', $foreign),
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storedValue(SettingDefinition $definition, array $payload): string
    {
        $value = $payload[$definition->key] ?? null;

        return match ($definition->type) {
            SettingType::Size => (string) SizeUnit::toBytes(
                is_scalar($value) ? $value : null,
                is_string($payload[$definition->key.'_unit'] ?? null) ? $payload[$definition->key.'_unit'] : 'MB',
            ),
            SettingType::CheckboxSet => implode(',', array_map('strval', array_values((array) ($value ?? [])))),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    /**
     * Route a per-root toggle set to `root_categories`.
     *
     * HTML omits unchecked boxes, so an absent root means "off". Roots the definition does not
     * declare eligible are never flipped, whatever the browser posted -- the form never offered
     * them, so a value for one did not come from the form.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyRootToggles(SettingDefinition $definition, array $payload): void
    {
        $posted = (array) ($payload[$definition->key] ?? []);
        $column = $definition->key;

        foreach (RootCategory::query()->get() as $rootCategory) {
            $rootId = (int) $rootCategory->id;

            if ($definition->eligibleRootIds !== null && ! in_array($rootId, $definition->eligibleRootIds, true)) {
                continue;
            }

            $enabled = ! empty($posted[$rootId]);

            if ($rootCategory->{$column} !== $enabled) {
                $rootCategory->update([$column => $enabled]);
            }
        }
    }
}
