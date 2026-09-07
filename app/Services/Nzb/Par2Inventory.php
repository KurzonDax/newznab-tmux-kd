<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use RuntimeException;
use Throwable;
use XMLParser;

/**
 * Read-only, bounded evidence from the stored inventory, never from a sampled release_files table.
 */
final class Par2Inventory
{
    public const int MAX_BYTES = 16777216;

    public const int MAX_FILES = 10000;

    /**
     * @return array{par2_only: bool, reason: string, files: int, digest: string}
     */
    public function inspect(string $path, int $totalFiles, int $declaredFiles): array
    {
        $files = 0;
        $digest = hash_init('sha256');
        $stream = $path === '' ? false : @fopen($path, 'rb');
        if ($stream === false) {
            return ['par2_only' => false, 'reason' => 'unreadable', 'files' => 0, 'digest' => ''];
        }

        $parser = xml_parser_create_ns('UTF-8', '|');
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
        $depth = 0;
        $namespace = '';
        $counts = array_filter([$totalFiles, $declaredFiles], static fn (int $count): bool => $count > 0);
        $names = [];
        try {
            xml_set_element_handler($parser, function (XMLParser $parser, string $tag, array $attributes) use (&$depth, &$namespace, &$files, &$counts, &$names): void {
                $depth++;
                if ($depth > 16) {
                    throw new RuntimeException('depth_limit');
                }
                if ($depth === 1) {
                    if (! in_array($tag, ['nzb', 'http://www.newzbin.com/DTD/2003/nzb|nzb'], true)) {
                        throw new RuntimeException('invalid_root');
                    }
                    $namespace = str_contains($tag, '|') ? 'http://www.newzbin.com/DTD/2003/nzb|' : '';
                }
                $allowed = ['nzb', 'head', 'meta', 'file', 'groups', 'group', 'segments', 'segment'];
                if (! in_array($tag, array_map(static fn (string $name): string => $namespace.$name, $allowed), true)) {
                    throw new RuntimeException('unknown_element');
                }
                if ($tag !== $namespace.'file') {
                    return;
                }
                if ($depth !== 2 || ++$files > self::MAX_FILES) {
                    throw new RuntimeException('file_limit_or_structure');
                }
                $subject = $attributes['subject'] ?? '';
                if (strlen($subject) > 4096) {
                    throw new RuntimeException('subject_limit');
                }
                $filename = self::filename($subject);
                if ($filename === null) {
                    throw new RuntimeException('unknown_filename');
                }
                if (! str_ends_with(strtolower($filename), '.par2')) {
                    throw new RuntimeException('non_par2');
                }
                if (isset($names[$filename])) {
                    throw new RuntimeException('duplicate_filename');
                }
                $names[$filename] = true;
                if (preg_match_all('/\[\d+\/(\d+)\]/', $subject, $matches)) {
                    foreach ($matches[1] as $count) {
                        $counts[] = (int) $count;
                    }
                }
            }, static function (XMLParser $parser, string $tag) use (&$depth): void {
                $depth--;
            });
            xml_set_default_handler($parser, static function (XMLParser $parser, string $text): void {
                if (str_contains($text, '<!ENTITY') || str_contains($text, '<!ATTLIST')) {
                    throw new RuntimeException('doctype_or_entity');
                }
            });
            xml_set_external_entity_ref_handler($parser, static function (): bool {
                throw new RuntimeException('external_entity');
            });

            $inflate = inflate_init(ZLIB_ENCODING_GZIP);
            if ($inflate === false) {
                throw new RuntimeException('gzip_unavailable');
            }
            $bytes = 0;
            $compressedBytes = 0;
            $xmlTail = '';
            while (! feof($stream)) {
                $chunk = fread($stream, 4096);
                if ($chunk === false) {
                    throw new RuntimeException('read_failure');
                }
                if ($chunk === '') {
                    break;
                }
                $compressedBytes += strlen($chunk);
                if ($compressedBytes > self::MAX_BYTES || inflate_get_status($inflate) === ZLIB_STREAM_END) {
                    throw new RuntimeException('gzip_limit_or_trailing_data');
                }
                $xml = @inflate_add($inflate, $chunk);
                if ($xml === false || ($bytes += strlen($xml)) > self::MAX_BYTES) {
                    throw new RuntimeException('gzip_or_byte_limit');
                }
                $scan = $xmlTail.$xml;
                if (preg_match('/<!(?:ENTITY|ATTLIST|ELEMENT|NOTATION)\b/', $scan) === 1 || str_contains($xml, "\0")) {
                    throw new RuntimeException('doctype_or_unsupported_encoding');
                }
                $xmlTail = substr($scan, -16);
                hash_update($digest, $xml);
                if (xml_parse($parser, $xml, false) !== 1) {
                    throw new RuntimeException('malformed_xml');
                }
            }
            if (inflate_get_status($inflate) !== ZLIB_STREAM_END || inflate_get_read_len($inflate) !== $compressedBytes) {
                throw new RuntimeException('truncated_or_trailing_gzip');
            }
            if (xml_parse($parser, '', true) !== 1 || $files === 0 || $counts === []) {
                throw new RuntimeException('empty_or_unknown_inventory');
            }
            foreach ($counts as $count) {
                if ($count !== $files) {
                    throw new RuntimeException('conflicting_file_count');
                }
            }

            return ['par2_only' => true, 'reason' => 'complete_par2_inventory', 'files' => $files, 'digest' => hash_final($digest)];
        } catch (Throwable $exception) {
            return ['par2_only' => false, 'reason' => $exception->getMessage(), 'files' => $files, 'digest' => ''];
        } finally {
            fclose($stream);
        }
    }

    public static function filename(string $subject): ?string
    {
        if (preg_match_all('/"([^"\r\n]+)"/', $subject, $quoted) === 1 && substr_count($subject, '"') === 2) {
            return trim($quoted[1][0]);
        }
        if (preg_match('/^([^\s"<>]+\.[a-zA-Z0-9]+)(?:\s+yEnc(?:\s+\(\d+\/\d+\))?)?$/iD', $subject, $plain) === 1) {
            return $plain[1];
        }

        return null;
    }
}
