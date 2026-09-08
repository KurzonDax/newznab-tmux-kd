<?php

declare(strict_types=1);

namespace Tests\Support\ObfuscationRecovery;

use App\Services\NNTP\NntpProvider;

trait InteractsWithRecoveryNntpServer
{
    /** @var list<int> */
    private array $children = [];

    private ?string $certificateDirectory = null;

    private ?string $certificateAuthority = null;

    private function stopRecoveryServers(): void
    {
        foreach ($this->children as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
        }
        if ($this->certificateDirectory !== null) {
            foreach (glob($this->certificateDirectory.'/*') as $file) {
                unlink($file);
            }
            rmdir($this->certificateDirectory);
        }
    }

    /** @param array<string,string> $dialogue */
    private function server(string $response, bool $drip = false, bool $tls = false, int $position = 1, string $messageId = 'fixture@local', array $dialogue = []): NntpProvider
    {
        $this->assertTrue(extension_loaded('pcntl'), 'The loopback transport layer is required.');
        $context = stream_context_create($tls ? ['ssl' => ['local_cert' => $this->certificate(), 'verify_peer' => false]] : []);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $code, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        $pid = pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            $peer = stream_socket_accept($listener, 2);
            fclose($listener);
            if (! is_resource($peer)) {
                exit(1);
            }
            stream_set_timeout($peer, 2);
            if ($tls && @stream_socket_enable_crypto($peer, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
                fclose($peer);
                exit(3);
            }
            fwrite($peer, "200 local fixture\r\n");
            foreach ($dialogue === [] ? ["BODY <{$messageId}>\r\n" => $response] : $dialogue as $expected => $reply) {
                $command = fgets($peer);
                if ($command !== $expected) {
                    fclose($peer);
                    exit(2);
                }
                foreach (str_split($reply, $drip ? 1 : 4096) as $chunk) {
                    if (@fwrite($peer, $chunk) === false) {
                        break;
                    }
                    if ($drip) {
                        usleep(40000);
                    }
                }
            }
            fclose($peer);
            exit(0);
        }
        $this->children[] = $pid;
        fclose($listener);

        return NntpProvider::fromConfig(['position' => $position, 'name' => 'fixture-'.$position, 'host' => '127.0.0.1',
            'port' => (int) substr($address, strrpos($address, ':') + 1), 'ssl' => $tls]);
    }

    private function certificate(): string
    {
        if ($this->certificateDirectory !== null) {
            return $this->certificateDirectory.'/server.pem';
        }
        $this->certificateDirectory = sys_get_temp_dir().'/recovery-tls-'.bin2hex(random_bytes(12));
        mkdir($this->certificateDirectory, 0700);
        $config = $this->certificateDirectory.'/openssl.conf';
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[san]\nsubjectAltName=IP:127.0.0.1\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $request = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['config' => $config, 'digest_alg' => 'sha256', 'req_extensions' => 'san']);
        $certificate = openssl_csr_sign($request, null, $key, 1, ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'san'], 1);
        openssl_x509_export($certificate, $pem);
        openssl_pkey_export($key, $privateKey);
        $this->certificateAuthority = $this->certificateDirectory.'/ca.pem';
        file_put_contents($this->certificateAuthority, $pem);
        $path = $this->certificateDirectory.'/server.pem';
        file_put_contents($path, $pem.$privateKey);
        chmod($path, 0600);

        return $path;
    }
}
