<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Issues #942 and #965: the old Books, Console, PC and Audio browse, legacy and title pages are retired (404), so no
 * page may still link to them. Matched case-sensitively, so the new /books, /console, /pc and /audio lists do not count.
 */
trait AssertsNoRetiredAddress
{
    protected function assertNoRetiredAddress(string $html, string $page): void
    {
        $retired = [
            '/browse/books', '/browse/console', '/browse/games', '/browse/pc', '/browse/audio', '/browse/music',
            '/title/books/', '/title/console/', '/title/games/', '/title/pc/', '/title/audio/',
            url('/Books'), url('/Console'), url('/Games'), url('/Audio'),
        ];
        $found = array_values(array_filter($retired, static fn (string $address): bool => str_contains($html, $address)));

        $this->assertSame([], $found, $page.' links to a retired address');
    }
}
