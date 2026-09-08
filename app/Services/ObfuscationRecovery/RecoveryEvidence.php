<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecoveryEvidence
{
    public function __construct(private readonly RecoveryArtifacts $artifacts, private readonly RecoveryIdentity $identity) {}

    public function get(string $messageId, bool $prefixOnly = false, int $minimumDecoded = 0): ?RecoveryArticle
    {
        if ($minimumDecoded < 0 || $minimumDecoded > 1048576) {
            throw new InvalidArgumentException('invalid_cached_evidence_requirement');
        }
        $messageId = $this->identity->messageId($messageId);

        return DB::transaction(function () use ($messageId, $prefixOnly, $minimumDecoded): ?RecoveryArticle {
            $row = DB::table('obfuscation_recovery_evidence')->where('message_id_digest', hash('sha256', $messageId))->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }
            if ($row->state === 'evicted') {
                return null;
            }
            if ($row->state !== 'valid' || $row->message_id !== $messageId) {
                throw new InvalidArgumentException('conflicting_article_evidence');
            }
            $entry = $row->full_evidence ?? ($prefixOnly ? $row->prefix_evidence : null);

            $article = $entry === null ? null : $this->read(json_decode($entry, true, flags: JSON_THROW_ON_ERROR));

            return $article !== null && strlen($article->data) >= $minimumDecoded ? $article : null;
        }, 1);
    }

    public function store(string $messageId, RecoveryArticle $article, ?int $attemptId = null): RecoveryArtifact
    {
        $messageId = $this->identity->messageId($messageId);
        $artifact = $this->artifacts->put([$article->data], 1048576);
        $metadata = $article->metadata();
        $entry = ['artifact' => $artifact->digest, 'bytes' => $artifact->bytes, 'metadata' => $metadata,
            'metadata_digest' => hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR)), 'attempt_id' => $attemptId];
        $valid = DB::transaction(function () use ($messageId, $article, $entry): bool {
            $digest = hash('sha256', $messageId);
            DB::table('obfuscation_recovery_evidence')->upsert([
                'message_id_digest' => $digest, 'message_id' => $messageId, 'created_at' => now(), 'updated_at' => now(),
            ], ['message_id_digest'], ['message_id_digest']);
            $row = DB::table('obfuscation_recovery_evidence')->where('message_id_digest', $digest)->lockForUpdate()->first();
            $conflict = ! in_array($row->state, ['valid', 'evicted'], true) || $row->message_id !== $messageId;
            $fingerprints = json_decode($row->fingerprints ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            foreach ($fingerprints as $fingerprint) {
                foreach (['filename', 'file_size', 'part', 'total', 'begin', 'end'] as $property) {
                    $conflict = $conflict || $fingerprint['metadata'][$property] !== $entry['metadata'][$property];
                }
                if (strlen($article->data) >= $fingerprint['bytes']) {
                    $conflict = $conflict || ! hash_equals($fingerprint['digest'], hash('sha256', substr($article->data, 0, $fingerprint['bytes'])));
                } elseif (strlen($article->data) >= $fingerprint['prefix_bytes']) {
                    $conflict = $conflict || ! hash_equals($fingerprint['prefix_digest'], hash('sha256', substr($article->data, 0, $fingerprint['prefix_bytes'])));
                } elseif ($row->state === 'evicted' && $article->data !== '') {
                    throw new InvalidArgumentException('cached_evidence_revalidation_incomplete');
                }
            }
            foreach (['prefix_evidence', 'full_evidence'] as $column) {
                if ($row->{$column} === null) {
                    continue;
                }
                $existing = $this->read(json_decode($row->{$column}, true, flags: JSON_THROW_ON_ERROR));
                foreach (['filename', 'fileSize', 'part', 'total', 'begin', 'end'] as $property) {
                    $conflict = $conflict || $existing->{$property} !== $article->{$property};
                }
                $shorter = min(strlen($existing->data), strlen($article->data));
                $conflict = $conflict || substr($existing->data, 0, $shorter) !== substr($article->data, 0, $shorter);
                if ($existing->complete && $article->complete) {
                    $conflict = $conflict || $existing->data !== $article->data || $existing->metadata() !== $article->metadata();
                }
            }
            if ($conflict) {
                DB::table('obfuscation_recovery_evidence')->where('message_id_digest', $digest)->update(['state' => 'conflict', 'updated_at' => now()]);

                return false;
            }
            $column = $article->complete ? 'full_evidence' : 'prefix_evidence';
            $current = $row->{$column} === null ? null : json_decode($row->{$column}, true, flags: JSON_THROW_ON_ERROR);
            if ($current === null || (! $article->complete && $current['bytes'] < strlen($article->data))) {
                (new RecoveryReferences)->retain('evidence', $digest, 'artifact', $entry['artifact']);
                if (! isset($fingerprints[$column]) || $fingerprints[$column]['bytes'] < $entry['bytes']) {
                    $fingerprints[$column] = ['metadata' => $entry['metadata'], 'bytes' => $entry['bytes'], 'digest' => $entry['artifact'],
                        'prefix_bytes' => min(16384, $entry['bytes']), 'prefix_digest' => hash('sha256', substr($article->data, 0, 16384))];
                }
                DB::table('obfuscation_recovery_evidence')->where('message_id_digest', $digest)
                    ->update([$column => json_encode($entry, JSON_THROW_ON_ERROR), 'state' => 'valid',
                        'fingerprints' => json_encode($fingerprints, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }

            return true;
        }, 1);
        if (! $valid) {
            throw new InvalidArgumentException('conflicting_article_evidence');
        }

        return $artifact;
    }

    /** @param array<string,mixed> $entry */
    private function read(array $entry): RecoveryArticle
    {
        if (! isset($entry['metadata'], $entry['metadata_digest'], $entry['artifact'], $entry['bytes'])
            || $entry['bytes'] < 0 || $entry['bytes'] > 1048576
            || ! hash_equals($entry['metadata_digest'], hash('sha256', json_encode($entry['metadata'], JSON_THROW_ON_ERROR)))) {
            throw new InvalidArgumentException('invalid_cached_article_metadata');
        }
        $data = '';
        foreach ($this->artifacts->read(new RecoveryArtifact($entry['artifact'], $entry['bytes'])) as $chunk) {
            $data .= $chunk;
        }

        return RecoveryArticle::fromMetadata($entry['metadata'], $data);
    }
}
