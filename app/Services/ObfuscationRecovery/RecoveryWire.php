<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Services\NNTP\NntpProvider;
use Closure;
use InvalidArgumentException;
use RuntimeException;

final class RecoveryWire
{
    /** @var resource|null */
    private $socket = null;

    private string $buffer = '';

    private int $received = 0;

    private int $sent = 0;

    private int $opened = 0;

    private int $maximumPlaintext = 0;

    private float $deadline = 0;

    private bool $used = false;

    /** @var Closure(bool):void|null */
    private ?Closure $connectionObserver = null;

    /** @param Closure(bool):void $observer */
    public function observeConnections(Closure $observer): self
    {
        if ($this->used) {
            throw new InvalidArgumentException('transport_already_started');
        }
        $this->connectionObserver = $observer;

        return $this;
    }

    public function __construct(
        private readonly float $connectTimeout = 10,
        private readonly float $readTimeout = 10,
        private readonly float $requestTimeout = 30,
        private readonly ?string $caFile = null,
    ) {
        if ($connectTimeout <= 0 || $connectTimeout > 10 || $readTimeout <= 0 || $readTimeout > 10
            || $requestTimeout <= 0 || $requestTimeout > 30) {
            throw new InvalidArgumentException('invalid_transport_deadline');
        }
    }

    public function fetch(NntpProvider $provider, string $messageId, int $maximumDecoded, bool $prefixOnly,
        int $reservedBytes, int $closeAllowance, bool $declarationOnly = false): RecoveryTransfer
    {
        if ($this->used || $reservedBytes > 2097152 || $closeAllowance < 32768 || $closeAllowance >= $reservedBytes
            || ($prefixOnly && ($maximumDecoded > 16384 || $reservedBytes > 196608))) {
            throw new InvalidArgumentException('invalid_transport_allowance');
        }
        $this->used = true;
        $identity = new RecoveryIdentity;
        $messageId = $identity->messageId($messageId);
        $decoder = new RecoveryYenc($maximumDecoded, $prefixOnly, $declarationOnly);
        $start = self::clock();
        $this->deadline = $start + $this->requestTimeout;
        $this->maximumPlaintext = $reservedBytes - $closeAllowance;
        $window = null;
        $article = null;
        $reason = null;
        $outcome = 'success';
        try {
            $window = $this->connect($provider);
            $this->authenticate($provider);
            $this->command('BODY <'.$messageId.'>');
            $response = $this->readLine();
            if (preg_match('/^4(?:23|30)(?: |$)/', $response)) {
                throw new RuntimeException('article_absent');
            }
            if (! preg_match('/^222 [0-9]+ (<[^<> ]+>)(?: |$)/D', $response, $match)) {
                throw new RuntimeException('unexpected_body_response');
            }
            if ($identity->messageId($match[1]) !== $messageId) {
                throw new InvalidArgumentException('response_identity_mismatch');
            }
            while (true) {
                $line = $this->readLine();
                if ($line === '.') {
                    $article = $decoder->finish();
                    break;
                }
                if (str_starts_with($line, '.')) {
                    if (! str_starts_with($line, '..')) {
                        throw new InvalidArgumentException('invalid_nntp_dot_stuffing');
                    }
                    $line = substr($line, 1);
                }
                if ($decoder->line($line)) {
                    $article = $decoder->prefix();
                    break;
                }
            }
        } catch (InvalidArgumentException $e) {
            $outcome = 'semantic_failure';
            $reason = $e->getMessage();
        } catch (RuntimeException $e) {
            $outcome = 'transport_failure';
            $reason = $e->getMessage();
        } finally {
            $buffered = strlen($this->buffer);
            $this->close();
        }

        return new RecoveryTransfer($article, $outcome, $reason, $this->received, $this->sent, null,
            $this->opened, $window, $buffered, $this->socket === null, (int) ((self::clock() - $start) * 1000), $decoder->decodedBytes());
    }

