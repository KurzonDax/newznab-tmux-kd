<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Services\Nzb\NzbService;
use App\Support\BackfillSettingRules;
use App\Support\NzbSettingRules;
use App\Support\Settings\SettingsRegistry;
use Database\Seeders\SettingsTableSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\MalformedSafeBackfillDates;
use Tests\Support\Settings\InteractsWithSettingsHub;
use Tests\TestCase;

/**
 * The two front-of-pipeline pages, and the rules classes they now enforce through the registry.
 */
class SettingsPipelinePagesTest extends TestCase
{
    use InteractsWithSettingsHub;
    use IsolatedSqliteDatabase;

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
        DB::table('settings')->upsert([
            ['name' => 'title', 'value' => 'NNTmux Test'],
        ], ['name'], ['value']);
        Cache::flush();

        $this->resetGlobalComposerState();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_usenet_ingest_page_groups_each_pane_with_its_own_tunables(): void
    {
        $rendered = $this->renderSection('usenet-ingest');

        $this->assertStringContainsString('Header download', $rendered);
        foreach (['binaries', 'binarythreads', 'bins_timer', 'maxmssgs', 'max_headers_iteration', 'secondary_header_start_hours'] as $key) {
            $this->assertStringContainsString('name="'.$key.'"', $rendered);
        }

        $this->assertStringContainsString('Backfill', $rendered);
        foreach (['backfill', 'backfill_order', 'backfill_days', 'safebackfilldate', 'disablebackfillgroup', 'backfill_qty', 'backfillthreads', 'back_timer', 'progressive'] as $key) {
            $this->assertStringContainsString('name="'.$key.'"', $rendered);
        }

        $this->assertStringContainsString('Missed-article repair', $rendered);
        $this->assertStringContainsString('name="partrepairmaxtries"', $rendered);
    }

    public function test_the_new_group_picker_says_which_field_it_reads(): void
    {
        $rendered = $this->renderSection('usenet-ingest');

        $this->assertStringContainsString('Days back', $rendered);
        $this->assertStringContainsString('Post count', $rendered);
        $this->assertMatchesRegularExpression(
            '/<option value="1"[^>]*>Days back<\/option>/',
            $rendered,
            'Value 1 is days; the label has to say so.'
        );
    }

    public function test_the_safe_backfill_date_uses_a_native_date_picker(): void
    {
        $this->assertMatchesRegularExpression(
            '/<input[^>]*type="date"[^>]*name="safebackfilldate"|<input[^>]*name="safebackfilldate"[^>]*type="date"/',
            $this->renderSection('usenet-ingest'),
        );
    }

    public function test_the_release_formation_page_renders_every_card(): void
    {
        $rendered = $this->renderSection('release-formation');

        $this->assertStringContainsString('Releases pane', $rendered);
        $this->assertStringContainsString('Formation gates', $rendered);
        $this->assertStringContainsString('NZB storage', $rendered);
        $this->assertStringContainsString('Retention &amp; cleanup', $rendered);
    }

    public function test_the_formation_gates_state_the_delete_and_the_stricter_of_layers(): void
    {
        $rendered = $this->renderSection('release-formation');

        $this->assertStringContainsString('deletes', $rendered);
        $this->assertStringContainsString('the larger of the two is the one enforced', $rendered);
        $this->assertStringContainsString('categories add a third minimum of their own', $rendered);
    }

    public function test_the_storage_depth_help_describes_the_fallback_rather_than_a_reorg_script(): void
    {
        $rendered = $this->renderSection('release-formation');

        $this->assertStringContainsString('name="nzbsplitlevel"', $rendered);
        $this->assertStringNotContainsString('nzb-reorg', $rendered);
        $this->assertStringContainsString('lookups fall back through the other depths', $rendered);
    }

    public function test_the_retention_card_does_not_claim_one_meaning_for_zero(): void
    {
        $rendered = $this->renderSection('release-formation');

        // partretentionhours resolves a non-positive value to the seeded 72 hours, so a blanket
        // "0 keeps forever" on the card would contradict the very first field under it.
        $this->assertStringContainsString('the incomplete-parts window falls back to its seeded 72 hours', $rendered);
        $this->assertStringContainsString('0 or blank falls back to 72 hours', $rendered);
    }

    public function test_the_header_download_card_saves(): void
    {
        $this->saveCard('usenet-ingest', 'headers', [
            'binaries' => '1',
            'binarythreads' => '4',
            'bins_timer' => '45',
            'maxmssgs' => '25000',
            'max_headers_iteration' => '500000',
            'secondary_header_start_hours' => '36',
        ]);

        $this->assertSame('1', $this->storedSettingValue('binaries'));
        $this->assertSame('4', $this->storedSettingValue('binarythreads'));
        $this->assertSame('25000', $this->storedSettingValue('maxmssgs'));
    }

