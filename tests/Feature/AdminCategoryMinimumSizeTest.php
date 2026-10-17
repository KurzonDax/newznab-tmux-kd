<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ReadsRenderedMarkup;
use Tests\TestCase;

/**
 * Issue #1109: the admin category Add and Edit pages set `categories.minsizetoformrelease` again.
 * Round-trip tests submit the form as the page renders it, never the values first posted.
 */
final class AdminCategoryMinimumSizeTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;
    use ReadsRenderedMarkup;

    private const string ADD_PAGE = '/admin/category-add';

    private const string HELP_TEXT = '0 means no category minimum; the site and group minimums still apply. Releases in this category smaller than this are deleted, including ones already indexed.';

    private ?User $administrator = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
    }

    protected function tearDown(): void
    {
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_edit_stores_the_submitted_minimum_in_bytes(): void
    {
        $this->createCategory(2010, 0);

        $this->signedIn()->post('/admin/category-edit?action=submit', [
            'id' => '2010',
            'root_categories_id' => '1',
            'description' => 'Invented category',
            'minsizetoformrelease' => '150',
            'minsizetoformrelease_unit' => 'MB',
        ])->assertRedirect('/admin/category-list');

        $this->assertSame(157286400, $this->storedMinimum(2010));
    }

    public function test_add_stores_the_submitted_minimum_in_bytes(): void
    {
        $this->signedIn()->post('/admin/category-add?action=submit', [
            'id' => '2020',
            'title' => 'Invented Added',
            'root_categories_id' => '1',
            'description' => 'Invented category',
            'minsizetoformrelease' => '150',
            'minsizetoformrelease_unit' => 'MB',
        ])->assertRedirect('/admin/category-list');

        $this->assertSame(157286400, $this->storedMinimum(2020));
    }

    public function test_add_page_starts_at_zero_megabytes_with_the_help_text(): void
    {
        $html = $this->page(self::ADD_PAGE);

        $this->assertSame(['value' => '0', 'unit' => 'MB'], $this->renderedMinimum($html));
        $this->assertStringContainsString(self::HELP_TEXT, $html);
    }

    public function test_edit_page_shows_a_whole_gigabyte_minimum_in_gb_and_an_unchanged_save_keeps_it(): void
    {
        $this->createCategory(2030, 2147483648);

        $html = $this->page($this->editPage(2030));
        $this->assertSame(['value' => '2', 'unit' => 'GB'], $this->renderedMinimum($html));
        $this->assertStringContainsString(self::HELP_TEXT, $html);

        $this->submitRendered($html)->assertRedirect('/admin/category-list');

        $this->assertSame(2147483648, $this->storedMinimum(2030));
    }

    public function test_edit_with_an_unsupported_unit_is_a_validation_error_and_keeps_the_stored_minimum(): void
    {
        $this->createCategory(2040, 157286400);

        $this->signedIn()->from($this->editPage(2040))->post('/admin/category-edit?action=submit', [
            'id' => '2040',
            'root_categories_id' => '1',
            'description' => 'Changed description',
            'minsizetoformrelease' => '5',
            'minsizetoformrelease_unit' => 'TB',
        ])->assertRedirect($this->editPage(2040))->assertSessionHasErrors('minsizetoformrelease');

        $this->assertSame(157286400, $this->storedMinimum(2040));
        $this->assertSame('Invented category', DB::table('categories')->where('id', 2040)->value('description'));
    }

    public function test_add_with_an_unsupported_unit_is_a_validation_error_and_creates_nothing(): void
    {
        $this->signedIn()->from(self::ADD_PAGE)->post('/admin/category-add?action=submit', [
            'id' => '2045',
            'title' => 'Invented Added',
            'minsizetoformrelease' => '5',
            'minsizetoformrelease_unit' => 'TB',
        ])->assertRedirect(self::ADD_PAGE)->assertSessionHasErrors('minsizetoformrelease');

        $this->assertFalse(DB::table('categories')->where('id', 2045)->exists());
    }

    public function test_an_empty_minimum_stores_zero(): void
    {
        $this->createCategory(2050, 157286400);

        $this->signedIn()->post('/admin/category-edit?action=submit', [
            'id' => '2050',
            'root_categories_id' => '1',
            'description' => 'Invented category',
            'minsizetoformrelease' => '',
            'minsizetoformrelease_unit' => 'MB',
        ])->assertRedirect('/admin/category-list');

        $this->assertSame(0, $this->storedMinimum(2050));
    }

    /**
     * Submitted value, unit, stored bytes, and the value the Edit page then shows in MB.
     *
     * @return array<string, array{string, string, int, string}>
     */
    public static function fractionalMinimums(): array
    {
        return [
            'thousandth of a megabyte' => ['0.001', 'MB', 1049, '0.001'],
            'six thousandths of a megabyte' => ['0.006', 'MB', 6291, '0.006'],
            'thousandth of a gigabyte' => ['0.001', 'GB', 1073742, '1.024'],
        ];
    }

    #[DataProvider('fractionalMinimums')]
    public function test_a_fractional_minimum_survives_an_edit_that_changes_only_the_description(string $value, string $unit, int $bytes, string $shown): void
    {
        $this->createCategory(2060, 0);

        $this->signedIn()->post('/admin/category-edit?action=submit', [
            'id' => '2060',
            'root_categories_id' => '1',
            'description' => 'Invented category',
            'minsizetoformrelease' => $value,
            'minsizetoformrelease_unit' => $unit,
        ])->assertRedirect('/admin/category-list');
        $this->assertSame($bytes, $this->storedMinimum(2060));

        $html = $this->page($this->editPage(2060));
        $this->assertSame(['value' => $shown, 'unit' => 'MB'], $this->renderedMinimum($html));

        $this->submitRendered($html, ['description' => 'Only the description changed'])
            ->assertRedirect('/admin/category-list');

        $this->assertSame('Only the description changed', DB::table('categories')->where('id', 2060)->value('description'));
        $this->assertSame($bytes, $this->storedMinimum(2060));
    }

    public function test_a_failed_add_keeps_the_submitted_minimum_and_the_corrected_retry_saves_it(): void
    {
        $this->createCategory(2070, 0);

        $this->signedIn()->from(self::ADD_PAGE)->post('/admin/category-add?action=submit', [
            'id' => '2070',
            'title' => 'Invented Added',
            'root_categories_id' => '1',
            'description' => '',
            'minsizetoformrelease' => '2',
            'minsizetoformrelease_unit' => 'GB',
        ])->assertRedirect(self::ADD_PAGE);

        $html = $this->page(self::ADD_PAGE);
        $this->assertStringContainsString('Category ID 2070 already exists.', $html);
        $this->assertSame(['value' => '2', 'unit' => 'GB'], $this->renderedMinimum($html));

        $this->submitRendered($html, ['id' => '2071'])->assertRedirect('/admin/category-list');

        $this->assertSame(2147483648, $this->storedMinimum(2071));
        $this->assertSame('Invented Added', DB::table('categories')->where('id', 2071)->value('title'));
        $this->assertSame(0, $this->storedMinimum(2070));
        $this->assertSame('Invented 2070', DB::table('categories')->where('id', 2070)->value('title'));
    }

    public function test_a_failed_edit_shows_the_submitted_empty_minimum_instead_of_the_stored_one(): void
    {
        $this->createCategory(2080, 2147483648);

        $this->signedIn()->from($this->editPage(2080))->post('/admin/category-edit?action=submit', [
            'id' => '2080',
            'root_categories_id' => '1',
            'description' => 'Invented category',
            'minsizetoformrelease' => '',
            'minsizetoformrelease_unit' => 'TB',
        ])->assertRedirect($this->editPage(2080));

        $html = $this->page($this->editPage(2080));

        // the unsupported unit is not offered back; the stored unit stands in for it
        $this->assertSame(['value' => '', 'unit' => 'GB'], $this->renderedMinimum($html));
        $this->assertStringContainsString('Unsupported size unit [TB].', $html);
        $this->assertSame(2147483648, $this->storedMinimum(2080));
    }

    public function test_flashed_value_and_supported_unit_override_the_stored_minimum_on_edit(): void
    {
        $this->createCategory(2090, 2147483648);

        $html = (string) $this->signedIn()
            ->withSession(['_old_input' => ['minsizetoformrelease' => '750', 'minsizetoformrelease_unit' => 'MB']])
            ->get($this->editPage(2090))->assertOk()->getContent();

        $this->assertSame(['value' => '750', 'unit' => 'MB'], $this->renderedMinimum($html));
    }

    public function test_flashed_value_and_supported_unit_override_the_default_on_add(): void
    {
        $html = (string) $this->signedIn()
            ->withSession(['_old_input' => ['minsizetoformrelease' => '3', 'minsizetoformrelease_unit' => 'GB']])
            ->get(self::ADD_PAGE)->assertOk()->getContent();

        $this->assertSame(['value' => '3', 'unit' => 'GB'], $this->renderedMinimum($html));
    }

    private function createCategory(int $id, int $minimumBytes): void
    {
        DB::table('categories')->insert([
            'id' => $id,
            'title' => 'Invented '.$id,
            'root_categories_id' => 1,
            'description' => 'Invented category',
            'status' => 1,
            'minsizetoformrelease' => $minimumBytes,
            'maxsizetoformrelease' => 0,
        ]);
    }

    private function storedMinimum(int $id): int
    {
        return (int) DB::table('categories')->where('id', $id)->value('minsizetoformrelease');
    }

    private function editPage(int $id): string
    {
        return '/admin/category-edit?id='.$id;
    }

    /**
     * The same signed-in administrator for every request of one test.
     */
    private function signedIn(): static
    {
        $this->administrator ??= $this->admin();

        return $this->actingAs($this->administrator);
    }

    private function page(string $url): string
    {
        return (string) $this->signedIn()->get($url)->assertOk()->getContent();
    }

    /**
     * @return array{value: string, unit: string}
     */
    private function renderedMinimum(string $html): array
    {
        $fields = $this->renderedFields($html);

        return ['value' => $fields['minsizetoformrelease'], 'unit' => $fields['minsizetoformrelease_unit']];
    }

    /**
     * Every named field of the category form with the value a browser would submit.
     *
     * @return array<string, string>
     */
    private function renderedFields(string $html): array
    {
        $xpath = $this->xpath($html);
        $forms = $this->elements($xpath, '//form[@id="categoryForm"]');
        $this->assertCount(1, $forms);

        $fields = [];
        foreach ($this->elements($xpath, './/input[@name]', $forms[0]) as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        foreach ($this->elements($xpath, './/select[@name]', $forms[0]) as $select) {
            $options = $this->elements($xpath, './/option[@selected]', $select) ?: $this->elements($xpath, './/option', $select);
            $fields[$select->getAttribute('name')] = $options[0]->getAttribute('value');
        }

        $this->assertArrayHasKey('minsizetoformrelease', $fields);
        $this->assertArrayHasKey('minsizetoformrelease_unit', $fields);

        return $fields;
    }

    /**
     * Submit the rendered category form to its own action, with only the given fields changed.
     *
     * @param  array<string, string>  $changes
     * @return TestResponse<Response>
     */
    private function submitRendered(string $html, array $changes = []): TestResponse
    {
        $xpath = $this->xpath($html);
        $action = $this->elements($xpath, '//form[@id="categoryForm"]')[0]->getAttribute('action');

        return $this->signedIn()->post($action, array_merge($this->renderedFields($html), $changes));
    }
}
