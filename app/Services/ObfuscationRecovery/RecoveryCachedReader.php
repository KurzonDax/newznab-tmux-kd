<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final class RecoveryCachedReader
{
    public function __construct(private readonly RecoveryManifest $manifest, private readonly RecoveryEvidence $evidence) {}

    public function read(RecoveryArtifact $manifest, RecoveryFilePlan $file, int $maximumBytes = 2097152): RecoveryCachedPrefix
    {
        $records = [];
        $count = 0;
        foreach ($this->manifest->read($manifest) as $record) {
            if (($record['file'] ?? null) !== $file->identity) {
                continue;
            }
            $count++;
            if (($record['role'] ?? null) !== $file->role->value || ($record['ordinal'] ?? null) !== $count) {
                throw new InvalidArgumentException('cached_manifest_membership_conflict');
            }
            if ($count <= 16) {
                $records[] = $record;
            }
        }
        if ($count === 0) {
            throw new InvalidArgumentException('unknown_manifest_file');
        }
        if ($count !== $file->totalParts) {
            throw new InvalidArgumentException('incomplete_cached_manifest');
        }

        return $this->readRecords($records, $file, $maximumBytes);
    }

    /** @param list<array<string,mixed>> $records */
    public function readRecords(array $records, RecoveryFilePlan $file, int $maximumBytes = 2097152): RecoveryCachedPrefix
    {
        if ($maximumBytes < 1 || $maximumBytes > 4194304 || count($records) !== min(16, $file->totalParts)) {
            throw new InvalidArgumentException('invalid_cached_reader_limit');
        }
        $ranges = $parts = [];
        $filename = null;
        foreach ($records as $i => $record) {
            if (($record['file'] ?? null) !== $file->identity || ($record['role'] ?? null) !== $file->role->value
                || ($record['ordinal'] ?? null) !== $i + 1) {
                throw new InvalidArgumentException('cached_manifest_membership_conflict');
            }
            $article = $this->evidence->get($record['message_id'], true);
            if ($article === null) {
                continue;
            }
            $expectedBegin = $file->role === RecoveryFileRole::Index ? 1 : ($article->part - 1) * RecoveryFilePlan::CHUNK_BYTES + 1;
            $expectedEnd = $file->role === RecoveryFileRole::Index ? $file->decodedBytes : min($article->part * RecoveryFilePlan::CHUNK_BYTES, $file->decodedBytes);
            if ($article->fileSize !== $file->decodedBytes || $article->total !== $file->totalParts
                || $article->begin !== $expectedBegin || $article->end !== $expectedEnd || isset($parts[$article->part])
                || ($filename !== null && $filename !== $article->filename)
                || ($article->complete && $article->crcPresent && ! $article->crcVerified)) {
                throw new InvalidArgumentException('cached_file_declaration_conflict');
            }
            $filename = $article->filename;
            $parts[$article->part] = true;
            if ($article->data === '' || $article->begin > $maximumBytes) {
                continue;
            }
            $ranges[] = ['begin' => $article->begin, 'data' => substr($article->data, 0, $maximumBytes - $article->begin + 1),
                'message_id' => $record['message_id']];
        }
        usort($ranges, static fn (array $a, array $b): int => $a['begin'] <=> $b['begin']);
        $data = '';
        $ids = [];
        foreach ($ranges as $range) {
            if ($range['begin'] !== strlen($data) + 1) {
                break;
            }
            $data .= $range['data'];
            $ids[] = $range['message_id'];
        }

        return new RecoveryCachedPrefix($data, strlen($data) === $file->decodedBytes, $ids);
    }
}
