<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * "Follow", never "Watch" (TV SPEC.md rule 12, issue #832): no visible text, heading, breadcrumb, menu, button,
 * `title` or `aria-label` of a rendered page says watch, watching or Watchlist. "Watch video preview" is the one
 * exception, because there the word means view.
 */
trait AssertsFollowWording
{
    protected function assertNoWatchWording(string $html, string $page): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $found = [];
        foreach ($xpath->query('//text()[not(ancestor::script) and not(ancestor::style)] | //@title | //@aria-label | //@alt | //@placeholder') as $node) {
            $text = trim(str_replace('Watch video preview', '', $node->nodeValue ?? ''));
            if (preg_match('/\bwatch(ing|es|ed|list|lists)?\b/i', $text) === 1) {
                $found[] = ($node instanceof \DOMAttr ? $node->name.'="'.$node->value.'"' : $text);
            }
        }

        $this->assertSame([], $found, $page.' says watch where it means follow');
    }
}
