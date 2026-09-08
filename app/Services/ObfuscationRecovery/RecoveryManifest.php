<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Closure;
use Generator;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

final class RecoveryManifest
{
    private const int MAXIMUM_LINE = 16384;

    public function __construct(private readonly RecoveryArtifacts $artifacts) {}

    /** @param list<RecoveryFilePlan> $files
     * @param  Closure(RecoveryFilePlan): iterable<object>  $source
     */
    public function write(array $files, string $group, string $epoch, int $groupId, int $generation, Closure $source): RecoveryArtifact
    {
        if ($group === '' || strlen($group) > 255 || $epoch === '' || strlen($epoch) > 64 || $groupId < 1 || $generation < 1
            || count($files) < 2 || count($files) > 33) {
            throw new InvalidArgumentException('invalid_manifest_scope');
        }
        usort($files, static fn (RecoveryFilePlan $a, RecoveryFilePlan $b): int => strcmp($a->identity, $b->identity));
        $ids = array_column($files, 'identity');
        $parts = array_sum(array_column($files, 'totalParts'));
        if (count(array_unique($ids)) !== count($files) || $parts > 500000
            || count(array_filter($files, static fn (RecoveryFilePlan $file): bool => $file->role === RecoveryFileRole::Index)) !== 1) {
            throw new InvalidArgumentException('invalid_manifest_inventory');
        }

        return $this->artifacts->put($this->lines($files, $group, $epoch, $groupId, $generation, $source), $parts * self::MAXIMUM_LINE);
    }

    /** @return Generator<int,array<string,mixed>> */
    public function read(RecoveryArtifact $artifact): Generator
    {
        $pending = '';
        foreach ($this->artifacts->read($artifact) as $chunk) {
            $pending .= $chunk;
            while (($end = strpos($pending, "\n")) !== false) {
                if ($end >= self::MAXIMUM_LINE) {
                    throw new InvalidArgumentException('manifest_record_cap');
                }
                $line = substr($pending, 0, $end);
                $pending = substr($pending, $end + 1);
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($record) || ($record['version'] ?? null) !== 1) {
                    throw new InvalidArgumentException('invalid_manifest_record');
                }
                yield $record;
            }
            if (strlen($pending) >= self::MAXIMUM_LINE) {
                throw new InvalidArgumentException('manifest_record_cap');
            }
        }
        if ($pending !== '') {
            throw new InvalidArgumentException('truncated_manifest_record');
        }
    }

    /** @return array{records:list<array<string,mixed>>,offset:int,digest:string,start:int} */
    public function page(RecoveryArtifact $artifact, int $offset): array
    {
        $records = [];
        $pending = '';
        $next = $offset;
        foreach ($this->artifacts->range($artifact, $offset, 8388608) as $chunk) {
            $pending .= $chunk;
            while (($end = strpos($pending, "\n")) !== false) {
                if ($end >= self::MAXIMUM_LINE) {
                    throw new InvalidArgumentException('manifest_record_cap');
                }
                $record = json_decode(substr($pending, 0, $end), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($record) || ($record['version'] ?? null) !== 1) {
                    throw new InvalidArgumentException('invalid_manifest_record');
                }
                $records[] = $record;
                $next += $end + 1;
                $pending = substr($pending, $end + 1);
                if (count($records) === 500) {
                    break 2;
                }
            }
            if (strlen($pending) >= self::MAXIMUM_LINE) {
                throw new InvalidArgumentException('manifest_record_cap');
            }
        }
        if (count($records) < 500 && ($pending !== '' || $next !== $artifact->bytes)) {
            throw new InvalidArgumentException('truncated_manifest_record');
        }

        return ['records' => $records, 'offset' => $next, 'digest' => $artifact->digest, 'start' => $offset];
    }

    /** @param list<RecoveryFilePlan> $files
     * @param  Closure(RecoveryFilePlan): iterable<object>  $source
     * @return Generator<int,string>
     */
    private function lines(array $files, string $group, string $epoch, int $groupId, int $generation, Closure $source): Generator
    {
        $temporary = tmpfile();
        if ($temporary === false) {
            throw new RuntimeException('manifest_workspace_unavailable');
        }
        $database = null;
        $insert = null;
        try {
            $database = new PDO('sqlite:'.stream_get_meta_data($temporary)['uri'], options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $database->exec('PRAGMA journal_mode=MEMORY');
            $database->exec('PRAGMA cache_size=-2048');
            $database->exec('CREATE TABLE seen (message_id TEXT COLLATE BINARY PRIMARY KEY, article_number INTEGER NOT NULL UNIQUE) WITHOUT ROWID');
            $insert = $database->prepare('INSERT INTO seen (message_id, article_number) VALUES (?, ?)');
            $identity = new RecoveryIdentity;
            foreach ($files as $file) {
                $ordinal = 0;
                $previous = null;
                foreach ($source($file) as $row) {
                    $ordinal++;
                    if ($ordinal > $file->totalParts || $row->metadata_conflict || $row->source_epoch !== $epoch
                        || (int) $row->groups_id !== $groupId || (int) $row->capture_generation !== $generation
                        || (int) $row->article_number < 1 || (int) $row->advertised_bytes < 0) {
                        throw new InvalidArgumentException('manifest_membership_conflict');
                    }
                    $messageId = $identity->messageId($row->message_id);
                    if ($messageId !== $row->message_id || $identity->messageId($row->source_message_id) !== $messageId
                        || ($file->role === RecoveryFileRole::Index && $file->identity !== $messageId)) {
                        throw new InvalidArgumentException('manifest_identity_conflict');
                    }
                    if ($previous !== null && ((int) $row->embedded_timestamp_ms < (int) $previous->embedded_timestamp_ms
                        || ((int) $row->embedded_timestamp_ms === (int) $previous->embedded_timestamp_ms && strcmp($messageId, $previous->message_id) <= 0))) {
                        throw new InvalidArgumentException('unsorted_manifest_membership');
                    }
                    try {
                        $insert->execute([$messageId, (int) $row->article_number]);
                    } catch (PDOException $exception) {
                        if ($exception->getCode() === '23000') {
                            throw new InvalidArgumentException('overlapping_manifest_membership');
                        }
                        throw new RuntimeException('manifest_workspace_failed');
                    }
                    $record = ['version' => 1, 'file' => $file->identity, 'role' => $file->role->value, 'ordinal' => $ordinal,
                        'group' => $group, 'group_id' => $groupId, 'source_epoch' => $epoch, 'capture_generation' => $generation,
                        'message_id' => $messageId, 'source_message_id' => base64_encode($row->source_message_id),
                        'article_number' => (int) $row->article_number, 'advertised_bytes' => (int) $row->advertised_bytes,
                        'embedded_timestamp_ms' => (int) $row->embedded_timestamp_ms, 'raw_subject' => base64_encode($row->raw_subject),
                        'poster_identity' => base64_encode($row->poster_identity), 'source_date' => $row->source_date,
                        'postdate' => $row->postdate, 'xref' => base64_encode($row->xref ?? '')];
                    $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                    if (strlen($line) > self::MAXIMUM_LINE) {
                        throw new InvalidArgumentException('manifest_record_cap');
                    }
                    yield $line;
                    $previous = $row;
                }
                if ($ordinal !== $file->totalParts) {
                    throw new InvalidArgumentException('incomplete_manifest_membership');
                }
            }
        } finally {
            $insert = null;
            $database = null;
            fclose($temporary);
        }
    }
}
