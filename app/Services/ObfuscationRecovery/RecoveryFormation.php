<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Generator;
use InvalidArgumentException;

final class RecoveryFormation
{
    /** @param iterable<object> $rows Ordered by advertised total, timestamp, bytewise Message-ID.
     * @return Generator<int, array<string,mixed>>
     */
    public function mediaRuns(iterable $rows): Generator
    {
        yield from $this->runs($rows, 'advertised_total', true);
    }

    /** @param iterable<object> $rows Ordered by exact subject/poster digest, timestamp, bytewise Message-ID.
     * @return Generator<int, array<string,mixed>>
     */
    public function rarRuns(iterable $rows): Generator
    {
        yield from $this->runs($rows, 'key_digest', false);
    }

    /** @param iterable<array<string,mixed>> $runs Ordered by start timestamp.
     * @return Generator<int, list<array<string,mixed>>>
     */
    public function mediaBundles(iterable $runs): Generator
    {
        $bundle = [];
        $end = $previousStart = null;
        foreach ($runs as $run) {
            if ($previousStart !== null && $run['start_ms'] < $previousStart) {
                throw new InvalidArgumentException('unsorted_recovery_runs');
            }
            if ($bundle !== [] && $run['start_ms'] > $end + 30000) {
                yield $bundle;
                $bundle = [];
                $end = null;
            }
            $bundle[] = $run;
            if (count($bundle) > 256) {
                throw new InvalidArgumentException('candidate_count_cap');
            }
            $end = max($end ?? 0, $run['end_ms']);
            if ($end - $bundle[0]['start_ms'] > 21600000) {
                throw new InvalidArgumentException('candidate_span_cap');
            }
            $previousStart = $run['start_ms'];
        }
        if ($bundle !== []) {
            yield $bundle;
        }
    }

    /** @param iterable<object> $rows
     * @return Generator<int, array<string,mixed>>
     */
    private function runs(iterable $rows, string $partitionColumn, bool $media): Generator
    {
        $run = $previous = $digest = null;
        $identity = new RecoveryIdentity;
        foreach ($rows as $row) {
            $partition = $media ? (int) $row->{$partitionColumn} : (string) $row->{$partitionColumn};
            $timestamp = (int) $row->embedded_timestamp_ms;
            if ($previous !== null) {
                $order = $media ? $partition <=> (int) $previous->{$partitionColumn} : strcmp($partition, $previous->{$partitionColumn});
                if ($order < 0 || ($order === 0 && ($timestamp < (int) $previous->embedded_timestamp_ms
                    || ($timestamp === (int) $previous->embedded_timestamp_ms && strcmp($row->message_id, $previous->message_id) <= 0)))) {
                    throw new InvalidArgumentException('unsorted_recovery_headers');
                }
            }
            if ($run !== null && ($partition !== $run['partition'] || $timestamp - $run['end_ms'] > 3000)) {
                $run['membership_digest'] = hash_final($digest);
                yield $this->classify($run, $media);
                $run = null;
            }
            if ($run === null) {
                $digest = hash_init('sha256');
                $run = ['partition' => $partition, 'advertised_total' => $media ? $partition : null,
                    'first_id' => (int) $row->id, 'first_message_id' => $row->message_id, 'start_ms' => $timestamp,
                    'observed_count' => 0, 'metadata_conflict' => false, 'first_article' => (int) $row->article_number,
                    'last_article' => (int) $row->article_number, 'first_postdate' => $row->postdate,
                    'last_postdate' => $row->postdate, 'membership_changed_at' => $row->first_observed_at,
                    'oldest_observed_at' => $row->first_observed_at];
            }
            $run['last_id'] = (int) $row->id;
            $run['last_message_id'] = $row->message_id;
            $run['end_ms'] = $timestamp;
            $run['observed_count']++;
            $run['metadata_conflict'] = $run['metadata_conflict'] || (bool) $row->metadata_conflict;
            $run['first_article'] = min($run['first_article'], (int) $row->article_number);
            $run['last_article'] = max($run['last_article'], (int) $row->article_number);
            $run['first_postdate'] = min($run['first_postdate'], $row->postdate);
            $run['last_postdate'] = max($run['last_postdate'], $row->postdate);
            $run['oldest_observed_at'] = min($run['oldest_observed_at'], $row->first_observed_at);
            $run['membership_changed_at'] = max($run['membership_changed_at'], $row->first_observed_at);
            hash_update($digest, $identity->digest([$row->message_id, (string) $timestamp,
                (string) $row->article_number, (string) $row->advertised_bytes, (string) $row->metadata_conflict]));
            $previous = $row;
        }
        if ($run !== null) {
            $run['membership_digest'] = hash_final($digest);
            yield $this->classify($run, $media);
        }
    }

    /** @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function classify(array $run, bool $media): array
    {
        $run['state'] = match (true) {
            $run['metadata_conflict'] => 'metadata_conflict',
            $run['observed_count'] > 500000 => 'candidate_count_cap',
            $run['end_ms'] - $run['start_ms'] > 21600000 => 'candidate_span_cap',
            ! $media => 'candidate',
            $run['observed_count'] < $run['advertised_total'] => 'short',
            $run['observed_count'] > $run['advertised_total'] => 'overfull',
            default => 'count_exact',
        };

        return $run;
    }
}
