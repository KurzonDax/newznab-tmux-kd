<?php

declare(strict_types=1);

namespace Tests\Support\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryArticle;
use DateTimeImmutable;

final class MediaPostingFixture
{
    /** @param array<string,string> $files
     * @return array{headers:list<array<string,mixed>>,cache:array<string,RecoveryArticle>,messages:array<string,list<string>>,index_id:string}
     */
    public static function make(array $files, string $date = '2026-01-01T00:00:00Z'): array
    {
        $time = new DateTimeImmutable($date);
        $base = $time->getTimestamp() * 1000;
        $headers = $cache = $messages = [];
        $fileOrdinal = 0;
        foreach ($files as $name => $bytes) {
            $size = strlen($bytes);
            $total = intdiv($size - 1, SyntheticPosting::ARTICLE_BYTES) + 1;
            $parts = range(1, $total);
            if ($total >= 3) {
                [$parts[1], $parts[2]] = [$parts[2], $parts[1]];
            }
            foreach ($parts as $ordinal => $part) {
                $timestamp = $base + 100 * $fileOrdinal + $ordinal;
                $id = 'f'.$fileOrdinal.'-p'.$part.'-'.$timestamp.'@nyuu';
                $headers[] = self::header($id, '[f] - '.str_repeat(chr(65 + $fileOrdinal % 26), 32).' yEnc (1/'.$total.')', $time, 740000);
                $messages[$name][] = $id;
                if ($part === 1) {
                    $cache[$id] = new RecoveryArticle('opaque', $size, 1, $total, 1, min(SyntheticPosting::ARTICLE_BYTES, $size),
                        substr($bytes, 0, 16384), $size <= 16384, false, false, null);
                }
            }
            $fileOrdinal++;
        }
        $index = SyntheticPosting::par2($files);
        $indexId = 'index-'.($base + 100 * $fileOrdinal + 1000).'@nyuu';
        $headers[] = self::header($indexId, '[i] - '.str_repeat('Z', 32).' yEnc (1/1)', $time, strlen($index) + 100);
        $cache[$indexId] = new RecoveryArticle('recovery.par2', strlen($index), 1, 1, 1, strlen($index), $index, true, false, false, null);
        array_unshift($headers, self::header('left@local', 'Boundary marker', $time->modify('-130 minutes'), 100));
        $headers[] = self::header('right@local', 'Boundary marker', $time->modify('+130 minutes'), 100);
        foreach ($headers as $ordinal => &$header) {
            $header['Number'] = (string) (4000000001 + $ordinal);
        }
        unset($header);

        return ['headers' => $headers, 'cache' => $cache, 'messages' => $messages, 'index_id' => $indexId];
    }

    /** @return array<string,mixed> */
    private static function header(string $id, string $subject, DateTimeImmutable $date, int $bytes): array
    {
        return ['Subject' => $subject, 'From' => 'fixture@example.invalid', 'Date' => $date->format('D, d M Y H:i:s O'),
            'Message-ID' => '<'.$id.'>', 'Bytes' => $bytes, 'Xref' => ''];
    }
}
