<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NNTP\ArticleTimeLocator;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NNTPService;
use DariusIII\NetNntp\Error;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ArticleTimeLocatorTest extends TestCase
{
    private const int FIRST = 1_000;

    private const int LAST = 2_000_000;

    private const int BASE_TIME = 1_750_000_000;

    public function test_it_finds_the_article_at_a_time_despite_old_dated_posts(): void
    {
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $target = 1_234_567;
        $nntp = $this->server(static fn (int $article): ?int => $article % 50 === 0
            ? self::BASE_TIME - 5 * 365 * 86_400
            : self::BASE_TIME + $article * 10);

        $found = (new ArticleTimeLocator)->locate($nntp, ['first' => self::FIRST, 'last' => (string) self::LAST], self::BASE_TIME + $target * 10);

        $this->assertLessThanOrEqual(2_000, abs($found - $target));
        $this->assertLessThanOrEqual($target, $found);
        $this->assertSame(0, $queries);
    }

    public function test_it_returns_the_first_article_when_no_range_has_twenty_dated_lines(): void
    {
        $nntp = $this->server(static fn (int $article): ?int => $article % 20 === 0 ? self::BASE_TIME : null);

        $this->assertSame(self::FIRST, (new ArticleTimeLocator)->locate($nntp, ['first' => self::FIRST, 'last' => self::LAST], self::BASE_TIME + 1));
    }

    public function test_an_nntp_error_is_a_failure_naming_the_provider(): void
    {
        /** @var NNTPService&MockInterface $nntp */
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('provider')->andReturn($this->provider());
        $nntp->shouldReceive('getXOVER')->andReturn(new Error('Connection reset', 400));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('super');

        (new ArticleTimeLocator)->locate($nntp, ['first' => self::FIRST, 'last' => self::LAST], self::BASE_TIME);
    }

    /**
     * A server holding every article from FIRST to LAST, dated by $dateOf (null = no Date header).
     *
     * @param  callable(int): ?int  $dateOf
     */
    private function server(callable $dateOf): NNTPService
    {
        /** @var NNTPService&MockInterface $nntp */
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('provider')->andReturn($this->provider());
        $nntp->shouldReceive('getXOVER')->andReturnUsing(static function (string $range) use ($dateOf): array {
            [$from, $to] = array_map('intval', explode('-', $range));
            $lines = [];
            for ($article = max($from, self::FIRST); $article <= min($to, self::LAST); $article++) {
                $date = $dateOf($article);
                $lines[] = ['Number' => (string) $article, 'Subject' => 'post', 'Date' => $date === null ? '' : gmdate('D, d M Y H:i:s', $date).' GMT'];
            }

            return $lines;
        });

        return $nntp;
    }

    private function provider(): NntpProvider
    {
        return new NntpProvider(2, 'super', 'super.example.com', 563, true, 'user', 'pass', 10, 120, true);
    }
}
