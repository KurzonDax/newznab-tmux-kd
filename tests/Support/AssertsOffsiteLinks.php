<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Every link that leaves the site opens in a new tab (TV SPEC.md rule 13, Movies SPEC.md 4, issue #833):
 * `target="_blank"`, a `rel` holding `noopener` and `noreferrer`, and visually hidden text that ends
 * "(opens in a new tab)".
 */
trait AssertsOffsiteLinks
{
    /**
     * Assert the rule for every offsite link on the page and return their hrefs, so a test can check the
     * links it expects are there at all.
     *
     * @return list<string>
     */
    protected function assertOffsiteLinksOpenInANewTab(string $html, string $page): array
    {
        $siteHost = parse_url(url('/'), PHP_URL_HOST);
        $offsite = [];
        $broken = [];
        foreach ($this->documentAnchors($html) as $anchor) {
            $href = $anchor->getAttribute('href');
            if (! str_starts_with($href, 'http') || parse_url($href, PHP_URL_HOST) === $siteHost) {
                continue;
            }

            $offsite[] = $href;
            $rel = preg_split('/\s+/', strtolower($anchor->getAttribute('rel')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $hidden = '';
            foreach ($anchor->getElementsByTagName('span') as $span) {
                if (in_array('sr-only', explode(' ', $span->getAttribute('class')), true)) {
                    $hidden .= $span->textContent;
                }
            }

            if ($anchor->getAttribute('target') !== '_blank'
                || ! in_array('noopener', $rel, true)
                || ! in_array('noreferrer', $rel, true)
                || ! str_ends_with(trim($hidden), '(opens in a new tab)')) {
                $broken[] = $href;
            }
        }

        $this->assertSame([], $broken, $page.' has offsite links that do not open safely in a new tab');

        return $offsite;
    }

    /**
     * Assert a link within the site opens in the same tab.
     */
    protected function assertSameTabLink(string $html, string $href, string $page): void
    {
        $matches = array_values(array_filter($this->documentAnchors($html), fn (\DOMElement $anchor): bool => $anchor->getAttribute('href') === $href));

        $this->assertNotSame([], $matches, $page.' has no link to '.$href);
        foreach ($matches as $anchor) {
            $this->assertFalse($anchor->hasAttribute('target'), $page.' opens '.$href.' in a new tab');
        }
    }

    /**
     * @return list<\DOMElement>
     */
    private function documentAnchors(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $anchors = [];
        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $anchors[] = $anchor;
        }

        return $anchors;
    }
}
