<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NNTP\NNTPService;
use DariusIII\NetNntp\Error as NntpError;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NNTPServiceErrorReturnTest extends TestCase
{
    #[Test]
    public function get_messages_returns_a_descriptive_error_for_an_invalid_identifier_type(): void
    {
        $result = (new NNTPServiceErrorReturnProbe)->getMessages('alt.test', new \stdClass);

        $this->assertInstanceOf(NntpError::class, $result);
        $this->assertSame(
            'Wrong Identifier type, array, int or string accepted. This type of var was passed: object',
            $result->getMessage(),
        );
        $this->assertNull($result->getCode());
    }

    #[Test]
    public function compressed_overview_decompression_failure_returns_its_error(): void
    {
        [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($writer, "not-gzip.\r\n");

        try {
            $result = (new NNTPServiceErrorReturnProbe)->readCompressedOverview($reader);
        } finally {
            fclose($reader);
            fclose($writer);
        }

        $this->assertNntpError($result, 'Decompression of OVER headers failed.');
    }

    #[Test]
    public function compressed_overview_fetch_failure_returns_its_error(): void
    {
        $socket = fopen('php://temp', 'r+');
        $this->assertIsResource($socket);

        try {
            $result = (new NNTPServiceErrorReturnProbe)->readCompressedOverview($socket);
        } finally {
            fclose($socket);
        }

        $this->assertNntpError($result, 'Error fetching data from usenet server while downloading OVER headers.');
    }

    #[Test]
    public function compressed_overview_unspecified_failure_returns_its_error(): void
    {
        $socket = fopen('php://temp', 'r+');
        $this->assertIsResource($socket);
        $this->assertFalse(fgetc($socket));
        $this->assertTrue(feof($socket));

        try {
            $result = (new NNTPServiceErrorReturnProbe)->readCompressedOverview($socket);
        } finally {
            fclose($socket);
        }

        $this->assertNntpError($result, 'Unspecified error while downloading OVER headers.');
    }

    private function assertNntpError(mixed $result, string $message): void
    {
        $this->assertInstanceOf(NntpError::class, $result);
        $this->assertSame($message, $result->getMessage());
        $this->assertSame(1000, $result->getCode());
    }
}

final class NNTPServiceErrorReturnProbe extends NNTPService
{
    public function __construct()
    {
        $this->_echo = false;
    }

    /**
     * @param  resource  $socket
     */
    public function readCompressedOverview(mixed $socket): array|string|NntpError
    {
        $this->_socket = $socket;

        return $this->_getXFeatureTextResponse();
    }

    public function __destruct() {}
}
