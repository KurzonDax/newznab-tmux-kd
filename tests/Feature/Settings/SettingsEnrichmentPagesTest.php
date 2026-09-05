<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Services\ReleaseRemoverService;
use App\Support\Settings\SettingsRegistry;
use Database\Seeders\SettingsTableSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\Settings\InteractsWithSettingsHub;
use Tests\TestCase;

/**
 * The three enrichment pages, and the one behaviour change the redesign ships.
 */
class SettingsEnrichmentPagesTest extends TestCase
{
    use InteractsWithSettingsHub;
    use IsolatedSqliteDatabase;

    /**
     * Form fields the audit found nothing reads. None of them may appear on the hub.
     *
     * @var list<string>
     */
    private const array DEAD_FIELDS = [
        'end',
        'userdownloadpurgedays',
        'userhostexclusion',
        'partsdeletechunks',
        'showdroppedyencparts',
        'lookupmusic',
        'lookuplanguage',
        'music_identity_shadow',
        'maxmusicprocessed',
        'ffmpeg_duration',
        'bins_kill_timer',
        'post_kill_timer',
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

    public function test_the_post_processing_page_renders_every_card(): void
    {
        $rendered = $this->renderSection('post-processing');

        foreach (['Additional pane', 'Eligibility', 'Batching &amp; timeouts', 'Archive &amp; password inspection', 'NFO retrieval', 'Previews &amp; samples', 'Audio previews'] as $card) {
            $this->assertStringContainsString($card, $rendered);
        }

        $this->assertStringContainsString('name="minsizetopostprocess"', $rendered);
        $this->assertStringContainsString('name="postthreadsaudio"', $rendered);
        $this->assertStringContainsString('name="generate_previews[2000]"', $rendered);
    }

    public function test_the_eligibility_card_contrasts_skipping_with_the_formation_gates(): void
    {
        $rendered = $this->renderSection('post-processing');

        $this->assertStringContainsString('These skip, they do not delete', $rendered);
        $this->assertStringContainsString('it reads as the seeded 1', $rendered);
        $this->assertStringContainsString('Blank reads as the seeded 100', $rendered);
    }

    public function test_the_pane_mode_labels_name_the_two_jobs_the_pane_does(): void
    {
        $rendered = $this->renderSection('post-processing');

        $this->assertStringContainsString('Archive inspection only', $rendered);
        $this->assertStringContainsString('NFO retrieval only', $rendered);
        $this->assertStringContainsString('Archive inspection and NFO retrieval', $rendered);
    }

    public function test_the_nfo_help_no_longer_claims_it_governs_movie_lookups(): void
    {
        $help = app(SettingsRegistry::class)->definition('lookupnfo')?->help;

        $this->assertIsString($help);
        $this->assertStringNotContainsString('disable movie lookups', $help);
        $this->assertStringContainsString('do not depend on this', $help);
    }

    public function test_the_metadata_page_pairs_each_lookup_with_its_own_cap(): void
    {
        $rendered = $this->renderSection('metadata-lookups');

        $pairs = [
            'lookupimdb' => 'maximdbprocessed',
            'lookuptv' => 'maxrageprocessed',
            'lookupanidb' => 'maxanidbprocessed',
            'lookupbooks' => 'maxbooksprocessed',
            'lookupgames' => 'maxgamesprocessed',
        ];

        $registry = app(SettingsRegistry::class);

        foreach ($pairs as $toggle => $cap) {
            $this->assertStringContainsString('name="'.$toggle.'"', $rendered);
            $this->assertStringContainsString('name="'.$cap.'"', $rendered);
            $this->assertSame(
                $registry->locate($toggle)?->card->id,
                $registry->locate($cap)?->card->id,
                $toggle.' and '.$cap.' are read together, so they save together.'
            );
        }

        $this->assertSame(
            $registry->locate('music_identity_enabled')?->card->id,
            $registry->locate('music_identity_workers')?->card->id,
        );
    }

    public function test_the_naming_page_renders_the_naming_and_sweep_panes(): void
    {
        $rendered = $this->renderSection('naming-hygiene');

        $this->assertStringContainsString('Fix release names', $rendered);
        $this->assertStringContainsString('Remove crap', $rendered);
        $this->assertStringContainsString('Executable discard', $rendered);
        $this->assertStringContainsString('Categorization', $rendered);
        $this->assertStringContainsString('name="fix_crap[]"', $rendered);
        $this->assertStringContainsString('name="discard_executables[2000]"', $rendered);
    }

    public function test_the_naming_card_renders_and_saves_the_descriptive_title_toggle(): void
    {
        DB::table('settings')->where('name', 'descriptive_title_rename')->update(['value' => '0']);
        Cache::flush();

        $this->assertStringContainsString('Rename from descriptive file names', $this->renderSection('naming-hygiene'));

        $this->saveCard('naming-hygiene', 'fix-names', [
            'fix_names' => '1',
            'fixnamethreads' => '1',
            'fixnamesperrun' => '10',
            'fix_timer' => '30',
            'fix_names_timeout' => '1200',
            'descriptive_title_rename' => '1',
        ]);

        $this->assertSame('1', $this->storedSettingValue('descriptive_title_rename'));
    }

    public function test_the_executable_card_renders_and_saves_the_forced_root_escape(): void
    {
        $rendered = $this->renderSection('naming-hygiene');

        $this->assertStringContainsString('name="forced_root_pc_escape"', $rendered);
        $this->assertStringContainsString('Let PC releases escape a forced root', $rendered);
        $this->assertStringContainsString('malware', $rendered);
        $this->assertStringContainsString('Executable extensions', $rendered);

        $this->saveCard('naming-hygiene', 'executables', $this->currentCardPayload('naming-hygiene', 'executables', [
            'forced_root_pc_escape' => '1',
        ]));

        $this->assertSame('1', $this->storedSettingValue('forced_root_pc_escape'));
    }

    public function test_the_previews_card_offers_only_the_eligible_roots_for_the_restricted_toggles(): void
    {
        $rendered = $this->renderSection('post-processing');

        foreach ([2000, 5000, 6000] as $eligible) {
            $this->assertStringContainsString('name="dynamic_preview_budget['.$eligible.']"', $rendered);
            $this->assertStringContainsString('name="generate_clips['.$eligible.']"', $rendered);
        }

        foreach ([1, 1000, 4000] as $ineligible) {
            $this->assertStringNotContainsString('name="dynamic_preview_budget['.$ineligible.']"', $rendered);
            $this->assertStringNotContainsString('name="generate_clips['.$ineligible.']"', $rendered);
            $this->assertStringContainsString('name="generate_previews['.$ineligible.']"', $rendered, 'Previews are offered for every root.');
        }
    }

    public function test_the_custom_class_picker_offers_exactly_what_the_sweep_accepts(): void
    {
        $definition = app(SettingsRegistry::class)->definition('fix_crap');

        $this->assertNotNull($definition);
        $this->assertSame(
            ReleaseRemoverService::TYPES,
            array_keys($definition->resolvedOptions()),
            'A class the picker omits can never be scheduled; a token it invents fails the sweep.'
        );
        $this->assertCount(16, $definition->resolvedOptions());
    }

    public function test_the_service_handler_map_and_its_published_type_list_agree(): void
    {
        $service = app(ReleaseRemoverService::class);

        $handlers = (new ReflectionClass($service))->getProperty('removalHandlers');

        $handlerKeys = array_keys($handlers->getValue($service));
        sort($handlerKeys);

        $types = ReleaseRemoverService::TYPES;
        sort($types);

        $this->assertSame($types, $handlerKeys);
    }

    public function test_all_now_means_all_sixteen_classes(): void
    {
        $service = app(ReleaseRemoverService::class);
        $reflection = new ReflectionClass($service);

        $called = [];
        $recorders = [];
        foreach (ReleaseRemoverService::TYPES as $type) {
            $recorders[$type] = static function () use ($type, &$called): void {
                $called[] = $type;
            };
        }

        $reflection->getProperty('removalHandlers')->setValue($service, $recorders);
        $reflection->getMethod('executeAllRemovals')->invoke($service);

        $this->assertSame(ReleaseRemoverService::TYPES, $called);
        $this->assertContains('passwordurl', $called, 'passwordurl was excluded from All with no rationale.');
        $this->assertContains('wmv_all', $called, 'wmv_all was excluded from All with no rationale.');
    }

    public function test_no_dead_field_from_the_audit_appears_anywhere_on_the_hub(): void
    {
        $registry = app(SettingsRegistry::class);

        foreach (self::DEAD_FIELDS as $key) {
            $this->assertFalse($registry->has($key), $key.' has no reader and must not be offered.');
        }

        foreach (array_keys($registry->sections()) as $section) {
            $rendered = $this->renderSection($section);

            foreach (self::DEAD_FIELDS as $key) {
                $this->assertStringNotContainsString('name="'.$key.'"', $rendered, $key.' is rendered on '.$section.'.');
            }
        }
    }

    public function test_the_eligibility_card_saves_its_size_pair(): void
    {
        $this->saveCard('post-processing', 'eligibility', [
            'minsizetopostprocess' => '5',
            'minsizetopostprocess_unit' => 'MB',
            'maxsizetopostprocess' => '50',
            'maxsizetopostprocess_unit' => 'GB',
        ]);

        $this->assertSame('5242880', $this->storedSettingValue('minsizetopostprocess'));
        $this->assertSame('53687091200', $this->storedSettingValue('maxsizetopostprocess'));
    }

    public function test_the_previews_card_persists_its_per_root_toggles(): void
    {
        $this->saveCard('post-processing', 'previews', [
            'processthumbnails' => '1',
            'processvideos' => '0',
            'processjpg' => '0',
            'segmentstodownload' => '2',
            'generate_previews' => ['2000' => '1', '5000' => '1'],
            'preview_target_seconds' => '30',
            'preview_max_fetch_mb' => '300',
            'preview_max_rar_parts' => '6',
            'dynamic_preview_budget' => ['2000' => '1'],
            'generate_clips' => ['5000' => '1'],
            'clip_minimum_seconds' => '5',
        ]);

        $toggles = DB::table('root_categories')->get()->keyBy('id');

        $this->assertEquals(1, $toggles[2000]->generate_previews);
        $this->assertEquals(0, $toggles[6000]->generate_previews, 'An unchecked root turns off.');
        $this->assertEquals(1, $toggles[2000]->dynamic_preview_budget);
        $this->assertEquals(1, $toggles[5000]->generate_clips);
        $this->assertEquals(0, $toggles[2000]->generate_clips);

        $this->assertNull(
            DB::table('settings')->where('name', 'generate_previews')->value('value'),
            'A toggle set must not become a settings row.'
        );
    }

    public function test_the_metadata_cards_save(): void
    {
        $this->saveCard('metadata-lookups', 'movies', [
            'lookupimdb' => '2',
            'maximdbprocessed' => '250',
        ]);

        $this->assertSame('2', $this->storedSettingValue('lookupimdb'));
        $this->assertSame('250', $this->storedSettingValue('maximdbprocessed'));

        $this->saveCard('metadata-lookups', 'etiquette', ['amazonsleep' => '1500']);
        $this->assertSame('1500', $this->storedSettingValue('amazonsleep'));
    }

    public function test_the_sweep_card_saves_a_custom_selection_as_the_service_reads_it(): void
    {
        $this->saveCard('naming-hygiene', 'remove-crap', [
            'fix_crap_opt' => 'Custom',
            'fix_crap' => ['passwordurl', 'wmv_all'],
            'crap_timer' => '30',
        ]);

        $this->assertSame('Custom', $this->storedSettingValue('fix_crap_opt'));
        $this->assertSame('passwordurl,wmv_all', $this->storedSettingValue('fix_crap'));
    }

    public function test_the_sweep_card_rejects_a_class_the_service_would_not_accept(): void
    {
        $this->expectException(ValidationException::class);

        $this->saveCard('naming-hygiene', 'remove-crap', [
            'fix_crap_opt' => 'Custom',
            'fix_crap' => ['not_a_class'],
            'crap_timer' => '30',
        ]);
    }

    public function test_the_naming_card_enforces_the_step_timeout_floor(): void
    {
        $payload = [
            'fix_names' => '1',
            'fixnamethreads' => '1',
            'fixnamesperrun' => '10',
            'fix_timer' => '30',
            'fix_names_timeout' => '1200',
            'descriptive_title_rename' => '1',
        ];

        $this->saveCard('naming-hygiene', 'fix-names', $payload);
        $this->assertSame('1200', $this->storedSettingValue('fix_names_timeout'));

        $this->expectException(ValidationException::class);
        $this->saveCard('naming-hygiene', 'fix-names', array_merge($payload, ['fix_names_timeout' => '5']));
    }
}
