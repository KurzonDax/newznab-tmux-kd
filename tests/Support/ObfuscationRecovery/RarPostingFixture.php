<?php

declare(strict_types=1);

namespace Tests\Support\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryArticle;
use DateTimeImmutable;

final class RarPostingFixture
{
    /** @return array{headers:list<array<string,mixed>>,cache:array<string,RecoveryArticle>,index_id:string} */
    public static function make(bool $singleArticleFinalVolume = false): array
    {
        $time = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $timestamp = $time->getTimestamp() * 1000;
        $volumes = SyntheticPosting::rar([1533600, 1533600, 1533600, $singleArticleFinalVolume ? 100000 : 816800])['volumes'];
        $headers = $cache = $files = [];
        $ordinal = 0;
        foreach ($volumes as $i => $volume) {
            $files[sprintf('Fixture.part%02d.rar', $i + 1)] = $volume;
            $size = strlen($volume);
            $total = intdiv($size - 1, SyntheticPosting::ARTICLE_BYTES) + 1;
            foreach (range(1, $total) as $part) {
                $id = 'volume'.$i.'-part'.$part.'-'.($timestamp + $ordinal++).'@nyuu';
                $begin = ($part - 1) * SyntheticPosting::ARTICLE_BYTES + 1;
                $end = min($part * SyntheticPosting::ARTICLE_BYTES, $size);
                $headers[] = self::header($id, $time, $end - $begin + 101);
                if ($part === 1 || $part === $total) {
                    $cache[$id] = new RecoveryArticle('opaque', $size, $part, $total, $begin, $end,
                        $part === 1 ? substr($volume, 0, 16384) : '', false, false, false, null);
                }
            }
        }
        $index = SyntheticPosting::par2($files);
        $indexId = 'index-'.($timestamp + $ordinal).'@nyuu';
        $headers[] = self::header($indexId, $time, strlen($index) + 100);
        $cache[$indexId] = new RecoveryArticle('recovery.par2', strlen($index), 1, 1, 1, strlen($index), $index, true, false, false, null);
        array_unshift($headers, self::header('left@local', $time->modify('-130 minutes'), 100));
        $headers[] = self::header('right@local', $time->modify('+130 minutes'), 100);
        foreach ($headers as $i => &$header) {
            $header['Number'] = (string) (4000000001 + $i);
        }
        unset($header);

        return ['headers' => $headers, 'cache' => $cache, 'index_id' => $indexId];
    }

    /** @return array<string,mixed> */
    private static function header(string $id, DateTimeImmutable $date, int $bytes): array
    {
        return ['Subject' => '0123456789abcdefghij', 'From' => 'fixture@example.invalid', 'Date' => $date->format('D, d M Y H:i:s O'),
            'Message-ID' => '<'.$id.'>', 'Bytes' => $bytes, 'Xref' => ''];
    }
}
