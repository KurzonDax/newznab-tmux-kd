<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\Enums\PasswordVerdict;
use App\Services\AdditionalProcessing\SevenZip\ArchiveRanges;
use App\Services\AdditionalProcessing\SevenZip\InspectionBudget;
use App\Services\AdditionalProcessing\SevenZip\Inspector;
use App\Services\AdditionalProcessing\SevenZip\PlaceholderVolume;
use App\Services\DTO\YencArticleMetadata;
use PHPUnit\Framework\TestCase;

class SevenZipPlaceholderRangesTest extends TestCase
{
    public function test_the_header_is_read_through_placeholders_from_the_first_and_last_parts_only(): void
    {
        [$volumes, $articles] = $this->posting($this->fixture('obfuscated-encrypted'), 7168);
        $this->assertCount(4, $volumes);
        $calls = [];
        $ranges = new ArchiveRanges(
            [$volumes[0], new PlaceholderVolume(7168), new PlaceholderVolume(7168), $volumes[3]],
            static function (string $id) use (&$calls, $articles): array {
                $calls[] = $id;

                return $articles[$id];
            },
            new InspectionBudget(32, 1048576, 10),
        );

        $result = (new Inspector)->inspect($ranges->read(...));

        $this->assertSame(PasswordVerdict::VerifiedEncrypted, $result['verdict'], $result['reason']);
        $this->assertSame('encrypted-header', $result['reason']);
        $this->assertSame([], array_values(array_filter($calls, static fn (string $id): bool => ! str_starts_with($id, 'v0') && ! str_starts_with($id, 'v3'))));
        $this->assertSame(array_unique($calls), $calls);
        $this->assertSame([
            ['start' => 0, 'size' => 7168, 'placeholder' => false],
            ['start' => 7168, 'size' => 7168, 'placeholder' => true],
            ['start' => 14336, 'size' => 7168, 'placeholder' => true],
            ['start' => 21504, 'size' => 6032, 'placeholder' => false],
        ], array_map(static fn (array $volume): array => array_intersect_key($volume, ['start' => 1, 'size' => 1, 'placeholder' => 1]), $ranges->volumes()));
        $held = $ranges->heldArticles();
        $this->assertSame([0, 3], array_values(array_unique(array_column($held, 'volume'))));
        foreach ($held as $article) {
            $this->assertSame(substr($this->fixture('obfuscated-encrypted'), $ranges->volumes()[$article['volume']]['start'] + $article['offset'], strlen($article['data'])), $article['data']);
        }
    }

    public function test_a_read_inside_a_placeholder_is_a_missing_range(): void
    {
        $data = $this->fixture('obfuscated-encrypted');
        // A 56-byte last part holds less than the next header: the header starts in part 3.
        [$volumes, $articles] = $this->posting($data, 9160);
        $this->assertSame(56, strlen($data) - 3 * 9160);
        $calls = [];
        $ranges = new ArchiveRanges(
            [$volumes[0], new PlaceholderVolume(9160), new PlaceholderVolume(9160), $volumes[3]],
            static function (string $id) use (&$calls, $articles): array {
                $calls[] = $id;

                return $articles[$id];
            },
            new InspectionBudget(32, 1048576, 10),
        );

        $result = (new Inspector)->inspect($ranges->read(...));

        $this->assertSame(PasswordVerdict::Incomplete, $result['verdict']);
        $this->assertSame('missing-range', $result['reason']);
        $this->assertSame([], array_values(array_filter($calls, static fn (string $id): bool => ! str_starts_with($id, 'v0') && ! str_starts_with($id, 'v3'))));
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/SevenZip/'.$name.'.7z');
    }

    /** @return array{list<ArchiveCandidate>, array<string, array{success: bool, data: string, metadata: YencArticleMetadata}>} */
    private function posting(string $data, int $volumeSize, int $articleSize = 4096): array
    {
        $volumes = $articles = [];
        foreach (str_split($data, $volumeSize) as $v => $volume) {
            $ids = [];
            $parts = str_split($volume, $articleSize);
            foreach ($parts as $p => $part) {
                $ids[] = $id = 'v'.$v.'p'.$p;
                $articles[$id] = ['success' => true, 'data' => $part,
                    'metadata' => new YencArticleMetadata(strlen($volume), $p + 1, count($parts), $p * $articleSize, strlen($part))];
            }
            $volumes[] = new ArchiveCandidate('"x'.$v.'" yEnc', [$ids[0]], $v === 0, $v, array_slice($ids, 1));
        }

        return [$volumes, $articles];
    }
}
