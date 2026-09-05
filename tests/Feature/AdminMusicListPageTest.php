<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SecondarySearchIndex;
use App\Facades\Search;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * Coverage for the admin music list (#450).
 *
 * The controller captured `musicsearch` and then called the search-less `getRange()` helper in
 * both branches, so the page returned the full table while presenting the search as active, and
 * the view rendered no paginator at all.
 *
 * Manticore is not reachable from the test suite, so the secondary-index path runs against a
 * faked `Search` facade and the `LIKE` fallback runs with `isAvailable()` false -- the path that
 * has to work with no search backend at all.
 */
class AdminMusicListPageTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->createMusicSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_music_search_filters_the_list_through_the_like_fallback(): void
    {
        Search::shouldReceive('isAvailable')->andReturnFalse();

        $this->createMusic([
            ['title' => 'Kind Of Blue', 'artist' => 'Miles Davis'],
            ['title' => 'Blue Train', 'artist' => 'John Coltrane'],
            ['title' => 'Unrelated Record', 'artist' => 'Someone Else'],
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.music-list', ['musicsearch' => 'Blue']));

        $response->assertOk();
        $response->assertSee('Kind Of Blue');
        $response->assertSee('Blue Train');
        $response->assertDontSee('Unrelated Record');
    }

    public function test_music_search_matches_an_artist_through_the_like_fallback(): void
    {
        Search::shouldReceive('isAvailable')->andReturnFalse();

        $this->createMusic([
            ['title' => 'Kind Of Blue', 'artist' => 'Miles Davis'],
            ['title' => 'Unrelated Record', 'artist' => 'Someone Else'],
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.music-list', ['musicsearch' => 'Miles']));

        $response->assertOk();
        $response->assertSee('Kind Of Blue');
        $response->assertDontSee('Unrelated Record');
    }

    public function test_music_search_uses_the_secondary_index_when_it_is_available(): void
    {
        $this->createMusic([
            ['title' => 'Indexed Record', 'artist' => 'Indexed Artist'],
            ['title' => 'Unrelated Record', 'artist' => 'Someone Else'],
        ]);

        $indexedId = (int) DB::table('musicinfo')->where('title', 'Indexed Record')->value('id');

        Search::shouldReceive('isAvailable')->andReturnTrue();
        Search::shouldReceive('searchSecondary')
            ->once()
            ->with(SecondarySearchIndex::Music, 'Indexed', 3000)
            ->andReturn(['id' => [$indexedId]]);

        $response = $this->actingAs($this->admin())->get(route('admin.music-list', ['musicsearch' => 'Indexed']));

        $response->assertOk();
        $response->assertSee('Indexed Record');
        $response->assertDontSee('Unrelated Record');
    }

    public function test_music_search_matching_nothing_renders_the_empty_state(): void
    {
        Search::shouldReceive('isAvailable')->andReturnFalse();

        $this->createMusic([
            ['title' => 'Kind Of Blue', 'artist' => 'Miles Davis'],
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.music-list', ['musicsearch' => 'Nothing Matches']));

        $response->assertOk();
        $response->assertSee('No music found matching "Nothing Matches".', false);
        $response->assertDontSee('Kind Of Blue');
    }

    public function test_an_empty_music_search_still_lists_everything_newest_first(): void
    {
        $this->createMusic([
            ['title' => 'Older Record', 'artist' => 'Artist One'],
            ['title' => 'Newer Record', 'artist' => 'Artist Two'],
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.music-list', ['musicsearch' => '']));

        $response->assertOk();
        $response->assertSee('Newer Record');
        $response->assertSee('Older Record');
        $response->assertSeeInOrder(['Newer Record', 'Older Record']);
    }

    public function test_music_list_renders_one_paginator_reaching_the_second_page(): void
    {
        $this->createMusic([
            ['title' => 'Oldest Record', 'artist' => 'Artist One'],
            ['title' => 'Middle Record', 'artist' => 'Artist Two'],
            ['title' => 'Newest Record', 'artist' => 'Artist Three'],
        ]);
        $admin = $this->admin();

        $firstPage = $this->actingAs($admin)->get(route('admin.music-list'));

        $firstPage->assertOk();
        $this->assertSame(
            1,
            substr_count((string) $firstPage->getContent(), 'aria-label="Pagination Navigation"'),
            'The music list must render exactly one paginator.'
        );
        $firstPage->assertSee('page=2', false);
        $firstPage->assertDontSee('Oldest Record');

        $secondPage = $this->actingAs($admin)->get(route('admin.music-list', ['page' => 2]));

        $secondPage->assertOk();
        $secondPage->assertSee('Oldest Record');
        $secondPage->assertDontSee('Newest Record');
    }

    public function test_music_pagination_keeps_the_active_search(): void
    {
        Search::shouldReceive('isAvailable')->andReturnFalse();

        $this->createMusic([
            ['title' => 'Match Oldest', 'artist' => 'Artist One'],
            ['title' => 'Match Middle', 'artist' => 'Artist Two'],
            ['title' => 'Match Newest', 'artist' => 'Artist Three'],
            ['title' => 'Other Record', 'artist' => 'Artist Four'],
        ]);
        $admin = $this->admin();

        $firstPage = $this->actingAs($admin)->get(route('admin.music-list', ['musicsearch' => 'Match']));

        $firstPage->assertOk();
        $firstPage->assertSee('musicsearch=Match', false);
        $firstPage->assertSee('page=2', false);
        $firstPage->assertDontSee('Other Record');

        $secondPage = $this->actingAs($admin)
            ->get(route('admin.music-list', ['musicsearch' => 'Match', 'page' => 2]));

        $secondPage->assertOk();
        $secondPage->assertSee('Match Oldest');
        $secondPage->assertDontSee('Other Record');
    }

    public function test_music_list_renders_no_paginator_for_a_single_page(): void
    {
        $this->createMusic([
            ['title' => 'Only Record', 'artist' => 'Only Artist'],
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.music-list'));

        $response->assertOk();
        $response->assertSee('Only Record');
        $response->assertDontSee('aria-label="Pagination Navigation"', false);
    }

    public function test_music_list_renders_the_empty_state_without_a_paginator(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.music-list'));

        $response->assertOk();
        $response->assertSee('No music found in the database.');
        $response->assertDontSee('aria-label="Pagination Navigation"', false);
    }

    /**
     * Rows are created oldest first; the listing orders newest first.
     *
     * @param  list<array{title: string, artist: string}>  $entries
     */
    private function createMusic(array $entries): void
    {
        $offset = DB::table('musicinfo')->count();
        $rows = [];

        foreach (array_values($entries) as $index => $entry) {
            $rows[] = [
                'title' => $entry['title'],
                'artist' => $entry['artist'],
                'asin' => 'asin-'.($offset + $index),
                'publisher' => 'Publisher '.($offset + $index),
                'year' => '2001',
                'genres_id' => 1,
                'tracks' => 'Track One',
                'cover' => 1,
                'created_at' => now()->addMinutes($offset + $index),
                'updated_at' => now()->addMinutes($offset + $index),
            ];
        }

        DB::table('musicinfo')->insert($rows);
    }

    private function createMusicSchema(): void
    {
        Schema::create('genres', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->integer('type')->nullable();
            $table->boolean('disabled')->default(false);
        });

        Schema::create('musicinfo', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('asin', 128)->nullable();
            $table->string('url', 1000)->nullable();
            $table->unsignedInteger('salesrank')->nullable();
            $table->string('artist')->nullable();
            $table->string('publisher')->nullable();
            $table->dateTime('releasedate')->nullable();
            $table->string('review', 3000)->nullable();
            $table->string('year', 4);
            $table->integer('genres_id')->nullable();
            $table->string('tracks', 3000)->nullable();
            $table->boolean('cover')->default(false);
            $table->timestamps();
        });

        DB::table('genres')->insert([
            'id' => 1,
            'title' => 'Jazz',
            'type' => 3000,
            'disabled' => false,
        ]);
    }
}
