<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final class RecoveryHeaderSelector
{
    public function __construct(private readonly RecoveryIdentity $identity) {}

    /**
     * @param  array<string, mixed>  $header
     * @return array<string, mixed>|null
     */
    public function select(array $header, RecoveryAlgorithm $algorithm, RecoveryScanContext $context): ?array
    {
        $subject = $header['Subject'] ?? null;
        $matches = $header['matches'] ?? [];
        if (! is_string($subject)) {
            return null;
        }
        if ($algorithm === RecoveryAlgorithm::Media) {
            if (($matches[2] ?? null) !== '1' || ! is_string($matches[1] ?? null)
                || preg_match('/^\[[A-Za-z0-9]{1,3}\] - [A-Za-z0-9]{30,50} yEnc$/D', $matches[1]) !== 1) {
                return null;
            }
            $total = $this->integer($matches[3] ?? null, 1, 100000);
        } else {
            if (preg_match('/^[a-z0-9]{20}$/D', $subject) !== 1) {
                return null;
            }
            $total = null;
        }
        foreach (['Subject' => 4096, 'From' => 2048, 'Message-ID' => 1024, 'Date' => 128, 'Xref' => 4096] as $key => $limit) {
            if (! is_string($header[$key] ?? '') || strlen($header[$key] ?? '') > $limit) {
                throw new InvalidArgumentException('invalid_header_fields');
            }
        }
        $id = $this->identity->messageId($header['Message-ID'] ?? '');
        if (preg_match('/-([0-9]{13})@nyuu$/D', $id, $timestamp) !== 1) {
            return null;
        }
        if (($header['From'] ?? '') === '') {
            throw new InvalidArgumentException('invalid_poster_identity');
        }
        $postdate = RecoveryCoverage::sourceDate($header['Date'] ?? null);
        if ($postdate === null) {
            throw new InvalidArgumentException('invalid_source_date');
        }
        $poster = $header['From'];

        return [
            'source_epoch' => $context->sourceEpoch, 'groups_id' => $context->groupId,
            'capture_generation' => $context->generation, 'message_id' => $id,
            'message_id_digest' => hash('sha256', $id), 'source_message_id' => $header['Message-ID'],
            'article_number' => $this->integer($header['Number'] ?? null, $context->first, $context->last),
            'raw_subject' => $subject, 'parsed_name' => $algorithm === RecoveryAlgorithm::Media ? $matches[1] : null,
            'poster_identity' => $poster, 'source_date' => $header['Date'], 'postdate' => $postdate,
            'xref' => $header['Xref'] ?? '', 'advertised_bytes' => $this->integer($header['Bytes'] ?? null, $algorithm === RecoveryAlgorithm::Rar ? 1 : 0, PHP_INT_MAX),
            'original_part' => $algorithm === RecoveryAlgorithm::Media ? 1 : null, 'advertised_total' => $total,
            'embedded_timestamp_ms' => (int) $timestamp[1], 'profile' => $algorithm->value,
            'key_digest' => $this->identity->digest([$subject, $poster]),
        ];
    }

    private function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException('invalid_header_number');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
        if ($integer === false) {
            throw new InvalidArgumentException('invalid_header_number');
        }

        return $integer;
    }
}
