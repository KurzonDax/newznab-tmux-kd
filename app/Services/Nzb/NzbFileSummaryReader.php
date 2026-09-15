<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use InflateContext;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use XMLParser;

final class NzbFileSummaryReader
{
    /** @return array{files: list<array{index: int, title: string, size: int}>, total: int, page: int, per: int, last_page: int} */
    public function page(string $path, int $page = 1, int $per = 100): array
    {
        if ($page < 1 || ! in_array($per, [24, 48, 100], true) || $page > intdiv(PHP_INT_MAX, $per)) {
            throw new InvalidArgumentException('Invalid file summary page.');
        }
        $stream = is_file($path) ? @fopen($path, 'rb') : false;
        if ($stream === false) {
            throw new NotFoundHttpException('NZB file not found.');
        }
        $parser = xml_parser_create_ns('UTF-8', '|');
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
        $files = [];
        $stack = [];
        $namespace = '';
        $total = 0;
        $title = '';
        $size = 0;
        $offset = ($page - 1) * $per;
        try {
            xml_set_element_handler($parser, static function (XMLParser $parser, string $tag, array $attributes) use (&$stack, &$namespace, &$total, &$title, &$size): void {
                $stack[] = $tag;
                if (count($stack) > 32) {
                    throw new RuntimeException('NZB nesting exceeds 32 levels.');
                }
                if (count($stack) === 1) {
                    if (! in_array($tag, ['nzb', 'http://www.newzbin.com/DTD/2003/nzb|nzb'], true)) {
                        throw new RuntimeException('Invalid NZB root.');
                    }
                    $namespace = str_contains($tag, '|') ? 'http://www.newzbin.com/DTD/2003/nzb|' : '';
                }
                if (count($stack) === 2 && $tag === $namespace.'file') {
                    $title = $attributes['subject'] ?? '';
                    $size = 0;
                    $total++;
                }
                if ($stack === [$namespace.'nzb', $namespace.'file', $namespace.'segments', $namespace.'segment']) {
                    $bytes = $attributes['bytes'] ?? '0';
                    if (! ctype_digit($bytes) || strlen(ltrim($bytes, '0')) > strlen((string) PHP_INT_MAX) || (strlen(ltrim($bytes, '0')) === strlen((string) PHP_INT_MAX) && strcmp(ltrim($bytes, '0'), (string) PHP_INT_MAX) > 0) || (int) $bytes > PHP_INT_MAX - $size) {
                        throw new RuntimeException('Invalid segment size.');
                    }
                    $size += (int) $bytes;
                }
            }, static function (XMLParser $parser, string $tag) use (&$stack, &$files, &$total, &$title, &$size, &$namespace, $offset, $per): void {
                /** @var int $total Updated by the SAX start handler. */
                if (count($stack) === 2 && $tag === $namespace.'file' && $total > $offset && $total - $offset <= $per) {
                    $files[] = ['index' => $total - 1, 'title' => $title, 'size' => $size];
                }
                array_pop($stack);
            });
            xml_set_external_entity_ref_handler($parser, static fn (): bool => false);
            $inflate = inflate_init(ZLIB_ENCODING_GZIP);
            if ($inflate === false) {
                throw new RuntimeException('Unable to inflate NZB.');
            }
            $guard = new NzbSummaryXmlGuard;
            $compressedBytes = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 512);
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read NZB.');
                }
                if ($chunk === '') {
                    break;
                }
                $compressedBytes += strlen($chunk);
                if (inflate_get_status($inflate) === ZLIB_STREAM_END) {
                    throw new RuntimeException('Trailing gzip data.');
                }
                $xml = $this->inflateChunk($inflate, $chunk);
                $xml = str_replace("\x0F", '', $xml);
                $guard->accept($xml);
                if (xml_parse($parser, $xml, false) !== 1) {
                    throw new RuntimeException('Invalid NZB XML.');
                }
            }
            if (inflate_get_status($inflate) !== ZLIB_STREAM_END || inflate_get_read_len($inflate) !== $compressedBytes || xml_parse($parser, '', true) !== 1) {
                throw new RuntimeException('Incomplete NZB.');
            }

            return ['files' => $files, 'total' => $total, 'page' => $page, 'per' => $per, 'last_page' => max(1, (int) ceil($total / $per))];
        } finally {
            fclose($stream);
            unset($parser, $inflate);
        }
    }

    private function inflateChunk(InflateContext $inflate, string $chunk): string
    {
        set_error_handler(static function (): never {
            throw new RuntimeException('Invalid gzip data.');
        }, E_WARNING);
        try {
            $xml = inflate_add($inflate, $chunk);
            if ($xml === false) {
                throw new RuntimeException('Invalid gzip data.');
            }

            return $xml;
        } finally {
            restore_error_handler();
        }
    }
}
