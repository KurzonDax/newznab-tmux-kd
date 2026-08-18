<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Binaries;

use App\Services\Binaries\HeaderParser;
use Illuminate\Support\Facades\Log;
use Tests\Support\NeverBlacklistedService;
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

    public function test_parse_rejects_headers_whose_article_number_is_not_numeric(): void
    {
        $parser = new HeaderParser(new NeverBlacklistedService);

        $result = $parser->parse([
            ['Number' => '500', 'Subject' => 'Some.Release (1/10) yEnc', 'Bytes' => 100],
            // A shifted overview format puts the subject in 'Number' and the
            // poster in 'Subject'.
            ['Number' => '2O8W9UZ0WrU37hNpMTuHrRZojgZjo [3/10] yEnc (131/165)', 'Subject' => 'poster@example.com'],
            ['Number' => '', 'Subject' => 'Some.Release (2/10) yEnc'],
            ['Number' => 'abc', 'Subject' => 'Some.Release (3/10) yEnc'],
        ], 'alt.test');

        $this->assertSame(['500'], $result['received'], 'Only numeric article numbers count as received.');
        $this->assertSame(3, $result['rejected']);
        $this->assertSame(3, $parser->getRejectedCount());
        $this->assertCount(1, $result['headers']);
        $this->assertSame(0, $result['notYEnc'], 'A shifted header is rejected outright, not counted as non-yEnc.');
    }

    public function test_parse_counters_reset_between_batches(): void
    {
        $parser = new HeaderParser(new NeverBlacklistedService);

        $parser->parse([['Number' => 'garbage', 'Subject' => 'poster@example.com']], 'alt.test');
        $parser->reset();
        $result = $parser->parse([['Number' => '900', 'Subject' => 'Some.Release (1/2) yEnc']], 'alt.test');

        $this->assertSame(0, $result['rejected']);
        $this->assertSame(0, $parser->getRejectedCount());
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