    public function overview(NntpProvider $provider, string $group, int $first, int $last): RecoveryOverviewTransfer
    {
        if ($this->used || ! $provider->isPrimary() || $first < 1 || $last < $first || $last === PHP_INT_MAX
            || $last - $first >= 20000 || preg_match('/^[a-zA-Z0-9+_.-]{1,255}$/D', $group) !== 1) {
            throw new InvalidArgumentException('invalid_overview_request');
        }
        $this->used = true;
        $start = self::clock();
        $this->deadline = $start + $this->requestTimeout;
        $this->maximumPlaintext = 33554432 - 65536;
        $headers = $seen = [];
        $window = null;
        $outcome = 'success';
        $reason = null;
        try {
            $window = $this->connect($provider);
            $this->authenticate($provider);
            $this->command('GROUP '.$group);
            if (! preg_match('/^211 [0-9]+ [0-9]+ [0-9]+ '.preg_quote($group, '/').'(?: |$)/D', $this->readLine())) {
                throw new RuntimeException('group_selection_failed');
            }
            $this->command('XOVER '.$first.'-'.$last);
            if (! preg_match('/^224(?: |$)/', $this->readLine())) {
                throw new RuntimeException('overview_request_failed');
            }
            while (($line = $this->readLine()) !== '.') {
                if (str_starts_with($line, '..')) {
                    $line = substr($line, 1);
                }
                $fields = explode("\t", $line);
                $number = filter_var($fields[0] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => $first, 'max_range' => $last]]);
                if (count($fields) < 8 || count($fields) > 9 || $number === false || isset($seen[$number]) || count($headers) >= 20000) {
                    throw new InvalidArgumentException('invalid_overview_response');
                }
                $seen[$number] = true;
                $headers[] = ['Number' => $number, 'Subject' => $fields[1], 'From' => $fields[2], 'Date' => $fields[3],
                    'Message-ID' => $fields[4], 'References' => $fields[5], 'Bytes' => $fields[6], 'Lines' => $fields[7], 'Xref' => $fields[8] ?? ''];
            }
        } catch (InvalidArgumentException $exception) {
            $outcome = 'semantic_failure';
            $reason = $exception->getMessage();
        } catch (RuntimeException $exception) {
            $outcome = 'transport_failure';
            $reason = $exception->getMessage();
        } finally {
            $buffered = strlen($this->buffer);
            $this->close();
        }

        return new RecoveryOverviewTransfer(new RecoveryTransfer(null, $outcome, $reason, $this->received, $this->sent, null,
            $this->opened, $window, $buffered, true, (int) ((self::clock() - $start) * 1000)), $outcome === 'success' ? $headers : []);
    }

    private function authenticate(NntpProvider $provider): void
    {
        $greeting = $this->readLine();
        if (! preg_match('/^20[01](?: |$)/', $greeting)) {
            throw new RuntimeException('provider_greeting_failed');
        }
        if ($provider->username !== '') {
            $this->command('AUTHINFO USER '.$provider->username);
            $auth = $this->readLine();
            if (str_starts_with($auth, '381 ')) {
                $this->command('AUTHINFO PASS '.$provider->password);
                $auth = $this->readLine();
            }
            if (! str_starts_with($auth, '281 ')) {
                throw new RuntimeException('provider_authentication_failed');
            }
        }
    }

    private function connect(NntpProvider $provider): ?int
    {
        if (preg_match('/^[a-zA-Z0-9_.:-]+$/D', $provider->host) !== 1 || $provider->port < 1 || $provider->port > 65535
            || strlen($provider->username) > 1024 || strlen($provider->password) > 4096
            || strpbrk($provider->username.$provider->password, "\r\n\0") !== false) {
            throw new InvalidArgumentException('invalid_provider_configuration');
        }
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $provider->host,
            'SNI_enabled' => true, 'disable_compression' => true];
        if ($this->caFile !== null) {
            $ssl['cafile'] = $this->caFile;
        }
        $context = stream_context_create(['ssl' => $ssl]);
        $host = str_contains($provider->host, ':') ? '['.$provider->host.']' : $provider->host;
        $socket = $this->quiet(fn () => stream_socket_client('tcp://'.$host.':'.$provider->port, $errorCode, $error,
            min($this->connectTimeout, $this->remaining()), STREAM_CLIENT_CONNECT, $context));
        if ($socket === false) {
            throw new RuntimeException('provider_connect_failed');
        }
        $this->socket = $socket;
        $this->opened++;
        ($this->connectionObserver ?? static function (bool $open): void {})(true);
        stream_set_read_buffer($socket, 0);
        stream_set_write_buffer($socket, 0);
        $window = null;
        if (function_exists('socket_import_stream')) {
            $native = socket_import_stream($socket);
            if ($native !== false) {
                socket_set_option($native, SOL_SOCKET, SO_RCVBUF, 32768);
                $observed = socket_get_option($native, SOL_SOCKET, SO_RCVBUF);
                $window = is_int($observed) ? $observed : null;
                unset($native);
            }
        }
        $this->setTimeout();
        if ($provider->ssl && $this->quiet(fn () => stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) !== true) {
            throw new RuntimeException('provider_tls_failed');
        }

        return $window;
    }

    private function command(string $command): void
    {
        $bytes = $command."\r\n";
        if ($this->sent + $this->received + strlen($bytes) > $this->maximumPlaintext) {
            throw new InvalidArgumentException('transport_attempt_cap');
        }
        while ($bytes !== '') {
            $this->setTimeout();
            $written = $this->quiet(fn () => fwrite($this->socket, $bytes));
            if ($written === false || $written === 0) {
                throw new RuntimeException('provider_write_failed');
            }
            $this->sent += $written;
            $bytes = substr($bytes, $written);
        }
    }

    private function readLine(): string
    {
        while (($offset = strpos($this->buffer, "\r\n")) === false) {
            if (strlen($this->buffer) > 8192) {
                throw new InvalidArgumentException('nntp_line_cap');
            }
            $allowance = $this->maximumPlaintext - $this->received - $this->sent;
            if ($allowance <= 0) {
                throw new InvalidArgumentException('transport_attempt_cap');
            }
            $this->setTimeout();
            $bytes = $this->quiet(fn () => fread($this->socket, min(8192, $allowance)));
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('provider_read_failed');
            }
            $this->received += strlen($bytes);
            $this->buffer .= $bytes;
        }
        if ($offset > 8192) {
            throw new InvalidArgumentException('nntp_line_cap');
        }
        $line = substr($this->buffer, 0, $offset);
        $this->buffer = substr($this->buffer, $offset + 2);

        return $line;
    }

    private function setTimeout(): void
    {
        $seconds = min($this->readTimeout, $this->remaining());
        stream_set_timeout($this->socket, (int) $seconds, max(1, (int) (($seconds - (int) $seconds) * 1000000)));
    }

    private function remaining(): float
    {
        $remaining = $this->deadline - self::clock();
        if ($remaining <= 0) {
            throw new RuntimeException('request_deadline');
        }

        return $remaining;
    }

    private function close(): void
    {
        $opened = is_resource($this->socket);
        if (is_resource($this->socket)) {
            $this->quiet(fn () => stream_socket_shutdown($this->socket, STREAM_SHUT_RDWR));
            fclose($this->socket);
        }
        $this->socket = null;
        $this->buffer = '';
        if ($opened && $this->connectionObserver !== null) {
            ($this->connectionObserver)(false);
        }
    }

    private static function clock(): float
    {
        return hrtime(true) / 1000000000;
    }

    /** @template TResult
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    private function quiet(Closure $operation): mixed
    {
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
