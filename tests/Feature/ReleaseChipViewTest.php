<?php

declare(strict_types=1);

namespace Tests\Feature;

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

    public function test_the_imported_variant_is_a_tone_of_its_own_reading_the_four_imported_tokens_in_light_and_dark(): void
    {
        $html = Blade::render('<x-chip variant="imported" icon="fas fa-right-to-bracket" />');

        $this->assertMatchesRegularExpression('/^<span class="release-chip chip-tone-imported" data-chip-variant="imported">\s*<i class="fas fa-right-to-bracket" aria-hidden="true"><\/i>\s*<\/span>$/', trim($html));
        $css = (string) file_get_contents(resource_path('css/app.css'));
        foreach (['--chip-imported-bg: oklch(0.93 0.06 90);', '--chip-imported-fg: oklch(0.42 0.09 85);', '--chip-imported-bg-dark: oklch(0.33 0.06 90);', '--chip-imported-fg-dark: oklch(0.89 0.11 90);',
            '.chip-tone-imported { background-color: var(--chip-imported-bg); color: var(--chip-imported-fg); }',
            '.dark .chip-tone-imported { background-color: var(--chip-imported-bg-dark); color: var(--chip-imported-fg-dark); }'] as $rule) {
            $this->assertStringContainsString($rule, $css, $rule);
        }
    }
}
