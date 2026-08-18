<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NNTP\NNTPService;
use Tests\TestCase;

/**
 * Regression tests for the overview format cache shared between
 * {@see NNTPService::getXOVER()} and the vendor client's getOverview().
 *
 * Part repair goes through getOverview(), which caches the format with 'Number'
 * prepended; the range scan goes through getXOVER() on the same connection. When
 * the two disagreed about whether 'Number' is part of the cached format, every
 * field of every header shifted by one slot: 'Number' held the subject, 'Subject'
 * held the poster, and the (non numeric) subject string ended up being written to
 * usenet_groups.last_record, permanently stalling the group.
 */
final class NNTPServiceOverviewFormatCacheTest extends TestCase
{
    /**
     * LIST OVERVIEW.FMT as the server reports it: the article number is implicit
     * and is never part of the format.
     *
     * @var array<string, bool>
     */
    private const SERVER_FORMAT = [
        'Subject' => false,
        'From' => false,
        'Date' => false,
        'Message-ID' => false,
        'References' => false,
        'Bytes' => false,
        'Lines' => false,
        'Xref' => true,
    ];

    /** One XOVER line: article number first, then the format fields. */
    private const HEADER_LINE = "679871775\tSome.Release (1/10) yEnc\tposter@example.com\t26 Jun 2014 13:08:22 GMT\t<part1of1@example.local>\t\t123\t9\tXref: news.example.com alt.test:679871775";

    /** @var list<string> */
    private const HEADER_FIELDS = [
        '679871775',
        'Some.Release (1/10) yEnc',
        'poster@example.com',
        '26 Jun 2014 13:08:22 GMT',
        '<part1of1@example.local>',
        '',
        '123',
        '9',
        'Xref: news.example.com alt.test:679871775',
    ];

    public function test_get_xover_caches_the_format_in_the_shape_the_vendor_client_expects(): void
    {
        $client = $this->makeClient();

        $headers = $client->getXOVER('679871775-679871775');

        $this->assertHeaderIsMappedCorrectly($headers);
        $this->assertSame(
            ['Number', 'Subject', 'From', 'Date', 'Message-ID', 'References', 'Bytes', 'Lines', 'Xref'],
            array_keys((array) $client->exposedOverviewFormatCache()),
            "getXOVER() must cache the format with 'Number' first so the vendor client's getOverview() maps the same way."
        );
    }

    public function test_get_xover_maps_headers_when_part_repair_populated_the_cache_first(): void
    {
        $client = $this->makeClient();
        // Part repair ran first on this connection: the vendor client cached the
        // format with 'Number' prepended.
        $client->seedOverviewFormatCache(array_merge(['Number' => false], self::SERVER_FORMAT));

        $headers = $client->getXOVER('679871775-679871775');

        $this->assertHeaderIsMappedCorrectly($headers);
        $this->assertSame(0, $client->overviewFormatCalls, 'A usable cache must not be re-fetched from the server.');
    }

    public function test_get_xover_normalises_a_legacy_cache_that_omits_the_article_number(): void
    {
        $client = $this->makeClient();
        // A cache left behind by the old getXOVER(), which stored the format
        // without 'Number'.
        $client->seedOverviewFormatCache(self::SERVER_FORMAT);

        $headers = $client->getXOVER('679871775-679871775');

        $this->assertHeaderIsMappedCorrectly($headers);
        $this->assertSame(
            ['Number', 'Subject', 'From', 'Date', 'Message-ID', 'References', 'Bytes', 'Lines', 'Xref'],
            array_keys((array) $client->exposedOverviewFormatCache())
        );
    }

    public function test_get_overview_maps_headers_when_the_range_scan_populated_the_cache_first(): void
    {
        $client = $this->makeClient();
        $client->getXOVER('679871775-679871775');

        $overview = $client->getOverview('679871775-679871775', true, false);

        $this->assertIsArray($overview);
        $this->assertCount(1, $overview);

        $article = reset($overview);

        $this->assertSame('679871775', $article['Number'] ?? null);
        $this->assertSame('Some.Release (1/10) yEnc', $article['Subject'] ?? null);
        $this->assertSame('26 Jun 2014 13:08:22 GMT', $article['Date'] ?? null);
    }

    /**
     * @param  mixed  $headers  The getXOVER() return value.
     */
    private function assertHeaderIsMappedCorrectly(mixed $headers): void
    {
        $this->assertIsArray($headers);
        $this->assertCount(1, $headers);

        $header = reset($headers);

        $this->assertSame('679871775', $header['Number'] ?? null, 'Number must hold the article number.');
        $this->assertSame('Some.Release (1/10) yEnc', $header['Subject'] ?? null);
        $this->assertSame('poster@example.com', $header['From'] ?? null);
        $this->assertSame('26 Jun 2014 13:08:22 GMT', $header['Date'] ?? null);
        $this->assertSame('<part1of1@example.local>', $header['Message-ID'] ?? null);
        $this->assertSame('news.example.com alt.test:679871775', $header['Xref'] ?? null);
    }

    private function makeClient(): NNTPServiceOverviewFormatProbe
    {
        $client = new NNTPServiceOverviewFormatProbe;
        $client->overviewFormat = self::SERVER_FORMAT;
        $client->textResponse = [self::HEADER_LINE];
        $client->overviewFields = [self::HEADER_FIELDS];

        return $client;
    }
}

/**
 * Drives getXOVER()/getOverview() over canned server responses: every socket
 * level call is stubbed, so only the format-cache handling is exercised.
 */
final class NNTPServiceOverviewFormatProbe extends NNTPService
{
    /** @var array<string, bool> */
    public array $overviewFormat = [];

    /** @var list<string> Raw XOVER lines returned to getXOVER(). */
    public array $textResponse = [];

    /** @var list<list<string>> Pre-split XOVER fields returned to getOverview(). */
    public array $overviewFields = [];

    public int $overviewFormatCalls = 0;

    /**
     * The parent constructor reads settings and NNTP config; this probe never
     * touches a socket, so none of that is needed.
     */
    public function __construct() {}

    /**
     * @param  array<string, bool>|null  $cache
     */
    public function seedOverviewFormatCache(?array $cache): void
    {
        $this->_overviewFormatCache = $cache;
    }

    /**
     * @return array<string, bool>|null
     */
    public function exposedOverviewFormatCache(): ?array
    {
        return $this->_overviewFormatCache;
    }

    public function getOverviewFormat(bool $_forceNames = true, bool $_full = false): mixed
    {
        $this->overviewFormatCalls++;

        return $_full ? $this->overviewFormat : array_keys($this->overviewFormat);
    }

    public function _getTextResponse(): NNTPService|array|string
    {
        return $this->textResponse;
    }

    protected function _checkConnection(bool $reSelectGroup = true): mixed
    {
        return true;
    }

    protected function _enableCompression(bool $secondTry = false): mixed
    {
        return true;
    }

    protected function _sendCommand(string $cmd): mixed
    {
        return 224; // ResponseCode::OverviewFollows
    }

    protected function cmdXOver(?string $range = null): mixed
    {
        return $this->overviewFields;
    }

    public function __destruct() {}
}
