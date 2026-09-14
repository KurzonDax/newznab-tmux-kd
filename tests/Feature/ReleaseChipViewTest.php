<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Release;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class ReleaseChipViewTest extends TestCase
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

    public function test_release_facts_share_semantic_colors_and_the_existing_modal_targets(): void
    {
        $release = Release::factory()->make([
            'id' => 42, 'guid' => 'facts-release', 'searchname' => 'Example Release',
            'completion' => 100, 'passwordstatus' => 1, 'nfostatus' => 1,
            'haspreview' => 0, 'jpgstatus' => 1, 'has_media_info' => true,
            'media_info_summary' => '1080p · x264 · DTS-HD 5.1',
            'has_video_preview' => true, 'video_preview_mime' => 'video/mp4',
        ]);
        $html = Blade::render('<x-release-facts :release="$release" />', compact('release'));
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);

        $this->assertSame('100%', trim($xpath->evaluate('string(//span[@data-chip-variant="success"])')));
        $this->assertSame('Password', trim($xpath->evaluate('string(//span[@data-chip-variant="danger"])')));
        $this->assertSame('1080p · x264 · DTS-HD 5.1', trim($xpath->evaluate('string(//button[@data-release-id="42"])')));
        $this->assertSame('NFO', trim($xpath->evaluate('string(//button[@data-chip-variant="warning"])')));
        $this->assertSame('Clip', trim($xpath->evaluate('string(//button[@data-video-url])')));
        $this->assertSame('info', $xpath->evaluate('string(//button[@data-video-url]/@data-chip-variant)'));
        $this->assertSame('Sample', trim($xpath->evaluate('string(//button[@data-chip-variant="success"])')));
        $this->assertSame(3, $xpath->query('//button[@data-guid="facts-release"]')->length);
    }

    public function test_origin_and_entity_chips_escape_labels_and_preserve_the_supplied_destination(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-origin-chip kind="group" value="alt.binaries.movies" />
            <x-origin-chip kind="poster" :value="$poster" />
            <x-entity-chip root="movies" title="Example Film" year="2024" href="/movies/view/1234567" />
            <x-entity-chip root="tv" title="Example Show" year="2020" href="/series/42" />
            <x-entity-chip root="adult" title="Hidden entity" href="/unused" />
            BLADE, ['poster' => 'User <user@example.test>']);
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);

        $this->assertSame(2, $xpath->query('//a[@data-chip-variant="origin"]')->length);
        $this->assertSame(url('/browse/group').'?g=alt.binaries.movies', $xpath->evaluate('string(//a[contains(@title,"All releases in")]/@href)'));
        $this->assertSame(route('poster-identity', ['name' => 'User <user@example.test>']), $xpath->evaluate('string(//a[contains(@title,"All posts by")]/@href)'));
        $this->assertSame('Example Film · 2024', trim($xpath->evaluate('string(//a[@href="/movies/view/1234567"])')));
        $this->assertSame('Example Show', trim($xpath->evaluate('string(//a[@href="/series/42"])')));
        $this->assertStringNotContainsString('Hidden entity', $html);
    }

    public function test_chips_keep_text_safe_and_expose_native_links_and_modal_buttons(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-chip variant="success" icon="fas fa-check">100%</x-chip>
            <x-chip variant="warning" action class="nfo-badge" data-guid="release-guid">NFO</x-chip>
            <x-chip variant="origin" href="/browse/group?g=alt.binaries.example">{{ $poster }}</x-chip>
            BLADE, ['poster' => 'User <user@example.test>']);
        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);

        $this->assertSame('100%', trim($xpath->evaluate('string(//span[contains(@class,"release-chip")])')));
        $this->assertSame('button', $xpath->evaluate('string(//button[@data-guid="release-guid"]/@type)'));
        $this->assertSame('NFO', trim($xpath->evaluate('string(//button[@data-guid="release-guid"])')));
        $this->assertSame('User <user@example.test>', trim($xpath->evaluate('string(//a)')));
        $this->assertSame('/browse/group?g=alt.binaries.example', $xpath->evaluate('string(//a/@href)'));
        $this->assertSame(0, $xpath->query('//user')->length);
    }
}
