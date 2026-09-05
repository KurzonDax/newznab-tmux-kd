<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\HeaderScanDirection;
use App\Services\Binaries\BinariesService;
use ReflectionMethod;
use RuntimeException;

/**
 * A binaries service whose scan() only records the article window it was handed.
 *
 * It exists to drive the header-update chunk walk without a news server or a database:
 * the walk under test is pure arithmetic over the configured message buffer, and every
 * chunk it asks for shows up in {@see self::$scannedRanges}. The recorded scan count is
 * capped so a walk that stops advancing fails the test instead of hanging it.
 */
final class RecordingBinariesService extends BinariesService
{
    /**
     * Every article window scan() was called with, in order.
     *
     * @var list<array{first: int, last: int}>
     */
    public array $scannedRanges = [];

    /**
     * How many chunks a walk may ask for before it is declared non-terminating.
     */
    public int $scanLimit = 100;

    /**
     * @param  array<string, mixed>  $groupMySQL
     * @param  array<int, int|string>|null  $missingParts
     * @return array<string, mixed>
     */
    public function scan(array $groupMySQL, int $first, int $last, HeaderScanDirection $direction, string $type = 'update', ?array $missingParts = null): array
    {
        $this->scannedRanges[] = ['first' => $first, 'last' => $last];

        if (count($this->scannedRanges) > $this->scanLimit) {
            throw new RuntimeException(
                'The article walk asked for more than '.$this->scanLimit.' chunks, so it is not terminating.'
            );
        }

        return [
            'firstArticleNumber' => $first,
            'lastArticleNumber' => $last,
            'firstArticleDate' => '2026-01-01 00:00:00',
            'lastArticleDate' => '2026-01-02 00:00:00',
        ];
    }

    /**
     * The group pointer is bookkeeping around the walk, and moving it needs a database.
     *
     * @param  array<string, mixed>  $groupMySQL
     * @param  array<string, mixed>  $groupNNTP
     * @param  array<string, mixed>  $scanSummary
     */
    protected function updateGroupAfterScan(array &$groupMySQL, array $groupNNTP, array $scanSummary, int $last): void {}

    /**
     * @param  array<string, mixed>  $groupMySQL
     * @param  array<string, mixed>  $groupNNTP
     * @return array<string, mixed>
     */
    public function articleRange(array $groupMySQL, array $groupNNTP, int $maxHeaders = 0): array
    {
        $calculate = new ReflectionMethod(BinariesService::class, 'calculateArticleRange');

        return $calculate->invoke($this, $groupMySQL, $groupNNTP, $maxHeaders);
    }

    /**
     * @param  array<string, mixed>  $groupMySQL
     * @param  array<string, mixed>  $groupNNTP
     * @param  array<string, mixed>  $range
     */
    public function walkArticleRange(array $groupMySQL, array $groupNNTP, array $range): void
    {
        $walk = new ReflectionMethod(BinariesService::class, 'processArticleRange');

        $walk->invokeArgs($this, [&$groupMySQL, $groupNNTP, $range]);
    }
}
