<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use App\Services\NNTP\DTO\BoundedArticleResponse;
use RuntimeException;

/** A disposable uncompressed connection; every protocol response byte shares one cap. */
class BoundedNntpStream
{
    private int $bytes = 0;

    public function fetch(NntpProvider $provider, string $messageId, bool $head, int $maxBytes, float $deadline): BoundedArticleResponse
    {
        $this->bytes = 0;
        if (! preg_match('/^<[^<>\s]+>$/D', $messageId) || $deadline <= microtime(true)) {
            return new BoundedArticleResponse(null, 0, 'invalid_request');
        }
        $stream = null;
        try {
            $timeout = min(5.0, $provider->timeout, $deadline - microtime(true));
            $stream = @stream_socket_client(($provider->ssl ? 'tls' : 'tcp').'://'.$provider->host.':'.$provider->port,
                $errorCode, $error, $timeout, STREAM_CLIENT_CONNECT);
            if ($stream === false) {
                throw new RuntimeException('connect_failed');
            }
            $greeting = $this->line($stream, $maxBytes, $deadline);
            if (! in_array(substr($greeting, 0, 3), ['200', '201'], true)) {
                throw new RuntimeException('greeting_failed');
            }
            if ($provider->username !== '') {
                $this->write($stream, 'AUTHINFO USER '.$provider->username);
                $reply = $this->line($stream, $maxBytes, $deadline);
                if (str_starts_with($reply, '381')) {
                    $this->write($stream, 'AUTHINFO PASS '.$provider->password);
                    $reply = $this->line($stream, $maxBytes, $deadline);
                }
                if (! str_starts_with($reply, '281')) {
                    throw new RuntimeException('authentication_failed');
                }
            }
            $this->write($stream, ($head ? 'HEAD ' : 'BODY ').$messageId);

            return $this->readResponse($stream, $maxBytes, $deadline, $head ? 221 : 222);
        } catch (RuntimeException $e) {
            return new BoundedArticleResponse(null, $this->bytes, $e->getMessage());
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** @param resource $stream */
    public function readResponse(mixed $stream, int $maxBytes, float $deadline, int $status): BoundedArticleResponse
    {
        try {
            $reply = $this->line($stream, $maxBytes, $deadline);
            if ((int) substr($reply, 0, 3) !== $status) {
                throw new RuntimeException('article_unavailable');
            }
            $data = '';
            while (true) {
                $line = $this->line($stream, $maxBytes, $deadline);
                if ($line === ".\r\n") {
                    return new BoundedArticleResponse($data, $this->bytes);
                }
                $data .= str_starts_with($line, '..') ? substr($line, 1) : $line;
            }
        } catch (RuntimeException $e) {
            return new BoundedArticleResponse(null, $this->bytes, $e->getMessage());
        }
    }

    /** @param resource $stream */
    private function line(mixed $stream, int $maxBytes, float $deadline): string
    {
        $line = '';
        do {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('deadline');
            }
            if ($this->bytes >= $maxBytes) {
                throw new RuntimeException('response_limit');
            }
            $timeout = min(5.0, $remaining);
            stream_set_timeout($stream, (int) $timeout, (int) (($timeout - (int) $timeout) * 1000000));
            $chunk = fgets($stream, min(8192, $maxBytes - $this->bytes + 1));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('read_failed');
            }
            $this->bytes += strlen($chunk);
            $line .= $chunk;
        } while (! str_ends_with($line, "\n"));

        return $line;
    }

    /** @param resource $stream */
    private function write(mixed $stream, string $command): void
    {
        if (str_contains($command, "\r") || str_contains($command, "\n")
            || fwrite($stream, $command."\r\n") !== strlen($command) + 2) {
            throw new RuntimeException('write_failed');
        }
    }
}
