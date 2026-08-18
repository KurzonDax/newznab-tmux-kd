<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Binaries;

use App\Services\Binaries\HeaderParser;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class HeaderParserTest extends TestCase
{
    public function test_it_ignores_a_trailing_malformed_article_number(): void
    {
        $parser = new HeaderParser;

        $result = $parser->getArticleRange([
            ['Number' => '100', 'Date' => '2026-08-18 10:00:00'],
            ['Number' => '101', 'Date' => '2026-08-18 10:01:00'],
            ['Number' => 'fragmentary-response'],
        ], 'alt.test', 100, 101);

        $this->assertSame(100, $result['firstArticleNumber']);
        $this->assertSame(101, $result['lastArticleNumber']);
        $this->assertSame('2026-08-18 10:01:00', $result['lastArticleDate']);
    }

    public function test_it_ignores_and_warns_about_an_out_of_range_article_number(): void
    {
        Log::spy();
        $parser = new HeaderParser;

        $result = $parser->getArticleRange([
            ['Number' => '200', 'Date' => '2026-08-18 11:00:00'],
            ['Number' => '201', 'Date' => '2026-08-18 11:01:00'],
            ['Number' => '9', 'Date' => '2026-08-18 11:02:00'],
        ], 'alt.test', 200, 201);

        $this->assertSame(200, $result['firstArticleNumber']);
        $this->assertSame(201, $result['lastArticleNumber']);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Rejected XOVER article number while determining scan range.'
                && $context === [
                    'group' => 'alt.test',
                    'requested_first' => 200,
                    'requested_last' => 201,
                    'offending_value' => '9',
                    'reason' => 'out_of_range',
                ]);
    }

    public function test_it_returns_an_empty_summary_when_no_article_number_is_valid(): void
    {
        $parser = new HeaderParser;

        $result = $parser->getArticleRange([
            ['Number' => 'not-an-integer'],
            ['Number' => '999'],
        ], 'alt.test', 300, 301);

        $this->assertSame([], $result);
    }
}
