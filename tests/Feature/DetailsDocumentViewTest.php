<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ReleaseEntityData;
use App\Data\ReleaseRowData;
use App\Models\Release;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use ReflectionProperty;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\InteractsWithPublicShell;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class DetailsDocumentViewTest extends TestCase
{
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
            completion: 100, repair_outcome: null, rescan_outcome: null, passworded: false,
            has_media_info: false, media_info_summary: null, nfo: true, preview: 'none', group: 'alt.binaries.example', poster: 'A Poster',
            renamed: true, pp_done: true, entity: new ReleaseEntityData('movies', '1234567', 'A Movie', '2024', null),
            in_basket: false, watched: false,
        );

        $html = view('details.index', ['release' => $release,
            'titleEntity' => new ReleaseEntityData('movies', '1234567', 'A Movie', '2024', null),
        ])->render();
        $this->assertStringContainsString('href="'.route('title', ['root' => 'movies', 'id' => '1234567']).'"', $html);

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
        $this->assertStringContainsString('Other releases of this title', $html);
        $dialogs = $xpath->query('//*[@data-modal-dialog]');
        $this->assertCount(7, $dialogs);
        foreach ($dialogs as $dialog) {
            $this->assertSame('dialog', $dialog->getAttribute('role'));
            $this->assertSame('true', $dialog->getAttribute('aria-modal'));
            $this->assertNotNull($document->getElementById($dialog->getAttribute('aria-labelledby')));
            $this->assertSame(1, $xpath->query('.//header//button[@title="Close (Esc)"]', $dialog)->length);
            $this->assertSame(0, $xpath->query('.//footer//button[normalize-space(.)="Close"]', $dialog)->length);
        }
    }

    public function test_anime_related_releases_paginate_when_no_title_overview_exists(): void
    {
        $related = (object) ['guid' => 'another-episode', 'searchname' => 'Anime.S01E02.1080p.WEB', 'related_label' => '1080p · WEB',
            'completion' => 100, 'row_data' => (object) ['size' => '40.00 MB']];
        $pages = new LengthAwarePaginator([$related], 101, 10, 1, [
            'path' => route('details', 'current-episode'), 'pageName' => 'other_page',
        ]);
        $pages->fragment('other-releases');
        $html = view('details.partials.related', ['entity' => new ReleaseEntityData('anime', '12', 'An Anime', null, null),
            'otherReleases' => $pages, 'otherReleaseCount' => 101])->render();
        $this->assertStringNotContainsString('href=""', $html);
        $this->assertStringContainsString('other_page=2#other-releases', $html);
        $this->assertStringContainsString('1080p · WEB', $html);
        $this->assertStringContainsString('40.00 MB', $html);
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
