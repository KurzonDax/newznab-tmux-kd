<?php

declare(strict_types=1);

namespace Tests\Support\Settings;

use App\Services\Releases\DynamicPreviewBudgetPolicy;
use App\Support\Settings\PipelineStage;
use App\Support\Settings\SettingCard;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingSection;
use App\Support\Settings\SettingsSectionProvider;
use App\Support\Settings\SettingType;

/**
 * A two-card fixture section exercising every control type the registry supports.
 *
 * The plumbing tests bind this instead of the real pages so they keep testing the loader,
 * the validator, and the writer rather than whatever the seven pages happen to declare today.
 */
final class FixtureSettingsSections implements SettingsSectionProvider
{
    public static function section(): SettingSection
    {
        return new SettingSection(
            id: 'fixture',
            title: 'Fixture',
            description: 'Every control type, once.',
            icon: 'fas fa-flask',
            stage: PipelineStage::Ingest,
            cards: [
                new SettingCard(
                    id: 'scalars',
                    title: 'Scalars',
                    description: 'One of each simple control.',
                    settings: [
                        SettingDefinition::bool('fixture_switch', 'Fixture switch', 'A yes/no switch.'),
                        new SettingDefinition(
                            key: 'fixture_threads',
                            label: 'Fixture threads',
                            help: 'A bounded whole number.',
                            type: SettingType::Int,
                            unit: 'threads',
                            rules: ['required', 'integer', 'min:1', 'max:16'],
                        ),
                        new SettingDefinition(
                            key: 'fixture_size',
                            label: 'Fixture size',
                            help: 'A byte count edited in MB or GB.',
                            type: SettingType::Size,
                        ),
                        new SettingDefinition(
                            key: 'fixture_date',
                            label: 'Fixture date',
                            help: 'A calendar date.',
                            type: SettingType::Date,
                        ),
                        new SettingDefinition(
                            key: 'fixture_mode',
                            label: 'Fixture mode',
                            help: 'One of a fixed set.',
                            type: SettingType::Enum,
                            options: [0 => 'Disabled', 1 => 'Enabled', 2 => 'Paused'],
                        ),
                        new SettingDefinition(
                            key: 'fixture_text',
                            label: 'Fixture text',
                            help: 'Free text nobody parses.',
                            type: SettingType::Text,
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'collections',
                    title: 'Collections',
                    description: 'The many-of-N controls.',
                    settings: [
                        new SettingDefinition(
                            key: 'fixture_classes',
                            label: 'Fixture classes',
                            help: 'A comma-separated list of tokens.',
                            type: SettingType::CheckboxSet,
                            options: ['alpha' => 'Alpha', 'beta' => 'Beta', 'gamma' => 'Gamma'],
                        ),
                        new SettingDefinition(
                            key: 'discard_executables',
                            label: 'Discard executables',
                            help: 'Per-root flags stored on root_categories.',
                            type: SettingType::RootToggles,
                        ),
                        new SettingDefinition(
                            key: 'dynamic_preview_budget',
                            label: 'Dynamic preview budget',
                            help: 'Per-root flags limited to the eligible roots.',
                            type: SettingType::RootToggles,
                            eligibleRootIds: DynamicPreviewBudgetPolicy::ELIGIBLE_ROOT_IDS,
                        ),
                    ],
                ),
            ],
        );
    }
}
