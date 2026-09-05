<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use App\Support\Settings\PipelineStage;
use App\Support\Settings\SettingLocation;
use App\Support\Settings\SettingsRegistry;
use App\Support\Settings\SettingType;
use PHPUnit\Framework\TestCase;
use Tests\Support\Settings\FixtureSettingsSections;

/**
 * The registry as a lookup table: sections, cards, the key whitelist, and search.
 */
class SettingsRegistryTest extends TestCase
{
    private SettingsRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new SettingsRegistry([FixtureSettingsSections::class]);
    }

    public function test_sections_are_keyed_by_slug_in_declaration_order(): void
    {
        $this->assertSame(['fixture'], array_keys($this->registry->sections()));
        $this->assertSame('fixture', $this->registry->defaultSectionId());
        $this->assertSame(PipelineStage::Ingest, $this->registry->section('fixture')?->stage);
    }

    public function test_an_unregistered_section_or_card_resolves_to_null(): void
    {
        $this->assertNull($this->registry->section('nope'));
        $this->assertNull($this->registry->card('fixture', 'nope'));
        $this->assertNull($this->registry->card('nope', 'scalars'));
        $this->assertNotNull($this->registry->card('fixture', 'scalars'));
    }

    public function test_the_key_whitelist_covers_every_declared_setting(): void
    {
        $this->assertSame([
            'fixture_switch',
            'fixture_threads',
            'fixture_size',
            'fixture_date',
            'fixture_mode',
            'fixture_text',
            'fixture_classes',
            'discard_executables',
            'dynamic_preview_budget',
        ], $this->registry->keys());

        $this->assertTrue($this->registry->has('fixture_mode'));
        $this->assertFalse($this->registry->has('drop_all_releases'));
    }

    public function test_a_setting_reports_the_section_and_card_it_lives_in(): void
    {
        $location = $this->registry->locate('fixture_classes');

        $this->assertNotNull($location);
        $this->assertSame('fixture', $location->section->id);
        $this->assertSame('collections', $location->card->id);
        $this->assertSame('Fixture ▸ Collections', $location->breadcrumb());
    }

    public function test_search_matches_key_fragments_label_and_help_text(): void
    {
        $this->assertSame(
            ['fixture_threads'],
            $this->keysFrom($this->registry->search('threads')),
        );

        $this->assertSame(
            ['fixture_date'],
            $this->keysFrom($this->registry->search('calendar')),
        );

        $this->assertContains('fixture_mode', $this->keysFrom($this->registry->search('fixed set')));
        $this->assertSame([], $this->registry->search('   '));
    }

    public function test_search_puts_an_exact_key_match_first(): void
    {
        $hits = $this->keysFrom($this->registry->search('fixture_size'));

        $this->assertSame('fixture_size', $hits[0]);
    }

    public function test_a_card_exposes_the_form_fields_it_accepts_including_size_unit_selects(): void
    {
        $card = $this->registry->card('fixture', 'scalars');

        $this->assertNotNull($card);
        $this->assertSame([
            'fixture_switch',
            'fixture_threads',
            'fixture_size',
            'fixture_size_unit',
            'fixture_date',
            'fixture_mode',
            'fixture_text',
        ], $card->formFields());
    }

    public function test_a_definition_without_declared_rules_falls_back_to_its_type_defaults(): void
    {
        $definition = $this->registry->definition('fixture_date');

        $this->assertNotNull($definition);
        $this->assertSame(SettingType::Date, $definition->type);
        $this->assertSame(['fixture_date' => ['nullable', 'date_format:Y-m-d']], $definition->validationRules());
    }

    public function test_declared_rules_replace_the_type_defaults_but_never_the_type_guard(): void
    {
        $threads = $this->registry->definition('fixture_threads');
        $this->assertNotNull($threads);
        $this->assertSame(['fixture_threads' => ['required', 'integer', 'min:1', 'max:16']], $threads->validationRules());

        $mode = $this->registry->definition('fixture_mode');
        $this->assertNotNull($mode);
        $rules = $mode->validationRules()['fixture_mode'];
        $this->assertSame('required', $rules[0]);
        $this->assertSame('in:"0","1","2"', (string) $rules[1]);
    }

    public function test_bool_definitions_carry_a_yes_no_option_pair_by_default(): void
    {
        $definition = $this->registry->definition('fixture_switch');

        $this->assertNotNull($definition);
        $this->assertSame([1 => 'Yes', 0 => 'No'], $definition->resolvedOptions());
    }

    /**
     * @param  list<SettingLocation>  $locations
     * @return list<string>
     */
    private function keysFrom(array $locations): array
    {
        return array_map(static fn ($location): string => $location->definition->key, $locations);
    }
}
