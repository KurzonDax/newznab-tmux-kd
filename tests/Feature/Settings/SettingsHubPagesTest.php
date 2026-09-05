<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Http\Controllers\Admin\AdminSettingsController;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\Settings\InteractsWithSettingsHub;
use Tests\TestCase;

/**
 * The hub shell and the two pages that prove the pattern end to end.
 */
class SettingsHubPagesTest extends TestCase
{
    use InteractsWithSettingsHub;
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'title' => 'NNTmux Test',
            'home_link' => '/',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        Cache::flush();

        $this->createSettingsHubSchema();
        $this->seedSettings();
        $this->resetGlobalComposerState();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_hub_lands_on_its_first_section(): void
    {
        $response = app(AdminSettingsController::class)->index();

        $this->assertTrue($response->isRedirect());
        $this->assertStringEndsWith('/admin/settings/website', $response->getTargetUrl());
    }

    public function test_an_unregistered_section_is_a_404(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->showSection('does-not-exist');
    }

    public function test_the_website_page_renders_its_cards_from_the_registry(): void
    {
        $rendered = $this->renderSection('website');

        $this->assertStringContainsString('Branding &amp; layout', $rendered);
        $this->assertStringContainsString('Browse &amp; display', $rendered);
        $this->assertStringContainsString('Sessions &amp; access', $rendered);

        $this->assertStringContainsString('name="strapline"', $rendered);
        $this->assertStringContainsString('name="showpasswordedrelease"', $rendered);
        $this->assertStringContainsString('name="trailers_size_x"', $rendered);
        $this->assertStringContainsString('name="single_active_session"', $rendered);

        $this->assertStringContainsString('A great usenet indexer', $rendered, 'Stored values are rendered back into the form.');
        $this->assertStringContainsString('/admin/settings/website/branding', $rendered);
    }

    public function test_the_engine_page_renders_the_safety_valves_that_used_to_need_sql(): void
    {
        $rendered = $this->renderSection('engine');

        $this->assertStringContainsString('Safety valves', $rendered);
        $this->assertStringContainsString('name="collections_kill"', $rendered);
        $this->assertStringContainsString('name="postprocess_kill"', $rendered);
        $this->assertStringContainsString('Pause header collection above', $rendered);
        $this->assertStringContainsString('Layout mode', $rendered);
        $this->assertStringContainsString('Monitoring windows', $rendered);
    }

    public function test_the_pipeline_strip_marks_the_stage_the_page_belongs_to(): void
    {
        $this->assertStringContainsString('aria-current="step"', $this->renderSection('website'));

        $this->assertStringNotContainsString(
            'aria-current="step"',
            $this->renderSection('engine'),
            'A page that governs every stage highlights none of them.'
        );
    }

    public function test_the_sessions_card_points_at_the_registrations_page(): void
    {
        $this->assertStringContainsString(
            'href="'.route('admin.registrations.index').'">Registrations</a>',
            $this->renderSection('website'),
        );
    }

    public function test_the_sub_navigation_lists_every_section_and_the_neighbouring_pages(): void
    {
        $rendered = $this->renderSection('website');

        $this->assertStringContainsString('/admin/settings/website', $rendered);
        $this->assertStringContainsString('/admin/settings/engine', $rendered);
        $this->assertStringContainsString('/admin/backups', $rendered);
        $this->assertStringContainsString('/admin/registrations', $rendered);
    }

    public function test_a_text_card_saves_through_the_foundation_path(): void
    {
        $response = $this->saveCard('website', 'branding', [
            'strapline' => 'Indexing since forever',
            'metatitle' => 'An indexer',
            'metadescription' => 'A usenet indexing website',
            'metakeywords' => 'usenet,nzbs',
            'footer' => 'Usenet binary indexer.',
            'home_link' => '/browse',
            'dereferrer_link' => '',
            'tandc' => '<p>Terms.</p>',
        ]);

        $this->assertTrue($response->isRedirect());
        $this->assertSame('Indexing since forever', $this->storedSettingValue('strapline'));
        $this->assertSame('/browse', $this->storedSettingValue('home_link'));
    }

    public function test_a_picker_card_saves_and_rejects_an_out_of_range_number(): void
    {
        $payload = [
            'showpasswordedrelease' => '1',
            'grabstatus' => '0',
            'trailers_display' => '1',
            'trailers_size_x' => '640',
            'trailers_size_y' => '360',
        ];

        $this->saveCard('website', 'browse', $payload);

        $this->assertSame('1', $this->storedSettingValue('showpasswordedrelease'));
        $this->assertSame('640', $this->storedSettingValue('trailers_size_x'));

        try {
            $this->saveCard('website', 'browse', array_merge($payload, ['trailers_size_x' => '0']));
            $this->fail('A trailer width of zero must be rejected.');
        } catch (ValidationException) {
            $this->assertSame('640', $this->storedSettingValue('trailers_size_x'), 'A rejected save leaves the stored value alone.');
        }
    }

