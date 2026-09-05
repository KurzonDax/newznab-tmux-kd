<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Settings;
use App\Services\Settings\SettingsCardUpdater;
use App\Support\Settings\SettingCard;
use App\Support\Settings\SettingsRegistry;
use App\Support\SizeUnit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\Settings\FixtureSettingsSections;
use Tests\Support\Settings\InteractsWithSettingsHub;
use Tests\TestCase;

/**
 * The per-card save path: whitelist, validation, upsert, and root-toggle routing.
 */
class SettingsCardUpdaterTest extends TestCase
{
    use InteractsWithSettingsHub;
    use IsolatedSqliteDatabase;

    private SettingsRegistry $registry;

    private SettingsCardUpdater $updater;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '0'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        Cache::flush();

        $this->createSettingsHubSchema();
        DB::table('settings')->delete();

        $this->registry = new SettingsRegistry([FixtureSettingsSections::class]);
        $this->updater = new SettingsCardUpdater;
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_saving_a_card_creates_settings_rows_that_do_not_exist_yet(): void
    {
        $this->assertNull(DB::table('settings')->where('name', 'fixture_switch')->value('value'));

        $this->updater->update($this->card('scalars'), $this->scalarPayload());

        $this->assertSame('1', $this->storedSettingValue('fixture_switch'));
        $this->assertSame('4', $this->storedSettingValue('fixture_threads'));
        $this->assertSame('2', $this->storedSettingValue('fixture_mode'));
    }

    public function test_saving_a_card_updates_a_row_that_already_exists(): void
    {
        DB::table('settings')->insert(['name' => 'fixture_threads', 'value' => '1']);

        $this->updater->update($this->card('scalars'), $this->scalarPayload(['fixture_threads' => '9']));

        $this->assertSame('9', $this->storedSettingValue('fixture_threads'));
        $this->assertSame(
            1,
            DB::table('settings')->where('name', 'fixture_threads')->count(),
            'An upsert must not duplicate the row it updates.'
        );
    }

    public function test_an_unknown_key_is_rejected_and_nothing_is_written(): void
    {
        $payload = $this->scalarPayload(['drop_all_releases' => '1']);

        try {
            $this->updater->update($this->card('scalars'), $payload);
            $this->fail('An unknown key must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('drop_all_releases', $exception->validator->errors()->first('card'));
        }

        $this->assertSame(0, DB::table('settings')->count(), 'A rejected payload must write nothing.');
    }

    public function test_a_key_belonging_to_another_card_is_rejected_wholesale(): void
    {
        $payload = $this->scalarPayload(['fixture_classes' => ['alpha']]);

        $this->expectException(ValidationException::class);

        try {
            $this->updater->update($this->card('scalars'), $payload);
        } finally {
            $this->assertSame(0, DB::table('settings')->count(), 'A cross-card payload must write nothing.');
        }
    }

    public function test_an_invalid_value_is_rejected_rather_than_clamped(): void
    {
        try {
            $this->updater->update($this->card('scalars'), $this->scalarPayload(['fixture_threads' => '99']));
            $this->fail('A value outside the declared range must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Fixture threads', $exception->validator->errors()->first('fixture_threads'));
        }

        $this->assertNull($this->storedSettingValue('fixture_threads'), 'Nothing is written when one field fails.');
    }

    public function test_an_option_outside_the_registered_set_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->updater->update($this->card('scalars'), $this->scalarPayload(['fixture_mode' => '7']));
    }

    public function test_a_malformed_date_is_rejected_and_a_valid_one_round_trips(): void
    {
        try {
            $this->updater->update($this->card('scalars'), $this->scalarPayload(['fixture_date' => '24-06-2012']));
            $this->fail('A date outside Y-m-d must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Fixture date', $exception->validator->errors()->first('fixture_date'));
        }

        $this->updater->update($this->card('scalars'), $this->scalarPayload(['fixture_date' => '2013-01-31']));

        $this->assertSame('2013-01-31', $this->storedSettingValue('fixture_date'));
    }

