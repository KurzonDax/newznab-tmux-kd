<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * Coverage for the AniDB edit form (#458).
 *
 * `AnidbService::updateTitle()` takes `int $anidbID` and twelve non-nullable strings, and the
 * controller passed every one of them straight from the request. `anidbid` alone broke every
 * submission, and the form carries one `required` attribute in total, so any blank field was a
 * TypeError behind it.
 *
 * Payloads are what the real form posts: every value a string, blank fields as ''.
 */
class AdminAnidbEditTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;

    private const ANIDB_ID = 4242;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->createAnidbTables();
        $this->seedAnidbEntry();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_anidb_edit_stores_the_submitted_fields(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.anidb-edit', ['id' => self::ANIDB_ID]), [
            'action' => 'submit',
            'anidbid' => (string) self::ANIDB_ID,
            'title' => 'Edited Title',
            'type' => 'TV Series',
            'startdate' => '2015-06-09',
            'enddate' => '2015-09-09',
            'related' => 'Some related',
            'similar' => 'Some similar',
            'creators' => 'A Creator',
            'description' => 'A description',
            'rating' => '8.5',
            'categories' => 'Action',
            'characters' => 'A Character',
        ]);

        $response->assertRedirect(route('admin.anidb-list'));

        $this->assertSame('Edited Title', $this->displayedTitle());

        $info = DB::table('anidb_info')->where('anidbid', self::ANIDB_ID)->first();

        $this->assertSame('TV Series', (string) $info->type, 'The media type belongs to anidb_info.');
        $this->assertSame('2015-06-09', substr((string) $info->startdate, 0, 10));
        $this->assertSame('2015-09-09', substr((string) $info->enddate, 0, 10));
        $this->assertSame('Some related', (string) $info->related);
        $this->assertSame('Some similar', (string) $info->similar);
        $this->assertSame('A Creator', (string) $info->creators);
        $this->assertSame('A description', (string) $info->description);
        $this->assertSame('8.5', (string) $info->rating);
        $this->assertSame('Action', (string) $info->categories);
        $this->assertSame('A Character', (string) $info->characters);
    }

    /**
     * The romaji and native titles are separate rows and must survive an edit of the English
     * one. An update filtered on anidbid alone rewrites all three, or collides on the primary
     * key (anidbid, type, lang, title).
     */
    public function test_anidb_edit_leaves_the_other_language_titles_alone(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.anidb-edit', ['id' => self::ANIDB_ID]), [
            'action' => 'submit',
            'anidbid' => (string) self::ANIDB_ID,
            'title' => 'Edited English Title',
            'type' => 'Movie',
        ]);

        $response->assertRedirect(route('admin.anidb-list'));

        $titles = DB::table('anidb_titles')
            ->where('anidbid', self::ANIDB_ID)
            ->orderBy('lang')
            ->pluck('title', 'lang')
            ->all();

        $this->assertSame('Edited English Title', $titles['en']);
        $this->assertSame('Romaji Title', $titles['x-jat'], 'The romaji title must not be overwritten.');
        $this->assertSame('Native Title', $titles['ja'], 'The native title must not be overwritten.');
        $this->assertCount(3, $titles, 'No title row may be lost.');
    }

    /**
     * anidb_titles.type is the title type (main/official/synonym), not the media type.
     */
    public function test_anidb_edit_does_not_rewrite_the_title_type(): void
    {
        $this->actingAs($this->admin())->post(route('admin.anidb-edit', ['id' => self::ANIDB_ID]), [
            'action' => 'submit',
            'anidbid' => (string) self::ANIDB_ID,
            'title' => 'Edited English Title',
            'type' => 'Movie',
        ])->assertRedirect(route('admin.anidb-list'));

        $this->assertSame(
            'official',
            (string) DB::table('anidb_titles')
                ->where('anidbid', self::ANIDB_ID)
                ->where('lang', 'en')
                ->value('type')
        );
        $this->assertSame('Movie', (string) DB::table('anidb_info')->where('anidbid', self::ANIDB_ID)->value('type'));
    }

    /**
     * The English row is the one getAnimeInfo() shows, so it is the one written back.
     */
    private function displayedTitle(): string
    {
        return (string) DB::table('anidb_titles')
            ->where('anidbid', self::ANIDB_ID)
            ->where('lang', 'en')
            ->value('title');
    }

    /**
     * The form marks only one field required, so every other one can arrive blank -- as null,
     * through ConvertEmptyStringsToNull, into a non-nullable string parameter.
     */
    public function test_anidb_edit_accepts_blank_optional_fields(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.anidb-edit', ['id' => self::ANIDB_ID]), [
            'action' => 'submit',
            'anidbid' => (string) self::ANIDB_ID,
            'title' => 'Edited Title',
            'type' => 'TV Series',
            'startdate' => '',
            'enddate' => '',
            'related' => '',
            'similar' => '',
            'creators' => '',
            'description' => '',
            'rating' => '',
            'categories' => '',
            'characters' => '',
        ]);

        $response->assertRedirect(route('admin.anidb-list'));
        $this->assertSame('Edited Title', $this->displayedTitle());
    }

    public function test_anidb_edit_rejects_a_blank_title(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.anidb-edit', ['id' => self::ANIDB_ID]), [
            'action' => 'submit',
            'anidbid' => (string) self::ANIDB_ID,
            'title' => '',
            'type' => 'TV Series',
        ]);

        $response->assertSessionHasErrors('title');
        $this->assertSame('Original Title', $this->displayedTitle());
    }

    private function seedAnidbEntry(): void
    {
        // PopulateAniListService writes up to three rows per anidbid: romaji, English, native.
        // A single-row seed hides an update that collapses them.
        DB::table('anidb_titles')->insert([
            ['anidbid' => self::ANIDB_ID, 'type' => 'main', 'lang' => 'x-jat', 'title' => 'Romaji Title'],
            ['anidbid' => self::ANIDB_ID, 'type' => 'official', 'lang' => 'en', 'title' => 'Original Title'],
            ['anidbid' => self::ANIDB_ID, 'type' => 'main', 'lang' => 'ja', 'title' => 'Native Title'],
        ]);

        DB::table('anidb_info')->insert([
            'anidbid' => self::ANIDB_ID,
            'type' => 'TV Series',
            'startdate' => '2001-01-01',
            'enddate' => '2001-03-01',
            'description' => 'Original description',
            'rating' => '7.0',
            'updated' => now(),
        ]);
    }
}
