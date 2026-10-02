<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\UsenetGroupProviderCursor;
use App\Support\Settings\SettingsRegistry;
use Database\Seeders\SettingsTableSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\Settings\InteractsWithSettingsHub;
use Tests\TestCase;

final class SecondaryHeaderStartSettingTest extends TestCase
{
    use InteractsWithSettingsHub;
    use IsolatedSqliteDatabase;

    /** @return array<string, string> */
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
        Cache::flush();
        $this->resetGlobalComposerState();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_it_is_on_the_header_download_card_of_usenet_ingest(): void
    {
        $card = collect(app(SettingsRegistry::class)->section('usenet-ingest')->cards)
            ->first(fn ($card): bool => $card->id === 'headers');

        $this->assertSame('Header download', $card->title);
        $this->assertContains('secondary_header_start_hours', array_map(fn ($setting): string => $setting->key, $card->settings));
        $this->assertStringContainsString('Secondary provider start', $this->renderSection('usenet-ingest'));

        $this->saveCard('usenet-ingest', 'headers', $this->currentCardPayload('usenet-ingest', 'headers', ['secondary_header_start_hours' => '2160']));
        $this->assertSame('2160', $this->storedSettingValue('secondary_header_start_hours'));
    }

    #[DataProvider('rejected')]
    public function test_it_rejects_hours_outside_one_to_ninety_days(string $value): void
    {
        try {
            $this->saveCard('usenet-ingest', 'headers', $this->currentCardPayload('usenet-ingest', 'headers', ['secondary_header_start_hours' => $value]));
            $this->fail('secondary_header_start_hours must reject ['.$value.'].');
        } catch (ValidationException $exception) {
            $this->assertTrue($exception->validator->errors()->has('secondary_header_start_hours'));
        }

        $this->assertSame('36', $this->storedSettingValue('secondary_header_start_hours'));
    }

    /** @return array<string, array{string}> */
    public static function rejected(): array
    {
        return ['zero' => ['0'], 'over ninety days' => ['2161']];
    }

    public function test_the_reader_falls_back_to_thirty_six_hours(): void
    {
        $this->assertSame(36, UsenetGroupProviderCursor::startHours());

        DB::table('settings')->where('name', 'secondary_header_start_hours')->update(['value' => '0']);
        $this->assertSame(36, UsenetGroupProviderCursor::startHours());

        DB::table('settings')->where('name', 'secondary_header_start_hours')->update(['value' => '']);
        $this->assertSame(36, UsenetGroupProviderCursor::startHours());

        DB::table('settings')->where('name', 'secondary_header_start_hours')->update(['value' => '48']);
        $this->assertSame(48, UsenetGroupProviderCursor::startHours());
    }
}
