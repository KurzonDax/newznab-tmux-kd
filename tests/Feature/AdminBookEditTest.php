<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * Coverage for the book edit form (#458).
 *
 * The last site still writing `Carbon::parse(...)->timestamp` into a datetime column, and it
 * passed `id` straight into `BookService::update(int $id, ...)`, so every submission failed
 * before reaching the database.
 *
 * Payloads are what the real form posts: every value a string, blank fields as ''.
 */
class AdminBookEditTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->createBookInfoTable();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_book_edit_stores_a_submitted_publish_date(): void
    {
        $id = $this->createBook('2001-01-01 13:45:00');

        $response = $this->actingAs($this->admin())->post(route('admin.book-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => 'Book Under Edit',
            'author' => 'An Author',
            'publisher' => 'A Publisher',
            'asin' => 'book-asin',
            'url' => 'https://example.test/book',
            'publishdate' => '2015-06-09',
        ]);

        $response->assertRedirect(route('admin.book-list'));

        $stored = DB::table('bookinfo')->where('id', $id)->value('publishdate');

        $this->assertSame('2015-06-09 00:00:00', Carbon::parse((string) $stored)->toDateTimeString());
    }

    public function test_book_edit_preserves_an_untouched_publish_date_including_its_time(): void
    {
        $id = $this->createBook('2001-01-01 13:45:00');

        $response = $this->actingAs($this->admin())->post(route('admin.book-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => 'Book Under Edit',
            'author' => 'An Author',
            'publishdate' => '',
        ]);

        $response->assertRedirect(route('admin.book-list'));

        $stored = DB::table('bookinfo')->where('id', $id)->value('publishdate');

        $this->assertSame('2001-01-01 13:45:00', Carbon::parse((string) $stored)->toDateTimeString());
    }

    /**
     * bookinfo.author is NOT NULL, and ConvertEmptyStringsToNull blanks the field to null.
     */
    public function test_book_edit_rejects_a_blank_author_rather_than_failing_on_the_constraint(): void
    {
        $id = $this->createBook('2001-01-01 13:45:00');

        $response = $this->actingAs($this->admin())->post(route('admin.book-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => 'Book Under Edit',
            'author' => '',
            'publishdate' => '',
        ]);

        $response->assertSessionHasErrors('author');
        $this->assertSame('An Author', (string) DB::table('bookinfo')->where('id', $id)->value('author'));
    }

    public function test_book_edit_rejects_a_blank_title(): void
    {
        $id = $this->createBook('2001-01-01 13:45:00');

        $response = $this->actingAs($this->admin())->post(route('admin.book-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => '',
            'author' => 'An Author',
            'publishdate' => '',
        ]);

        $response->assertSessionHasErrors('title');
        $this->assertSame('Book Under Edit', (string) DB::table('bookinfo')->where('id', $id)->value('title'));
    }

    private function createBook(?string $publishdate): int
    {
        return (int) DB::table('bookinfo')->insertGetId([
            'title' => 'Book Under Edit',
            'author' => 'An Author',
            'asin' => 'book-asin',
            'publisher' => 'A Publisher',
            'publishdate' => $publishdate,
            'genre' => 'Fiction',
            'cover' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
