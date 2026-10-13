<?php

declare(strict_types=1);

namespace Tests\Unit\Categorization;

use App\Services\Categorization\Arbitration\CategoryArbitrationSettings;
use App\Services\DecisionModel\DecisionMode;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class CategoryArbitrationSettingsTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_an_install_without_the_settings_has_the_model_off(): void
    {
        $this->assertSame(DecisionMode::Off, CategoryArbitrationSettings::read()->mode);
    }

    public function test_stored_values_are_read(): void
    {
        $this->store(['decision_model_mode' => 'apply', 'decision_model_apply_threshold' => '0.9', 'decision_model_arbitrate_below' => '0.5']);

        $settings = CategoryArbitrationSettings::read();

        $this->assertSame([DecisionMode::Apply, 0.9, 0.5], [$settings->mode, $settings->applyThreshold, $settings->arbitrateBelow]);
    }

    public function test_the_ends_of_the_range_are_honoured(): void
    {
        $this->store(['decision_model_mode' => 'shadow', 'decision_model_apply_threshold' => '1', 'decision_model_arbitrate_below' => '0']);

        $settings = CategoryArbitrationSettings::read();

        $this->assertSame([1.0, 0.0], [$settings->applyThreshold, $settings->arbitrateBelow]);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unusableThresholds(): array
    {
        return [
            'missing' => [null],
            'blank' => [''],
            'not a number' => ['high'],
            'above one' => ['1.2'],
            'negative' => ['-0.2'],
        ];
    }

    #[DataProvider('unusableThresholds')]
    public function test_an_unusable_threshold_resolves_to_the_coded_default(?string $stored): void
    {
        $this->store(array_filter([
            'decision_model_mode' => 'shadow',
            'decision_model_apply_threshold' => $stored,
            'decision_model_arbitrate_below' => $stored,
        ], static fn (?string $value): bool => $value !== null));

        $settings = CategoryArbitrationSettings::read();

        $this->assertSame([0.80, 0.70], [$settings->applyThreshold, $settings->arbitrateBelow]);
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function store(array $settings): void
    {
        foreach ($settings as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
    }
}
