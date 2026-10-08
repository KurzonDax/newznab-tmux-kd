<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ReleaseEntityData;
use App\Data\ReleaseRowData;
use App\Models\Content;
use App\Models\Release;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Support\Facades\Cache;
use ReflectionProperty;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsOffsiteLinks;
use Tests\Support\InteractsWithPublicShell;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class DetailsDocumentViewTest extends TestCase
{
    use AssertsOffsiteLinks;
    use InteractsWithAdminListPages;
    use InteractsWithPublicShell;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->withoutVite();
        Cache::flush();
        (new ReflectionProperty(GlobalDataComposer::class, 'resolvedData'))->setValue(null, null);

        $this->bootAdminListPage();
        $this->createPublicShellCountTables();
        $this->actingAs($this->createUserWithRole('User'));

        config(['nntmux_settings.covers_path' => $this->makeTempDirectory('details-covers')]);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(GlobalDataComposer::class, 'resolvedData'))->setValue(null, null);
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_details_renders_a_complete_document_with_one_working_image_modal(): void
    {
        $release = Release::factory()->make([
            'id' => 1,
            'guid' => 'details-document',
            'searchname' => 'Example.Release',
            'size' => 41943040,
            'nfostatus' => 1,
            'adddate' => now(),
            'postdate' => now(),
        ]);
        $release->setRelation('audioTags', null);
        $release->row_data = new ReleaseRowData(
            id: 1, guid: 'details-document', name: 'Example.Release', category: 'Movies > HD',
            size: '40.00 MB', files: 3, added: '1 hour ago', posted: 'Sep 13, 2026 13:00', grabs: 2, comments: 0,
            completion: 100, passworded: false,
            has_media_info: false, media_info_summary: null, nfo: true, preview: 'none', group: 'alt.binaries.example', poster: 'A Poster',
            renamed: true, pp_done: true, entity: new ReleaseEntityData('movies', '1234567', 'A Movie', '2024', null, filmId: 21),
            in_basket: false, watched: false,
        );

        $html = view('details.index', ['release' => $release,
            'titleEntity' => new ReleaseEntityData('movies', '1234567', 'A Movie', '2024', null, filmId: 21),
        ])->render();
        $this->assertStringNotContainsString('href="'.route('movies.film', ['movieinfoId' => 21]).'"', $html);
        $this->assertStringNotContainsString('/title/movies/', $html);

        $this->assertStringStartsWith('<!DOCTYPE html>', ltrim($html));
        $this->assertSame(1, substr_count($html, 'x-data="imageModal"'));
        $this->assertStringNotContainsString('id="imageModal"', $html);
        $this->assertStringContainsString('Download NZB', $html);

        $document = new \DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame('Example.Release', trim($xpath->query('//h1')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//*[@data-details-header]')->length);
        $this->assertStringContainsString('40.00 MB', $xpath->query('//*[@data-details-header]')->item(0)->textContent);
        $this->assertStringNotContainsString('detail-info-sidebar', $html);
        foreach (['overview', 'files', 'media', 'nfo', 'comments'] as $tab) {
            $this->assertNotNull($document->getElementById($tab));
            $this->assertSame(1, $xpath->query('//nav[@aria-label="Release details"]//a[@href="#'.$tab.'"]')->length);
        }
        $this->assertSame(1, $xpath->query('//*[@data-details-header]//a[@href="#nfo"]')->length);
        $this->assertStringContainsString('No media info for this release.', $html);
        $this->assertStringNotContainsString('Other releases of this title', $html);
        $dialogs = $xpath->query('//*[@data-modal-dialog]');
        $this->assertCount(8, $dialogs);
        $this->assertSame(1, $xpath->query('//*[@aria-labelledby="watchlist-modal-title"]')->length);
        foreach ($dialogs as $dialog) {
            $this->assertSame('dialog', $dialog->getAttribute('role'));
            $this->assertSame('true', $dialog->getAttribute('aria-modal'));
            $this->assertNotNull($document->getElementById($dialog->getAttribute('aria-labelledby')));
            $this->assertSame(1, $xpath->query('.//header//button[@title="Close (Esc)"]', $dialog)->length);
            $this->assertSame(0, $xpath->query('.//footer//button[normalize-space(.)="Close"]', $dialog)->length);
        }
    }

    public function test_offsite_links_on_the_details_page_and_footer_open_safely_in_a_new_tab(): void
    {
        foreach ([['Outside help', 'https://help.example.org/guide', 1], ['Inside help', '/inside-help/', 2]] as [$title, $url, $ordinal]) {
            Content::query()->create(['title' => $title, 'url' => $url, 'body' => '<p>Plain text only.</p>',
                'contenttype' => Content::TYPE_USEFUL, 'status' => Content::STATUS_ENABLED, 'ordinal' => $ordinal, 'role' => Content::ROLE_EVERYONE]);
        }
        $release = Release::factory()->make(['id' => 2, 'guid' => 'offsite-links', 'searchname' => 'Offsite.Release',
            'size' => 1048576, 'adddate' => now(), 'postdate' => now()]);
        $release->setRelation('audioTags', null);
        $release->row_data = new ReleaseRowData(
            id: 2, guid: 'offsite-links', name: 'Offsite.Release', category: 'Movies > HD',
            size: '1.00 MB', files: 1, added: '1 hour ago', posted: 'Sep 13, 2026 13:00', grabs: 0, comments: 0,
            completion: 100, passworded: false,
            has_media_info: false, media_info_summary: null, nfo: false, preview: 'none', group: 'alt.binaries.example', poster: 'A Poster',
            renamed: true, pp_done: true, entity: null, in_basket: false, watched: false,
        );

        $html = view('details.index', ['release' => $release, 'site' => ['dereferrer_link' => ''],
            'show' => ['title' => 'A Show', 'started' => '2020-01-01', 'tvdb' => 81189],
            'anidb' => ['title' => 'An Anime', 'country' => 'JP', 'media_type' => 'TV', 'anilist_id' => 21, 'mal_id' => 22],
        ])->render();

        $offsite = $this->assertOffsiteLinksOpenInANewTab($html, 'The details page');
        foreach (['https://github.com/NNTmux/newznab-tmux', 'https://help.example.org/guide', 'https://simplegate.space/',
            'https://thetvdb.com/?tab=series&id=81189', 'https://anilist.co/anime/21', 'https://myanimelist.net/anime/22'] as $expected) {
            $this->assertNotSame([], array_filter($offsite, fn (string $href): bool => str_contains($href, $expected)), 'Missing offsite link '.$expected);
        }
        $this->assertCount(2, array_filter($offsite, fn (string $href): bool => $href === 'https://github.com/NNTmux/newznab-tmux'));
        $this->assertStringContainsString('<span class="sr-only">GitHub (opens in a new tab)</span>', $html);

        $this->assertSameTabLink($html, url('/inside-help/'), 'The footer');
        $this->assertSameTabLink($html, route('browse.all', ['group' => 'alt.binaries.example']), 'The details page');
        $this->assertSameTabLink($html, route('browse.all', ['poster' => 'A Poster']), 'The details page');
    }

    public function test_nfo_modal_response_preserves_plain_text_and_release_identity(): void
    {
        $html = view('nfo.view', [
            'modal' => true,
            'nfo' => ['nfoUTF' => "Title <&>\n  ASCII art"],
            'rel' => ['searchname' => 'Example.Release'],
        ])->render();
        $document = new \DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $pre = $document->getElementsByTagName('pre')->item(0);
        $this->assertNotNull($pre);
        $this->assertSame('Example.Release', $pre->getAttribute('data-release-name'));
        $this->assertSame("Title <&>\n  ASCII art", $pre->textContent);
    }
}
