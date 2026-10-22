<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Support\Settings\SettingsRegistry;
use Database\Seeders\SettingsTableSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\Settings\InteractsWithSettingsHub;
use Tests\TestCase;

/**
 * The decision-model page of the settings hub and the defaults it starts from.
 */
final class DecisionModelSettingsTest extends TestCase
{
    use InteractsWithSettingsHub;
    use IsolatedSqliteDatabase;

    private const array DEFAULTS = [
        'decision_model_mode' => 'off',
        'decision_model_apply_threshold' => '0.80',
        'decision_model_arbitrate_below' => '0.70',
        'decision_model_music_album_mode' => 'off',
    ];

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '0', 'title' => 'NNTmux Test', 'home_link' => '/'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        Cache::flush();

        $this->createSettingsHubSchema();
        (new SettingsTableSeeder)->run();
        DB::table('settings')->upsert([['name' => 'title', 'value' => 'NNTmux Test']], ['name'], ['value']);
        Cache::flush();

        $this->resetGlobalComposerState();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_a_fresh_install_starts_with_the_model_off_and_the_agreed_thresholds(): void
    {
        foreach (self::DEFAULTS as $name => $value) {
            $this->assertSame($value, $this->storedSettingValue($name));
        }
    }

    public function test_an_existing_install_gets_the_same_defaults_without_losing_an_operator_value(): void
    {
        DB::table('settings')->whereIn('name', array_keys(self::DEFAULTS))->delete();
        DB::table('settings')->insert([
            ['name' => 'decision_model_mode', 'value' => 'shadow'],
            ['name' => 'decision_model_music_album_mode', 'value' => 'shadow'],
        ]);

        foreach ([
            '2026_10_13_120100_add_decision_model_settings.php',
            '2026_10_23_120000_add_decision_model_music_album_setting.php',
        ] as $file) {
            /** @var Migration $migration */
            $migration = require database_path('migrations/'.$file);
            $migration->up();
        }

        $this->assertSame('shadow', $this->storedSettingValue('decision_model_mode'));
        $this->assertSame('0.80', $this->storedSettingValue('decision_model_apply_threshold'));
        $this->assertSame('0.70', $this->storedSettingValue('decision_model_arbitrate_below'));
        $this->assertSame('shadow', $this->storedSettingValue('decision_model_music_album_mode'));
    }

    public function test_the_page_renders_the_four_settings_on_two_cards(): void
    {
        $rendered = $this->renderSection('decision-model');

        $this->assertStringContainsString('name="decision_model_mode"', $rendered);
        $this->assertStringContainsString('name="decision_model_apply_threshold"', $rendered);
        $this->assertStringContainsString('name="decision_model_arbitrate_below"', $rendered);
        $this->assertStringContainsString('Shadow', $rendered);
        $this->assertStringContainsString('Music album check', $rendered);
        $this->assertStringContainsString('name="decision_model_music_album_mode"', $rendered);
    }

    public function test_the_music_album_mode_offers_only_off_and_shadow(): void
    {
        $definition = app(SettingsRegistry::class)->definition('decision_model_music_album_mode');

        $this->assertSame(['off', 'shadow'], array_keys($definition?->resolvedOptions() ?? []));
    }

    public function test_the_music_album_card_saves_shadow(): void
    {
        $this->saveCard('decision-model', 'music-album-check', ['decision_model_music_album_mode' => 'shadow']);

        $this->assertSame('shadow', $this->storedSettingValue('decision_model_music_album_mode'));
        $this->assertSame('off', $this->storedSettingValue('decision_model_mode'), 'the other card is untouched');
    }

    public function test_the_music_album_card_rejects_apply(): void
    {
        try {
            $this->saveCard('decision-model', 'music-album-check', ['decision_model_music_album_mode' => 'apply']);
            $this->fail('The card saved a mode it does not offer.');
        } catch (ValidationException $e) {
            $this->assertSame(['decision_model_music_album_mode'], array_keys($e->errors()));
        }

        $this->assertSame('off', $this->storedSettingValue('decision_model_music_album_mode'));
    }

    public function test_the_mode_offers_off_shadow_and_apply(): void
    {
        $definition = app(SettingsRegistry::class)->definition('decision_model_mode');

        $this->assertSame(['off', 'shadow', 'apply'], array_keys($definition?->resolvedOptions() ?? []));
    }

    public function test_the_card_saves_a_mode_and_both_thresholds(): void
    {
        $this->saveCard('decision-model', 'category-arbitration', [
            'decision_model_mode' => 'shadow',
            'decision_model_apply_threshold' => '0.85',
            'decision_model_arbitrate_below' => '0.6',
        ]);

        $this->assertSame('shadow', $this->storedSettingValue('decision_model_mode'));
        $this->assertSame('0.85', $this->storedSettingValue('decision_model_apply_threshold'));
        $this->assertSame('0.6', $this->storedSettingValue('decision_model_arbitrate_below'));
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function rejectedValues(): array
    {
        return [
            'a mode that does not exist' => [['decision_model_mode' => 'always']],
            'a threshold above 1' => [['decision_model_apply_threshold' => '1.5']],
            'a negative threshold' => [['decision_model_arbitrate_below' => '-0.1']],
            'a threshold that is not a number' => [['decision_model_apply_threshold' => 'high']],
            'a blank threshold' => [['decision_model_arbitrate_below' => '']],
        ];
    }

    /**
     * @param  array<string, string>  $override
     */
    #[DataProvider('rejectedValues')]
    public function test_the_card_rejects_a_value_outside_what_the_reader_accepts(array $override): void
    {
        try {
            $this->saveCard('decision-model', 'category-arbitration', $this->currentCardPayload('decision-model', 'category-arbitration', $override));
            $this->fail('The card saved a value it must reject.');
        } catch (ValidationException $e) {
            $this->assertSame(array_keys($override), array_keys($e->errors()));
        }

        foreach (self::DEFAULTS as $name => $value) {
            $this->assertSame($value, $this->storedSettingValue($name));
        }
    }
}
