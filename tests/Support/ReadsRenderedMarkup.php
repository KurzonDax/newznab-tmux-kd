<?php

declare(strict_types=1);

namespace Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads rendered pages for tests that check which element carries a control's behaviour.
 *
 * Structure (what sits inside what) is read through XPath. Alpine's own attributes
 * (`@click`, `@submit.prevent`) are read from the element's opening tag as written, because
 * an HTML parser may not keep attribute names that start with `@`.
 */
trait ReadsRenderedMarkup
{
    protected function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    /**
     * @return list<DOMElement>
     */
    protected function elements(DOMXPath $xpath, string $query, ?DOMElement $within = null): array
    {
        $elements = [];
        foreach ($xpath->query($query, $within) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * Every opening tag of the named element whose attributes contain the given text.
     *
     * @return list<string>
     */
    protected function openingTags(string $html, string $element, string $containing): array
    {
        preg_match_all('/<'.preg_quote($element, '/').'\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/', $html, $tags);

        return array_values(array_filter($tags[0], static fn (string $tag): bool => str_contains($tag, $containing)));
    }

    /**
     * The decoded value of one attribute of an opening tag, or null when the tag has no such attribute.
     */
    protected function attribute(string $tag, string $name): ?string
    {
        if (preg_match('/[\s"\']'.preg_quote($name, '/').'="([^"]*)"/', $tag, $match) !== 1) {
            return null;
        }

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }
}