    public function test_the_backfill_card_saves_including_the_shared_stop_date(): void
    {
        $this->saveCard('usenet-ingest', 'backfill', $this->backfillPayload(['safebackfilldate' => '2015-03-01']));

        $this->assertSame('2015-03-01', $this->storedSettingValue('safebackfilldate'));
        $this->assertSame('2', $this->storedSettingValue('backfill_days'));
        $this->assertSame('1', $this->storedSettingValue('progressive'));
    }

    public function test_the_formation_gate_card_saves_and_converts_its_size_pair(): void
    {
        $this->saveCard('release-formation', 'gates', $this->gatesPayload(['incomplete_release_grace_hours' => '24']));

        $this->assertSame('52428800', $this->storedSettingValue('minsizetoformrelease'));
        $this->assertSame('107374182400', $this->storedSettingValue('maxsizetoformrelease'));
        $this->assertSame('97', $this->storedSettingValue('completionpercent'));
        $this->assertSame('24', $this->storedSettingValue('incomplete_release_grace_hours'));
    }

    public function test_the_incomplete_release_wait_accepts_zero_and_rejects_values_out_of_range(): void
    {
        $this->saveCard('release-formation', 'gates', $this->gatesPayload(['incomplete_release_grace_hours' => '0']));
        $this->assertSame('0', $this->storedSettingValue('incomplete_release_grace_hours'), 'A stored 0 falls back to 72 hours when read.');

        foreach (['-1', '87601'] as $value) {
            try {
                $this->saveCard('release-formation', 'gates', $this->gatesPayload(['incomplete_release_grace_hours' => $value]));
                $this->fail("An incomplete release wait of {$value} hours must be rejected.");
            } catch (ValidationException $exception) {
                $this->assertTrue($exception->validator->errors()->has('incomplete_release_grace_hours'));
            }

            $this->assertSame('0', $this->storedSettingValue('incomplete_release_grace_hours'), 'The stored value survives a rejected save.');
        }
    }

    #[DataProvider('malformedSafeBackfillDates')]
    public function test_backfill_rules_reject_a_date_the_pass_could_not_parse(string $value): void
    {
        try {
            $this->saveCard('usenet-ingest', 'backfill', $this->backfillPayload(['safebackfilldate' => $value]));
            $this->fail('A stop date the backfill pass cannot parse must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Shared stop date', $exception->validator->errors()->first('safebackfilldate'));
        }

        $this->assertSame('2012-06-24', $this->storedSettingValue('safebackfilldate'), 'The seeded value survives a rejected save.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedSafeBackfillDates(): array
    {
        return MalformedSafeBackfillDates::cases();
    }

    public function test_nzb_rules_reject_a_storage_depth_no_path_was_written_at(): void
    {
        $tooDeep = (string) (NzbService::MAX_SPLIT_LEVEL + 1);

        try {
            $this->saveCard('release-formation', 'nzb-storage', [
                'maxnzbsprocessed' => '1000',
                'nzbsplitlevel' => $tooDeep,
            ]);
            $this->fail('A storage depth beyond the write path\'s fan-out must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Storage depth', $exception->validator->errors()->first('nzbsplitlevel'));
        }

        $this->assertSame('4', $this->storedSettingValue('nzbsplitlevel'));

        $this->saveCard('release-formation', 'nzb-storage', [
            'maxnzbsprocessed' => '1000',
            'nzbsplitlevel' => '0',
        ]);

        $this->assertSame('0', $this->storedSettingValue('nzbsplitlevel'), 'Zero is the legal store-flat depth.');
    }

    public function test_the_nzb_and_backfill_rules_come_from_the_existing_classes(): void
    {
        $registry = app(SettingsRegistry::class);

        $this->assertSame(
            NzbSettingRules::rules()['nzbsplitlevel'],
            $registry->definition('nzbsplitlevel')?->validationRules()['nzbsplitlevel'],
        );
        $this->assertSame(
            BackfillSettingRules::rules()['safebackfilldate'],
            $registry->definition('safebackfilldate')?->validationRules()['safebackfilldate'],
        );
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function gatesPayload(array $overrides = []): array
    {
        return array_merge([
            'minfilestoformrelease' => '2',
            'minsizetoformrelease' => '50',
            'minsizetoformrelease_unit' => 'MB',
            'maxsizetoformrelease' => '100',
            'maxsizetoformrelease_unit' => 'GB',
            'completionpercent' => '97',
            'incomplete_release_grace_hours' => '72',
            'delaytime' => '2',
            'collection_timeout' => '48',
            'crossposttime' => '2',
        ], $overrides);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function backfillPayload(array $overrides = []): array
    {
        return array_merge([
            'backfill' => '1',
            'backfill_order' => '2',
            'backfill_days' => '2',
            'safebackfilldate' => '2012-06-24',
            'disablebackfillgroup' => '1',
            'backfill_qty' => '100000',
            'backfillthreads' => '1',
            'back_timer' => '30',
            'progressive' => '1',
        ], $overrides);
    }
}
