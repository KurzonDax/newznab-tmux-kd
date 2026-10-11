<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Content;
use App\Services\Releases\ReleaseSearchService;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsOffsiteLinks;
use Tests\Support\InteractsWithPublicShell;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class DetailsDocumentViewTest extends TestCase
{
    use AssertsOffsiteLinks;
    use InteractsWithAdminListPages;
    use InteractsWithPublicShell;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    /** A category under none of the eight roots: its release gets the generic release page (issue #1051 section 2). */
    private const UNROOTED = 8010;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->withoutVite();
        Cache::flush();
        (new ReflectionProperty(GlobalDataComposer::class, 'resolvedData'))->setValue(null, null);

        $this->bootAdminListPage();
        $this->createPublicShellCountTables();
        $this->actingAs($this->browserUser());

        config(['nntmux_settings.covers_path' => $this->makeTempDirectory('details-covers')]);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(GlobalDataComposer::class, 'resolvedData'))->setValue(null, null);
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_details_renders_a_complete_document_with_one_working_image_dialog(): void
    {
        $this->releasePageFixtures();
        $this->release('Example.Release', ['guid' => 'details-document', 'size' => 41943040, 'nfostatus' => 1, 'categories_id' => self::UNROOTED, 'groups_id' => 5,
            'fromname' => 'A Poster', 'imdbid' => '1234567', 'movieinfo_id' => 21]);

        // a release outside the eight roots gets the generic release page (issue #1051), whatever it was once matched to
        $html = (string) $this->get('/details/details-document')->assertOk()->assertViewIs('details.shelf.index')->getContent();
        $this->assertStringNotContainsString('href="'.route('movies.film', ['movieinfoId' => 21]).'"', $html);
        $this->assertStringNotContainsString('/title/movies/', $html);

        $this->assertStringStartsWith('<!DOCTYPE html>', ltrim($html));
        $this->assertSame(1, substr_count($html, 'x-data="tvImageDialog"'));
        $this->assertStringNotContainsString('x-data="imageModal"', $html);
        $this->assertStringNotContainsString('id="imageModal"', $html);
        $this->assertStringContainsString('Download NZB', $html);

        $document = new \DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame('Example.Release', trim($xpath->query('//h1')->item(0)->textContent));
        $this->assertSame(0, $xpath->query('//*[@data-details-header]')->length);
        $this->assertSame(1, $xpath->query('//dl[contains(@class, "tv-details-facts")]//dd[normalize-space(.)="40 MB"]')->length);
        $this->assertStringNotContainsString('detail-info-sidebar', $html);
        foreach (['overview', 'files', 'nfo', 'comments'] as $tab) {
            $this->assertNotNull($document->getElementById('tab-'.$tab));
        }
        $this->assertNull($document->getElementById('tab-media'), 'no Media info tab without media info');
        $this->assertStringNotContainsString('Other releases of this title', $html);
        // every dialog frame holds one dialog (the frame itself, or the redesigned dialogs' panel inside it)
        $frames = $xpath->query('//*[@data-modal-dialog]');
        $this->assertCount(7, $frames);
        $this->assertSame(1, $xpath->query('//*[@aria-labelledby="watchlist-modal-title"]')->length);
        foreach ($frames as $frame) {
            $dialogs = $xpath->query('descendant-or-self::*[@role="dialog"]', $frame);
            $this->assertSame(1, $dialogs->length);
            $dialog = $dialogs->item(0);
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
        $this->releasePageFixtures();
        $this->release('Offsite.Release', ['guid' => 'offsite-links', 'size' => 1048576, 'categories_id' => self::UNROOTED, 'groups_id' => 5, 'fromname' => 'A Poster']);

        $html = (string) $this->get('/details/offsite-links')->assertOk()->assertViewIs('details.shelf.index')->getContent();

        $offsite = $this->assertOffsiteLinksOpenInANewTab($html, 'The details page');
        foreach (['https://github.com/NNTmux/newznab-tmux', 'https://help.example.org/guide', 'https://simplegate.space/'] as $expected) {
            $this->assertNotSame([], array_filter($offsite, fn (string $href): bool => str_contains($href, $expected)), 'Missing offsite link '.$expected);
        }
        $this->assertCount(2, array_filter($offsite, fn (string $href): bool => $href === 'https://github.com/NNTmux/newznab-tmux'));
        $this->assertStringContainsString('<span class="sr-only">GitHub (opens in a new tab)</span>', $html);

        $this->assertSameTabLink($html, url('/inside-help/'), 'The footer');
        $this->assertSameTabLink($html, route('browse.all', ['group' => 'alt.binaries.example']), 'The details page');
        $this->assertSameTabLink($html, route('browse.all', ['poster' => 'A Poster']), 'The details page');
    }

    /**
     * What the generic release page of a release outside the eight roots reads: the release with its group, a category
     * under none of the eight roots, and the tables its row, its facts and its comments are loaded from.
     */
    private function releasePageFixtures(): void
    {
        $tables = ProductionTables::fromAuthority();
        $tables->create('releases', ['id', 'name', 'searchname', 'guid', 'display_name', 'categories_id', 'category_band', 'size', 'totalpart',
            'adddate', 'postdate', 'grabs', 'comments', 'completion', 'declaredfiles', 'nzbstatus', 'passwordstatus', 'nfostatus',
            'haspreview', 'jpgstatus', 'videostatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'movieinfo_id', 'videos_id',
            'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'resolution', 'source', 'predb_id', 'is_imported']);
        foreach (['usenet_groups', 'videos', 'tv_episodes', 'movieinfo', 'consoleinfo', 'release_audio_tags', 'release_video_clips', 'predb', 'releases_groups', 'release_comments'] as $table) {
            $tables->create($table);
        }
        foreach (['2026_02_01_000000_create_release_reports_table', '2026_06_08_000000_add_response_fields_to_release_reports_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        DB::table('root_categories')->insert(['id' => 8000, 'title' => 'Unrooted']);
        DB::table('categories')->insert(['id' => self::UNROOTED, 'title' => 'Legacy', 'root_categories_id' => 8000]);
        DB::table('usenet_groups')->insert(['id' => 5, 'name' => 'alt.binaries.example', 'active' => 1]);
        $this->mock(ReleaseSearchService::class)->shouldReceive('searchSimilar')->andReturn([]);
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
