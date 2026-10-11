<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The Imported chip of a release chip line (issue #1049): the `fa-right-to-bracket` icon with no text in the
 * `imported` tone, never a link or a button, its sentence both the hover title and the accessible name, the last
 * of the release's own chips.
 */
trait AssertsImportedChip
{
    private const IMPORTED_SENTENCE = 'This release was added from an imported NZB';

    /** $html holds exactly one Imported chip, built as the issue states. */
    protected function assertImportedChip(string $html, string $where): void
    {
        $this->assertSame(1, preg_match_all('/<(\w+)\b([^>]*data-chip-variant="imported"[^>]*)>(.*?)<\/\1>/s', $html, $chip), $where.' has one Imported chip');
        $this->assertSame('span', $chip[1][0], $where.': the chip reports, it is no link or button');
        foreach (['chip-tone-imported', 'release-chip', 'role="img"', 'title="'.self::IMPORTED_SENTENCE.'"', 'aria-label="'.self::IMPORTED_SENTENCE.'"'] as $part) {
            $this->assertStringContainsString($part, $chip[2][0], $where);
        }
        $this->assertStringNotContainsString('href=', $chip[2][0], $where);
        $this->assertSame('<i class="fas fa-right-to-bracket" aria-hidden="true"></i>', trim($chip[3][0]), $where.': the icon and no text');
    }

    protected function assertNoImportedChip(string $html, string $where): void
    {
        $this->assertStringNotContainsString('data-chip-variant="imported"', $html, $where);
        $this->assertStringNotContainsString('fa-right-to-bracket', $html, $where);
        $this->assertStringNotContainsString(self::IMPORTED_SENTENCE, $html, $where);
    }

    /**
     * The Imported chip comes after every marker of $before (the release's other chips) and ahead of every
     * marker of $after (the group and poster chips); each marker is in $html.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    protected function assertImportedChipPlaced(string $html, array $before, array $after, string $where): void
    {
        $chip = strpos($html, 'data-chip-variant="imported"');
        $this->assertNotFalse($chip, $where.' has the Imported chip');
        foreach ($before as $marker) {
            $at = strrpos($html, $marker);
            $this->assertNotFalse($at, $where.' has '.$marker);
            $this->assertLessThan($chip, $at, $where.': the Imported chip follows '.$marker);
        }
        foreach ($after as $marker) {
            $at = strpos($html, $marker);
            $this->assertNotFalse($at, $where.' has '.$marker);
            $this->assertGreaterThan($chip, $at, $where.': the Imported chip is ahead of '.$marker);
        }
    }

    /** The table row (`tr data-release-row`) of the release named $name. */
    protected function releaseRowNamed(string $html, string $name): string
    {
        foreach (array_slice(preg_split('/<tr\b[^>]*data-release-row/', $html) ?: [], 1) as $row) {
            $row = strstr($row, '</tr>', true) ?: $row;
            if (str_contains($row, 'title="'.e($name).'"') || str_contains($row, '>'.e($name).'</a>')) {
                return $row;
            }
        }
        $this->fail('No release row for '.$name);
    }

    /** A release page's own chip line, the group and poster line after it left out. */
    protected function detailsChipLine(string $html): string
    {
        $from = strpos($html, '<div class="tv-chips tv-details-chips">');
        $this->assertNotFalse($from, 'The page has a chip line');

        return substr($html, $from, strpos($html, '</div>', $from) - $from);
    }

    /** The chip line (`div.tv-chips`) of $row holds the Imported chip and nothing else. */
    protected function assertLoneImportedChipLine(string $row, string $where): void
    {
        $this->assertImportedChip($row, $where);
        $this->assertMatchesRegularExpression('/<div class="tv-chips">\s*<span [^>]*data-chip-variant="imported"[^>]*>.*?<\/span>\s*<\/div>/s', $row, $where.': the chip line holds the Imported chip alone');
    }

    /**
     * The page's Completion menu is single-choice and lists "Any completion", "100% only", "95% or more" and then
     * "Imported only", the last one ticked and no other.
     */
    protected function assertImportedOnlyChosenLast(string $html): void
    {
        $menu = (string) strstr((string) strstr($html, 'data-name="completion"'), '</div>', true);
        preg_match_all('/role="menuitemradio"(?: data-any| data-value="([^"]*)" data-text="([^"]*)")[^>]*aria-checked="(true|false)"/', $menu, $items, PREG_SET_ORDER);
        $this->assertSame([['', '', 'false'], ['100', '100% only', 'false'], ['95', '95% or more', 'false'], ['imported', 'Imported only', 'true']],
            array_map(static fn (array $item): array => [$item[1], $item[2], $item[3]], $items));
        $this->assertMatchesRegularExpression('/<span class="checkbox-menu-name">Completion<\/span>.*?<span class="checkbox-menu-value"[^>]*>Imported<\/span>/s', $menu, 'the filter cell reads Imported');
    }
}
