<?php

declare(strict_types=1);

namespace Tests\Support\Reconciliation;

use App\Services\NNTP\NNTPService;
use DariusIII\NetNntp\Error;
use Illuminate\Support\Facades\DB;

final class FakeHeaderNntp extends NNTPService
{
    /** @var array<int, int> Article number => unix time, for the legacy date bisection. */
    public array $articleDates = [];

    public int $xoverCalls = 0;

    public bool $leaseObservedDuringFetch = false;

    public bool $throwDuringFetch = false;

    public bool $selectFails = false;

    public int $groupFirst = 1;

    public int $groupLast = 2000;

    /** @param array<int, array<string, mixed>> $articles */
    public function __construct(public array $articles = []) {}

    public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
    {
        if ($this->selectFails) {
            return new Error('No such group');
        }

        return ['group' => $group, 'first' => $this->groupFirst, 'last' => $this->groupLast];
    }

    public function getXOVER(string $range): mixed
    {
        $this->xoverCalls++;
        $this->leaseObservedDuringFetch = DB::table('releases')
            ->where('id', 1)
            ->whereNotNull('recovery_claimed_at')
            ->exists();

        if ($this->throwDuringFetch) {
            throw new \RuntimeException('overview failed');
        }

        [$first, $last] = array_pad(explode('-', $range, 2), 2, null);
        $first = (int) $first;
        $last = $last === null || $last === '' ? $first : (int) $last;

        if ($first === $last && $this->articleDates !== []) {
            // A single-article probe: the date bisection asking when this article was posted.
            return [[
                'Number' => (string) $first,
                'Date' => gmdate('D, d M Y H:i:s \G\M\T', $this->dateFor($first)),
                'Subject' => 'probe',
                'From' => 'probe@example.org',
                'Message-ID' => '<probe@example.local>',
            ]];
        }

        $lines = [];

        foreach ($this->articles as $number => $line) {
            if ($number >= $first && $number <= $last) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public function doQuit(bool $force = false): mixed
    {
        return true;
    }

    public function __destruct() {}

    /**
     * Linear interpolation between the pinned article dates, so a bisection converges.
     */
    private function dateFor(int $article): int
    {
        $numbers = array_keys($this->articleDates);
        $low = min($numbers);
        $high = max($numbers);
        $article = max($low, min($high, $article));

        $span = $high - $low;

        if ($span <= 0) {
            return $this->articleDates[$low];
        }

        $elapsed = $this->articleDates[$high] - $this->articleDates[$low];

        return (int) round($this->articleDates[$low] + ($elapsed * (($article - $low) / $span)));
    }
}
