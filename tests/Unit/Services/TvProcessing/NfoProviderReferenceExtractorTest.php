<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TvProcessing;

use App\Services\TvProcessing\NfoProviderReferenceExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NfoProviderReferenceExtractorTest extends TestCase
{
    /** @var list<int> */
    private const array TV_NO_ID_LINES = [
        5, 15, 16, 17, 18, 19, 20, 35, 36, 37, 38, 39, 40, 41, 42, 43,
        71, 81, 82, 83, 84, 92, 93, 94, 97, 98, 99, 100, 102, 104, 105,
    ];

    /** @var list<int> */
    private const array ROBUSTNESS_NO_ID_LINES = [19, 20, 21, 22, 23, 24, 25, 26, 27, 28];

    #[Test]
    public function it_classifies_the_complete_tv_nfo_reference_corpus(): void
    {
        $classified = $this->classifyFixture(
            'nfo_provider_reference_lines_tv.txt',
            self::TV_NO_ID_LINES,
        );

        self::assertSame([
            'imdb' => 52,
            'tvdb' => 2,
            'tmdb' => 1,
            'tvmaze' => 3,
            'tvrage' => 19,
            'none' => 31,
        ], $classified['totals']);
        self::assertSame(108, $classified['line_count']);
        self::assertContains('0361217', $classified['ids']['imdb']);
        self::assertContains('43648966', $classified['ids']['imdb']);
        self::assertEqualsCanonicalizing(['70726', '290572'], $classified['ids']['tvdb']);
        self::assertSame(['156563'], $classified['ids']['tmdb']);
        self::assertEqualsCanonicalizing(['396', '298', '5994'], $classified['ids']['tvmaze']);
        self::assertContains('31714', $classified['ids']['tvrage']);
    }

    #[Test]
    public function it_classifies_the_robustness_corpus_without_false_provider_ids(): void
    {
        $classified = $this->classifyFixture(
            'nfo_provider_reference_lines_robustness.txt',
            self::ROBUSTNESS_NO_ID_LINES,
        );

        self::assertSame([
            'imdb' => 18,
            'tvdb' => 0,
            'tmdb' => 0,
            'tvmaze' => 0,
            'tvrage' => 0,
            'none' => 10,
        ], $classified['totals']);
        self::assertSame(28, $classified['line_count']);
        self::assertContains('0005074', $classified['ids']['imdb']);
        self::assertContains('10210064', $classified['ids']['imdb']);
    }

    /**
     * @param  list<int>  $expectedNoIdLines
     * @return array{
     *     totals: array{imdb: int, tvdb: int, tmdb: int, tvmaze: int, tvrage: int, none: int},
     *     ids: array{imdb: list<string>, tvdb: list<string>, tmdb: list<string>, tvmaze: list<string>, tvrage: list<string>},
     *     line_count: int
     * }
     */
    private function classifyFixture(string $fixture, array $expectedNoIdLines): array
    {
        $lines = file($this->fixturePath($fixture), FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        $extractor = new NfoProviderReferenceExtractor;
        $totals = ['imdb' => 0, 'tvdb' => 0, 'tmdb' => 0, 'tvmaze' => 0, 'tvrage' => 0, 'none' => 0];
        $ids = ['imdb' => [], 'tvdb' => [], 'tmdb' => [], 'tvmaze' => [], 'tvrage' => []];

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $references = $extractor->extract($line);
            if (in_array($lineNumber, $expectedNoIdLines, true)) {
                $totals['none']++;
                self::assertSame([], $references, "Expected no provider reference on line {$lineNumber}: {$line}");

                continue;
            }

            self::assertCount(1, $references, "Expected one provider reference on line {$lineNumber}: {$line}");
            $provider = $references[0]['provider'];
            $totals[$provider]++;
            $ids[$provider][] = $references[0]['id'];
        }

        return [
            'totals' => $totals,
            'ids' => $ids,
            'line_count' => count($lines),
        ];
    }

    private function fixturePath(string $fixture): string
    {
        return dirname(__DIR__, 3).'/Fixtures/'.$fixture;
    }
}
