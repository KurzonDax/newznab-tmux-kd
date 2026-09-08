<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NNTP\BoundedNntpStream;
use App\Services\NNTP\NntpProvider;
use PHPUnit\Framework\TestCase;

class BoundedNntpStreamTest extends TestCase
{
    public function test_aborted_socket_response_closes_the_connection_and_counts_protocol_bytes(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
        $this->assertIsResource($server);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $peer = stream_socket_accept($server, 2);
            if ($peer === false) {
                exit(1);
            }
            stream_set_timeout($peer, 2);
            fwrite($peer, "200 fixture ready\r\n");
            $request = fgets($peer);
            fwrite($peer, "222 body\r\n".str_repeat('x', 8192)."\r\n.\r\n");
            $read = @fread($peer, 1);
            $closed = ($read === '' || $read === false) && ! stream_get_meta_data($peer)['timed_out'];
            fclose($peer);
            fclose($server);
            exit($closed && str_starts_with($request, 'BODY ') ? 0 : 1);
        }
        fclose($server);
        $provider = new NntpProvider(1, 'local', '127.0.0.1', $port, false, '', '', 1, 1, true);
        $result = (new BoundedNntpStream)->fetch($provider, '<bounded@example.invalid>', false, 128, microtime(true) + 1);
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertNull($result->data);
        $this->assertSame(128, $result->bytes);
        $this->assertSame('response_limit', $result->reason);
    }

    public function test_an_expired_deadline_reads_no_more_protocol_bytes(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "222 article\r\ndata\r\n.\r\n");
        rewind($stream);
        $result = (new BoundedNntpStream)->readResponse($stream, 100, microtime(true) - 1, 222);
        $this->assertSame('deadline', $result->reason);
        $this->assertSame(0, $result->bytes);
        $this->assertSame(0, ftell($stream));
        fclose($stream);
    }

    public function test_an_over_limit_multiline_response_never_reads_beyond_its_reservation(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "222 article\r\n".str_repeat('x', 100)."\r\n.\r\n");
        rewind($stream);
        $result = (new BoundedNntpStream)->readResponse($stream, 32, microtime(true) + 5, 222);
        $this->assertSame(32, $result->bytes);
        $this->assertSame('response_limit', $result->reason);
        $this->assertNull($result->data);
        fclose($stream);
    }
}
