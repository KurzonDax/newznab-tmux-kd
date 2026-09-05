<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Services\Tmux\TmuxTaskRunner;
use App\Support\Settings\SettingsRegistry;
use Database\Seeders\SettingsTableSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\Settings\InteractsWithSettingsHub;
use Tests\TestCase;

/**
 * The worker-count and timeout bounds that used to live in UpdateTmuxSettingsRequest.
 *
 * Those bounds are not cosmetic: a thread count of 0 gives a pane that never starts, and one
 * of 500 opens 500 NNTP sessions against a provider that allows a few dozen. The old tmux form
 * was the only thing enforcing them; the registry enforces them now, and this pins that the
 * move did not lose a single bound.
 */
final class SettingsWorkerBoundsTest extends TestCase
{
    use InteractsWithSettingsHub;
    use IsolatedSqliteDatabase;

    /**
     * Field => [section, card, maximum accepted worker count].
     *
     * @var array<string, array{0: string, 1: string, 2: int}>
     */
    private const array WORKER_FIELDS = [
        'binarythreads' => ['usenet-ingest', 'headers', 99],
        'backfillthreads' => ['usenet-ingest', 'backfill', 99],
        'releasethreads' => ['release-formation', 'releases-pane', 99],
        'postthreads' => ['post-processing', 'additional', 99],
        'nfothreads' => ['post-processing', 'nfo', 16],
        'postthreadsnon' => ['metadata-lookups', 'video-panes', 99],
        'postthreadsamazon' => ['metadata-lookups', 'shelf-pane', 99],
        'fixnamethreads' => ['naming-hygiene', 'fix-names', 16],
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
        Cache::flush();

        $this->resetGlobalComposerState();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function workerFields(): iterable
    {
        foreach (array_keys(self::WORKER_FIELDS) as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('workerFields')]
    public function test_a_worker_count_is_accepted_at_both_of_its_boundaries(string $field): void
    {
        [$section, $card, $maximum] = self::WORKER_FIELDS[$field];

        $this->saveCard($section, $card, $this->currentCardPayload($section, $card, [$field => '1']));
        $this->assertSame('1', $this->storedSettingValue($field));

        $this->saveCard($section, $card, $this->currentCardPayload($section, $card, [$field => (string) $maximum]));
        $this->assertSame((string) $maximum, $this->storedSettingValue($field));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedWorkerCounts(): iterable
    {
        foreach (self::WORKER_FIELDS as $field => [, , $maximum]) {
            yield $field.' zero' => [$field, '0'];
            yield $field.' negative' => [$field, '-1'];
            yield $field.' non-integer' => [$field, 'abc'];
            yield $field.' decimal' => [$field, '1.5'];
            yield $field.' over maximum' => [$field, (string) ($maximum + 1)];
            yield $field.' blank' => [$field, ''];
        }
    }

    #[DataProvider('rejectedWorkerCounts')]
    public function test_a_worker_count_outside_its_bounds_is_rejected_and_writes_nothing(string $field, string $value): void
    {
        [$section, $card] = self::WORKER_FIELDS[$field];

        $before = $this->cardSettings($section, $card);

        try {
            $this->saveCard($section, $card, $this->currentCardPayload($section, $card, [$field => $value]));
            $this->fail($field.' must reject ['.$value.'].');
        } catch (ValidationException $exception) {
            $this->assertTrue($exception->validator->errors()->has($field));
        }

        $this->assertSame($before, $this->cardSettings($section, $card), 'A rejected card writes none of its fields.');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function fixNamesTimeouts(): iterable
    {
        yield 'floor' => [(string) TmuxTaskRunner::MIN_FIX_NAMES_TIMEOUT, true];
        yield 'default' => ['1200', true];
        yield 'large' => ['86400', true];
        yield 'below floor' => [(string) (TmuxTaskRunner::MIN_FIX_NAMES_TIMEOUT - 1), false];
        yield 'zero' => ['0', false];
        yield 'blank' => ['', false];
        yield 'non-integer' => ['soon', false];
        yield 'decimal' => ['60.5', false];
    }

    #[DataProvider('fixNamesTimeouts')]
    public function test_the_fix_names_step_timeout_holds_its_floor(string $value, bool $accepted): void
    {
        $payload = $this->currentCardPayload('naming-hygiene', 'fix-names', ['fix_names_timeout' => $value]);

        if ($accepted) {
            $this->saveCard('naming-hygiene', 'fix-names', $payload);
            $this->assertSame($value, $this->storedSettingValue('fix_names_timeout'));

            return;
        }

        $before = $this->storedSettingValue('fix_names_timeout');

        try {
            $this->saveCard('naming-hygiene', 'fix-names', $payload);
            $this->fail('fix_names_timeout must reject ['.$value.'].');
        } catch (ValidationException $exception) {
            $this->assertTrue($exception->validator->errors()->has('fix_names_timeout'));
        }

        $this->assertSame($before, $this->storedSettingValue('fix_names_timeout'));
    }

    public function test_every_worker_field_still_lives_on_the_page_that_owns_its_pane(): void
    {
        foreach (self::WORKER_FIELDS as $field => [$section, $card]) {
            $rendered = $this->renderSection($section);

            $this->assertStringContainsString('name="'.$field.'"', $rendered, $field.' is missing from '.$section);
            $this->assertSame($card, app(SettingsRegistry::class)->locate($field)?->card->id);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function cardSettings(string $section, string $card): array
    {
        $settingCard = app(SettingsRegistry::class)->card($section, $card);

        $this->assertNotNull($settingCard);

        $values = [];

        foreach ($settingCard->settings as $definition) {
            $stored = DB::table('settings')->where('name', $definition->key)->value('value');
            $values[$definition->key] = $stored === null ? null : (string) $stored;
        }

        return $values;
    }
}
