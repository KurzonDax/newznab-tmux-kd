<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use UnexpectedValueException;

final readonly class SourceEnvelope
{
    public function __construct(public string $poster, public int $date, public string $messageId) {}

    public static function validate(string $headers, PostingFile $file, int $partNumber = 1): self
    {
        $headers = preg_replace('/\r?\n[ \t]+/', ' ', $headers) ?? '';
        $fields = [];
        foreach (preg_split('/\r?\n/', $headers) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            if (! preg_match('/^([!-9;-~]+):[ \t]*(.*)$/D', $line, $matches)) {
                throw new UnexpectedValueException('malformed_header');
            }
            $key = strtolower($matches[1]);
            if ($key !== 'x-trace' && isset($fields[$key]) && $fields[$key] !== trim($matches[2])) {
                throw new UnexpectedValueException('conflicting_header');
            }
            $fields[$key] = trim($matches[2]);
        }
        foreach (['from', 'subject', 'date', 'message-id'] as $key) {
            if (($fields[$key] ?? '') === '') {
                throw new UnexpectedValueException('missing_header_'.$key);
            }
        }
        $subject = iconv_mime_decode($fields['subject'], ICONV_MIME_DECODE_STRICT, 'UTF-8');
        $poster = iconv_mime_decode($fields['from'], ICONV_MIME_DECODE_STRICT, 'UTF-8');
        if ($subject !== false) {
            $subject = preg_replace('/(\(\d+\/\d+\)) \d+$/D', '$1', $subject) ?? '';
        }
        $date = strtotime($fields['date']);
        $article = null;
        foreach ($file->segments as $segment) {
            if ($segment['number'] === $partNumber) {
                $article = '<'.trim($segment['messageid'], '<>').'>';
                break;
            }
        }
        if ($subject === false || $poster === false || $date === false
            || ! preg_match('/\((\d+)\/(\d+)\)$/D', $subject, $counter)
            || $partNumber < 1 || $partNumber > $file->declaredParts
            || (int) $counter[1] !== $partNumber || (int) $counter[2] !== $file->declaredParts
            || $fields['message-id'] !== $article
            || PostingFile::subject($subject) !== PostingFile::subject($file->subject)
        ) {
            throw new UnexpectedValueException('contradictory_source_envelope');
        }

        return new self($poster, $date, $fields['message-id']);
    }
}
