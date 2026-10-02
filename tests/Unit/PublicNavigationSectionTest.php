<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BrowseRoot;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PublicNavigationSectionTest extends TestCase
{
    /** @return iterable<string, array{string, ?int, ?BrowseRoot}> */
    public static function pages(): iterable
    {
        yield 'movies list' => ['/movies', null, BrowseRoot::Movies];
        yield 'films wall' => ['/movies/films', null, BrowseRoot::Movies];
        yield 'film page' => ['/movies/film/12', null, BrowseRoot::Movies];
        yield 'tv list' => ['/tv', null, BrowseRoot::Tv];
        yield 'tv shows' => ['/tv/shows', null, BrowseRoot::Tv];
        yield 'tv show page' => ['/tv/show/12', null, BrowseRoot::Tv];
        yield 'adult list' => ['/adult', null, BrowseRoot::Adult];
        yield 'books list' => ['/books', null, BrowseRoot::Books];
        yield 'pc list' => ['/pc', null, BrowseRoot::Games];
        yield 'books browse' => ['/browse/books', null, BrowseRoot::Books];
        yield 'books sub-category' => ['/browse/books/7010', null, BrowseRoot::Books];
        yield 'pc browse' => ['/browse/pc', null, BrowseRoot::Games];
        yield 'music browse' => ['/browse/music', null, BrowseRoot::Audio];
        yield 'audio title page' => ['/title/audio/12', null, BrowseRoot::Audio];
        yield 'groups' => ['/browsegroup', null, BrowseRoot::All];
        yield 'all releases' => ['/browse/all', null, BrowseRoot::All];
        yield 'all releases, capitalised' => ['/browse/All', null, BrowseRoot::All];
        yield 'group redirect' => ['/browse/group', null, BrowseRoot::All];
        yield 'tv release details' => ['/details/abc', 5030, BrowseRoot::Tv];
        yield 'movie release details' => ['/details/abc', 2040, BrowseRoot::Movies];
        yield 'adult release details' => ['/details/abc', 6010, BrowseRoot::Adult];
        yield 'other release details' => ['/details/abc', 10, BrowseRoot::Other];
        yield 'details of an unknown category' => ['/details/abc', 99999, null];
        yield 'search' => ['/search', null, null];
        yield 'account' => ['/account', null, null];
        yield 'basket' => ['/basket', null, null];
        yield 'following' => ['/watchlist', null, null];
    }

    #[DataProvider('pages')]
    public function test_the_underlined_header_button_follows_the_page_being_viewed(string $uri, ?int $releaseCategory, ?BrowseRoot $expected): void
    {
        $request = Request::create($uri);
        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(static fn () => $route);

        $this->assertSame($expected, GlobalDataComposer::currentSection($request, $releaseCategory));
    }
}
