<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Issue #942: the old Books, Console and PC browse, legacy and title pages are retired (404), so no page may still
 * link to them. Matched case-sensitively, so the new /books, /console and /pc lists do not count.
 */
trait AssertsNoRetiredAddress
{
    protected function assertNoRetiredAddress(string $html, string $page): void
    {
        $retired = [
            '/browse/books', '/browse/console', '/browse/games', '/browse/pc',
            '/title/books/', '/title/console/', '/title/games/', '/title/pc/',
            url('/Books'), url('/Console'), url('/Games'),
        ];
        $found = array_values(array_filter($retired, static fn (string $address): bool => str_contains($html, $address)));

        $this->assertSame([], $found, $page.' links to a retired address');
    }

    /**
     * The shared lists' title chips for one release each of Console, PC, Books and Audio, all with title id 12: the
     * console chip opens that release's details page, the audio chip its title page, and no chip names the book or
     * PC title.
     */
    protected function assertShelfTitleChips(string $html, string $page, string $consoleGuid): void
    {
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $chips = [];
        foreach ((new \DOMXPath($document))->query('//a[@data-chip-variant="entity"]') as $chip) {
            $chips[trim($chip->textContent)] = $chip->getAttribute('href');
        }

        $this->assertSame(route('details', $consoleGuid), $chips['Console Game Title · 2024'] ?? null, $page);
        $this->assertSame(route('title', ['root' => 'audio', 'id' => 12]), $chips['Album Title · 2021'] ?? null, $page);
        foreach (array_keys($chips) as $label) {
            $this->assertStringNotContainsString('Printed Book Title', $label, $page);
            $this->assertStringNotContainsString('Computer Game Title', $label, $page);
        }
    }
}