    public function test_size_fields_round_trip_through_their_unit_pair(): void
    {
        $this->updater->update($this->card('scalars'), $this->scalarPayload([
            'fixture_size' => '1.5',
            'fixture_size_unit' => 'GB',
        ]));

        $this->assertSame('1610612736', $this->storedSettingValue('fixture_size'));
        $this->assertNull(
            DB::table('settings')->where('name', 'fixture_size_unit')->value('value'),
            'The unit select must not become a settings row.'
        );

        // The display split prefers whole units: 1.5 GB comes back as the equivalent 1536 MB,
        // which is the same byte count the engine reads.
        $this->assertSame(['value' => 1536, 'unit' => 'MB'], SizeUnit::fromBytes((int) $this->storedSettingValue('fixture_size')));
    }

    public function test_an_unsupported_size_unit_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->updater->update($this->card('scalars'), $this->scalarPayload([
            'fixture_size' => '10',
            'fixture_size_unit' => 'PB',
        ]));
    }

    public function test_checkbox_sets_store_a_comma_separated_list_and_an_empty_post_clears_them(): void
    {
        $this->updater->update($this->card('collections'), ['fixture_classes' => ['alpha', 'gamma']]);

        $this->assertSame('alpha,gamma', $this->storedSettingValue('fixture_classes'));

        $this->updater->update($this->card('collections'), []);

        $this->assertSame('', $this->storedSettingValue('fixture_classes'));
    }

    public function test_a_token_outside_the_registered_set_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->updater->update($this->card('collections'), ['fixture_classes' => ['alpha', 'delta']]);
    }

    public function test_per_root_toggles_are_routed_to_root_categories_and_never_to_settings(): void
    {
        $this->updater->update($this->card('collections'), [
            'discard_executables' => ['1000' => '1', '2000' => '1'],
        ]);

        $toggles = DB::table('root_categories')->pluck('discard_executables', 'id');

        $this->assertEquals(1, $toggles[1000], 'A newly checked root turns on.');
        $this->assertEquals(1, $toggles[2000], 'A root that was already on stays on.');
        $this->assertEquals(0, $toggles[5000], 'An unchecked root turns off.');

        $this->assertNull(
            DB::table('settings')->where('name', 'discard_executables')->value('value'),
            'The toggle set must not leak into the settings table.'
        );
    }

    public function test_ineligible_roots_never_flip_whatever_is_posted(): void
    {
        $this->updater->update($this->card('collections'), [
            'dynamic_preview_budget' => ['2000' => '1', '1000' => '1'],
        ]);

        $toggles = DB::table('root_categories')->pluck('dynamic_preview_budget', 'id');

        $this->assertEquals(1, $toggles[2000], 'An eligible root flips.');
        $this->assertEquals(0, $toggles[6000], 'An eligible root that was on and is now unchecked turns off.');
        $this->assertEquals(0, $toggles[1000], 'An ineligible root is ignored even when posted.');
    }

    public function test_explicitly_stored_values_keep_resolving_exactly_as_before(): void
    {
        $this->updater->update($this->card('scalars'), $this->scalarPayload([
            'fixture_switch' => '0',
            'fixture_mode' => '0',
            'fixture_threads' => '12',
            'fixture_text' => '',
        ]));

        // settingValue() converts numeric strings and preserves the empty string; a value
        // written through the hub must read back the same way one written by the old form does.
        $this->assertSame(0, Settings::settingValue('fixture_switch'));
        $this->assertSame(0, Settings::settingValue('fixture_mode'));
        $this->assertSame(12, Settings::settingValue('fixture_threads'));
        $this->assertSame('', Settings::settingValue('fixture_text'));
    }

    private function card(string $id): SettingCard
    {
        $card = $this->registry->card('fixture', $id);

        $this->assertNotNull($card);

        return $card;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function scalarPayload(array $overrides = []): array
    {
        return array_merge([
            'fixture_switch' => '1',
            'fixture_threads' => '4',
            'fixture_size' => '500',
            'fixture_size_unit' => 'MB',
            'fixture_date' => '2012-06-24',
            'fixture_mode' => '2',
            'fixture_text' => 'hello',
        ], $overrides);
    }
}