    public function test_the_safety_valves_persist_from_the_engine_page(): void
    {
        $this->saveCard('engine', 'safety-valves', [
            'collections_kill' => '250000',
            'postprocess_kill' => '750000',
        ]);

        $this->assertSame('250000', $this->storedSettingValue('collections_kill'));
        $this->assertSame('750000', $this->storedSettingValue('postprocess_kill'));
    }

    public function test_a_setting_with_no_row_yet_is_created_by_its_card(): void
    {
        DB::table('settings')->where('name', 'postprocess_kill')->delete();

        $this->saveCard('engine', 'safety-valves', [
            'collections_kill' => '0',
            'postprocess_kill' => '1000',
        ]);

        $this->assertSame('1000', $this->storedSettingValue('postprocess_kill'));
    }

    public function test_search_finds_settings_by_key_fragment_and_by_help_text(): void
    {
        $byKey = app(SettingsRegistry::class)->search('collections_kill');
        $this->assertSame('collections_kill', $byKey[0]->definition->key);
        $this->assertSame('Engine & Monitoring ▸ Safety valves', $byKey[0]->breadcrumb());

        $byHelp = app(SettingsRegistry::class)->search('anti-account-sharing');
        $this->assertSame(['single_active_session'], array_map(
            static fn ($hit): string => $hit->definition->key,
            $byHelp,
        ));
    }

    public function test_search_results_render_in_the_sidebar_with_their_location(): void
    {
        $rendered = $this->renderSection('website', ['q' => 'niceness']);

        $this->assertStringContainsString('Process niceness', $rendered);
        $this->assertStringContainsString('Engine &amp; Monitoring ▸ Session', $rendered);
        $this->assertStringContainsString('/admin/settings/engine#setting-niceness', $rendered);
    }

    public function test_a_search_that_matches_nothing_says_so(): void
    {
        $this->assertStringContainsString('Nothing matches', $this->renderSection('website', ['q' => 'zzzz-no-such-setting']));
    }

    public function test_every_card_gates_its_own_save_button(): void
    {
        $rendered = $this->renderSection('engine');

        $this->assertSame(
            5,
            substr_count($rendered, 'x-data="settingsCard"'),
            'Each card on the engine page is its own gated form.'
        );
        $this->assertStringContainsString(':disabled="pristine"', $rendered);
    }

    private function seedSettings(): void
    {
        DB::table('settings')->upsert([
            ['name' => 'title', 'value' => 'NNTmux Test'],
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'strapline', 'value' => 'A great usenet indexer'],
            ['name' => 'metatitle', 'value' => 'An indexer'],
            ['name' => 'metadescription', 'value' => 'A usenet indexing website'],
            ['name' => 'metakeywords', 'value' => 'usenet,nzbs'],
            ['name' => 'footer', 'value' => 'Usenet binary indexer.'],
            ['name' => 'home_link', 'value' => '/'],
            ['name' => 'dereferrer_link', 'value' => ''],
            ['name' => 'tandc', 'value' => '<p>Terms.</p>'],
            ['name' => 'showpasswordedrelease', 'value' => '0'],
            ['name' => 'grabstatus', 'value' => '1'],
            ['name' => 'trailers_display', 'value' => '1'],
            ['name' => 'trailers_size_x', 'value' => '480'],
            ['name' => 'trailers_size_y', 'value' => '345'],
            ['name' => 'single_active_session', 'value' => '0'],
            ['name' => 'running', 'value' => '0'],
            ['name' => 'tmux_session', 'value' => 'nntmux'],
            ['name' => 'niceness', 'value' => '19'],
            ['name' => 'monitor_delay', 'value' => '30'],
            ['name' => 'collections_kill', 'value' => '0'],
            ['name' => 'postprocess_kill', 'value' => '0'],
            ['name' => 'sequential', 'value' => '0'],
            ['name' => 'run_ircscraper', 'value' => '0'],
            ['name' => 'console', 'value' => '0'],
            ['name' => 'htop', 'value' => '0'],
            ['name' => 'mytop', 'value' => '0'],
            ['name' => 'nmon', 'value' => '0'],
            ['name' => 'vnstat', 'value' => '0'],
            ['name' => 'vnstat_args', 'value' => ''],
            ['name' => 'tcptrack', 'value' => '0'],
            ['name' => 'tcptrack_args', 'value' => '-i eth0 port 443'],
            ['name' => 'bwmng', 'value' => '0'],
            ['name' => 'redis', 'value' => '0'],
            ['name' => 'redis_args', 'value' => ''],
        ], ['name'], ['value']);
    }
}
