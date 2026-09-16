<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\SevenZip;

use App\Services\AdditionalProcessing\Enums\PasswordVerdict;
use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** @phpstan-import-type Streams from HeaderParser */
final class Inspector
{
    public const string SIGNATURE = "7z\xBC\xAF\x27\x1C";

    public const int MAX_HEADER_BYTES = 1_048_576;

    /**
     * @param  Closure(int, int): string  $read  Absolute decoded archive ranges.
     * @return array{verdict: PasswordVerdict, files: list<array<string, mixed>>, reason: string}
     */
    public function inspect(Closure $read, ?float $deadline = null): array
    {
        try {
            $deadline ??= microtime(true) + 20;
            $start = $read(0, 32);
            if (strlen($start) !== 32 || ! str_starts_with($start, self::SIGNATURE)
                || ord($start[6]) !== 0 || ord($start[7]) > 4) {
                throw new RuntimeException('invalid-start-header');
            }
            self::checkCrc(substr($start, 12, 20), substr($start, 8, 4));
            $offset = self::uint64(substr($start, 12, 8));
            $size = self::uint64(substr($start, 20, 8));
            if ($offset > PHP_INT_MAX - 32 - $size || $size < 1 || $size > self::MAX_HEADER_BYTES) {
                throw new RuntimeException('invalid-next-header-range');
            }
            $data = $read(32 + $offset, $size);
            if (strlen($data) !== $size) {
                throw new RuntimeException('missing-next-header');
            }
            self::checkCrc($data, substr($start, 28, 4));
            $parser = new HeaderParser;
            $dataLimit = $offset;
            if (ord($data[0]) === 23) {
                $reader = new MetadataReader(substr($data, 1));
                $streams = $parser->streams($reader);
                $reader->end();
                $this->validateRanges($streams, $offset);
                $dataLimit = $streams['offset'];
                if (count($streams['folders']) !== 1) {
                    throw new RuntimeException('unsupported-encoded-header-streams');
                }
                $folder = $streams['folders'][0];
                $packedSize = $streams['packed'][0];
                if ($packedSize > self::MAX_HEADER_BYTES || $folder['size'] > self::MAX_HEADER_BYTES) {
                    throw new RuntimeException('encoded-header-budget');
                }
                $packed = $read(32 + $streams['offset'], $packedSize);
                if (strlen($packed) !== $packedSize) {
                    throw new RuntimeException('missing-encoded-header');
                }
                if ($streams['crcs'][0] !== null) {
                    self::checkCrc($packed, $streams['crcs'][0]);
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('inspection-time-budget');
                }
                if ($folder['encrypted']) {
                    return ['verdict' => PasswordVerdict::VerifiedEncrypted, 'files' => [], 'reason' => 'encrypted-header'];
                }
                if (count($folder['coders']) !== 1) {
                    throw new RuntimeException('unsupported-header-compression');
                }
                $coder = $folder['coders'][0];
                $data = $this->decode($packed, $coder['method'], $coder['properties'], $folder['size'], $deadline);
                if ($folder['crc'] === null) {
                    throw new RuntimeException('missing-decoded-header-crc');
                }
                self::checkCrc($data, $folder['crc']);
            }
            $manifest = $parser->manifest($data);
            if ($manifest['streams'] !== null) {
                $this->validateRanges($manifest['streams'], $dataLimit);
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException('inspection-time-budget');
            }

            return [
                'verdict' => $manifest['encrypted'] ? PasswordVerdict::VerifiedEncrypted : PasswordVerdict::VerifiedUnencrypted,
                'files' => $manifest['files'],
                'reason' => 'validated-header',
            ];
        } catch (Throwable $exception) {
            return ['verdict' => PasswordVerdict::Incomplete, 'files' => [], 'reason' => $exception->getMessage()];
        }
    }

    public static function checkCrc(string $data, string $crc): void
    {
        if (pack('V', crc32($data)) !== $crc) {
            throw new RuntimeException('header-crc-mismatch');
        }
    }

    private static function uint64(string $bytes): int
    {
        $parts = unpack('Vlow/Vhigh', $bytes);
        if ($parts === false || $parts['high'] > 0x7FFFFFFF) {
            throw new RuntimeException('integer-overflow');
        }

        return ($parts['high'] << 32) | $parts['low'];
    }

    /** @param Streams $streams */
    private function validateRanges(array $streams, int $limit): void
    {
        $remaining = $limit - $streams['offset'];
        if ($remaining < 0) {
            throw new RuntimeException('invalid-pack-offset');
        }
        foreach ($streams['packed'] as $size) {
            if ($size < 1 || $size > $remaining) {
                throw new RuntimeException('invalid-pack-size');
            }
            $remaining -= $size;
        }
    }

    private function decode(string $data, string $method, string $properties, int $size, float $deadline): string
    {
        if ($method === '00') {
            if (strlen($data) !== $size) {
                throw new RuntimeException('decoded-header-size');
            }

            return $data;
        }
        // Python is already part of the processing runtime. Its raw LZMA decoder
        // caps output and dictionary memory; no archive payload is extracted.
        $program = <<<'PY'
import sys,lzma,resource
resource.setrlimit(resource.RLIMIT_AS, (134217728,134217728))
method,props,size=sys.argv[1],bytes.fromhex(sys.argv[2]),int(sys.argv[3])
if method=='030101':
    p=props[0]; lc=p%9; p//=9; lp=p%5; pb=p//5
    dictionary=int.from_bytes(props[1:],'little')
    filters=[dict(id=lzma.FILTER_LZMA1,dict_size=dictionary,lc=lc,lp=lp,pb=pb)]
elif method=='21':
    p=props[0]; dictionary=(2|(p&1))<<(p//2+11)
    filters=[dict(id=lzma.FILTER_LZMA2,dict_size=dictionary)]
else:
    raise ValueError('unsupported-header-compression')
if dictionary>33554432:
    raise ValueError('header-dictionary-budget')
decoder=lzma.LZMADecompressor(format=lzma.FORMAT_RAW,filters=filters)
data=decoder.decompress(sys.stdin.buffer.read(1048577),max_length=size+1)
if len(data)!=size:
    raise ValueError('decoded-header-size')
sys.stdout.buffer.write(data)
PY;
        $process = new Process(['python3', '-c', $program, $method, bin2hex($properties), (string) $size]);
        $process->setInput($data)->setTimeout(max(0.001, min(5, $deadline - microtime(true))));
        $process->run();
        if (! $process->isSuccessful() || strlen($process->getOutput()) !== $size) {
            throw new RuntimeException('header-decompression-failed');
        }

        return $process->getOutput();
    }
}
