<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class PersonalListsPresentationTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_movie_titles_link_to_the_working_details_route_on_desktop_and_mobile(): void
    {
        $html = (string) $this->view('mymovies.index', [
            ...$this->viewData(),
            'movies' => [['imdbid' => '0137523', 'title' => 'Example Movie']],
        ]);

        $this->assertSame(2, substr_count($html, 'href="'.route('movie.view', ['imdbid' => '0137523']).'"'));
        $this->assertStringNotContainsString('/Movies?imdb=', $html);
    }

    public function test_add_movie_page_shows_the_category_form_without_an_unrelated_empty_list(): void
    {
        $this->view('mymovies.add', [
            ...$this->viewData(),
            'type' => 'add',
            'imdbid' => '0137523',
            'movie' => ['title' => 'Example Movie'],
            'cat_ids' => [2040],
            'cat_names' => [2040 => 'HD'],
        ])
            ->assertSee('Choose Categories:')
            ->assertSee('value="2040"', false)
            ->assertDontSee('No movies bookmarked yet.')
            ->assertDontSee('id="sortable"', false);
    }

    /** @return array<string, mixed> */
    private function viewData(): array
    {
        return [
            'site' => ['home_link' => '/', 'dereferrer_link' => ''],
            'userdata' => (object) ['id' => 1, 'api_token' => 'example-token'],
        ];
    }
}
