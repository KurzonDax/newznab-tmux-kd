<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Support\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\Settings\FixtureSettingsSections;
use Tests\Support\Settings\InteractsWithSettingsHub;
use Tests\TestCase;

/**
 * The one save action the whole hub posts to.
 */
class AdminSettingsControllerTest extends TestCase
{
    use InteractsWithSettingsHub;
    use IsolatedSqliteDatabase;

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

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        Cache::flush();

        $this->createSettingsHubSchema();
        DB::table('settings')->delete();

        $this->app->instance(SettingsRegistry::class, new SettingsRegistry([FixtureSettingsSections::class]));
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_saving_a_registered_card_writes_it_and_redirects_back_to_the_card(): void
    {
        $response = $this->saveCard('fixture', 'collections', ['fixture_classes' => ['beta']]);

        $this->assertTrue($response->isRedirect());
        $this->assertStringEndsWith('/admin/settings/fixture#card-collections', $response->getTargetUrl());
        $this->assertSame('beta', DB::table('settings')->where('name', 'fixture_classes')->value('value'));
    }

    public function test_an_unregistered_section_or_card_is_a_404(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->saveCard('fixture', 'not-a-card', []);
    }

    public function test_a_payload_carrying_another_cards_field_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->saveCard('fixture', 'collections', [
                'fixture_classes' => ['beta'],
                'fixture_switch' => '1',
            ]);
        } finally {
            $this->assertSame(0, DB::table('settings')->count());
        }
    }

    public function test_the_csrf_token_is_not_mistaken_for_a_setting(): void
    {
        $response = $this->saveCard('fixture', 'collections', [
            '_token' => 'irrelevant',
            'fixture_classes' => ['alpha'],
        ]);

        $this->assertTrue($response->isRedirect());
        $this->assertNull(DB::table('settings')->where('name', '_token')->value('value'));
    }
}
