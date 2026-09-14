<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ReleaseEntityData;
use App\Models\Release;
use App\View\Composers\GlobalDataComposer;
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
            'size' => 1073741824,
            'nfostatus' => 1,
            'adddate' => now(),
            'postdate' => now(),
        ]);
        $release->setRelation('audioTags', null);

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
        $nfoTrigger = $document->getElementById('nfo');
        $this->assertNotNull($nfoTrigger);
        $this->assertSame('details-document', $nfoTrigger->getAttribute('data-guid'));
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
