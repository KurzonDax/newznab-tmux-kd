<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Enums\HeaderScanDirection;
use App\Support\SqlError;

/**
 * Orchestrates the header storage process.
 *
 * This service coordinates the CollectionHandler, BinaryHandler, PartHandler,
 * and HeaderStorageTransaction to store parsed headers into the database.
 */
final class HeaderStorageService
{
    /** Bounded attempts per chunk: transient locks and silent id-resolution races both use it. */
    private const int CHUNK_RETRY_MAX = 5;

    private CollectionHandler $collectionHandler;

    private BinaryHandler $binaryHandler;

    private PartHandler $partHandler;

    private BinariesConfig $config;

    private HeaderStorageReport $report;

    /** @var list<int|string> Article numbers the current chunk attempt could not store */
    private array $attemptFailedNumbers = [];

    /** @var array<int, HeaderFailureReason> Why each header of the current attempt could not be placed */
    private array $attemptFailures = [];

    private ?\Throwable $lastStorageException = null;

    public function __construct(
        ?CollectionHandler $collectionHandler = null,
        ?BinaryHandler $binaryHandler = null,
        ?PartHandler $partHandler = null,
        ?BinariesConfig $config = null
    ) {
        $this->config = $config ?? BinariesConfig::fromSettings();
        $this->collectionHandler = $collectionHandler ?? new CollectionHandler(sqlChunkSize: $this->config->sqlChunkSize);
        $this->binaryHandler = $binaryHandler ?? new BinaryHandler($this->config->sqlChunkSize);
        $this->partHandler = $partHandler ?? new PartHandler(
            $this->config->sqlChunkSize,
            true
        );
        $this->report = HeaderStorageReport::empty();
    }

    /**
     * Store parsed headers to the database.
     *
     * @param  array<int, array<string, mixed>>  $headers  Parsed headers with 'matches' already populated
     * @param  array<string, mixed>  $groupMySQL  Group info from database
     * @param  bool  $addToPartRepair  Whether to track failed inserts
     * @return HeaderStorageReport Article numbers needing part repair, plus why they got there
     */
    public function store(array $headers, array $groupMySQL, bool $addToPartRepair = true, HeaderScanDirection $direction = HeaderScanDirection::Head): HeaderStorageReport
    {
        $this->report = HeaderStorageReport::empty();

        if (empty($headers)) {
            return $this->report;
        }

        // Header and SQL chunking are configured independently, but both are
        // clamped by BinariesConfig so generated statements remain bounded.
        $chunkSize = max(1, $this->config->headerChunkSize);

        // Walk the array with offset slicing instead of array_chunk() so we
        // don't materialize every chunk simultaneously in memory.
        $total = \count($headers);
        for ($offset = 0; $offset < $total; $offset += $chunkSize) {
            $chunk = \array_slice($headers, $offset, $chunkSize);
            $this->storeChunk($chunk, $groupMySQL, $addToPartRepair, $direction);
            unset($chunk);
        }

        return $this->report;
    }

    /**
     * Store one bounded header chunk inside its own transaction.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @param  array<string, mixed>  $groupMySQL
     */
    private function storeChunk(array $headers, array $groupMySQL, bool $addToPartRepair, HeaderScanDirection $direction): void
    {
        $attempt = 0;
        do {
            if ($this->storeChunkAttempt($headers, $groupMySQL, $addToPartRepair, $direction)) {
                $this->report = $this->report->withStoredChunk($this->attemptFailedNumbers, $attempt > 0);

                return;
            }

            $attempt++;
            if ($attempt >= self::CHUNK_RETRY_MAX || ! $this->isRetryableChunkFailure()) {
                $this->report = $this->report->withRolledBackChunk(
                    $this->attemptFailedNumbers,
                    $this->countAttemptFailures(static fn (HeaderFailureReason $reason): bool => $reason->isTransientRace()),
                    $this->countAttemptFailures(static fn (HeaderFailureReason $reason): bool => ! $reason->isTransientRace()),
                );

                return;
            }

            usleep((min(500, 20 * $attempt) + random_int(0, 25)) * 1000);
        } while (true);
    }

    /**
     * A rolled-back chunk is worth another attempt when the failure is timing, not data.
     *
     * Transient lock errors were always retried. The dominant failure in production is
     * silent instead: a concurrent scan of the same cross-posted upload commits the
     * collection (or binary) row after this transaction's REPEATABLE READ snapshot was
     * taken, so the insert-or-ignore lands on the peer row but the resolving SELECT
     * cannot see it. No exception is raised. A fresh transaction takes a fresh snapshot,
     * so attempt two resolves it. Headers rejected on their own merits are never retried.
     */
    private function isRetryableChunkFailure(): bool
    {
        if ($this->isTransientLockError($this->lastStorageException)) {
            return true;
        }

        if ($this->lastStorageException !== null || $this->attemptFailures === []) {
            return false;
        }

        foreach ($this->attemptFailures as $reason) {
            if (! $reason->isTransientRace()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  callable(HeaderFailureReason): bool  $predicate
     */
    private function countAttemptFailures(callable $predicate): int
    {
        return \count(array_filter($this->attemptFailures, $predicate));
    }

    /**
     * @param  array<int, array<string, mixed>>  $headers
     * @param  array<string, mixed>  $groupMySQL
     */
    private function storeChunkAttempt(array $headers, array $groupMySQL, bool $addToPartRepair, HeaderScanDirection $direction): bool
    {
        $this->lastStorageException = null;
        $this->attemptFailedNumbers = [];
        $this->attemptFailures = [];
        $this->collectionHandler->reset();
        $this->binaryHandler->reset();
        $this->partHandler->reset();
        $this->partHandler->setAddToPartRepair($addToPartRepair);

        $chunkNumbers = [];
        foreach ($headers as $header) {
            if (isset($header['Number']) && (\is_int($header['Number']) || \is_string($header['Number']))) {
                $chunkNumbers[] = $header['Number'];
            }
        }

        // Create transaction
        $transaction = new HeaderStorageTransaction(
            $this->collectionHandler,
            $this->binaryHandler
        );

        $transaction->begin();

        $this->processHeaderChunk($headers, $groupMySQL, $transaction);

        // Flush remaining parts
        if ($this->partHandler->hasPending()) {
            if (! $this->partHandler->flush()) {
                $transaction->markError();
                $this->attemptFailures[] = HeaderFailureReason::RejectedPart;
            }
        }

        // Flush binary aggregate updates
        if (! $transaction->hasErrors()) {
            if (! $this->binaryHandler->refreshAggregates(
                $this->partHandler->getTouchedBinaryIds(),
                $this->config->sqlChunkSize
            )) {
                $transaction->markError();
            }
            if (! $transaction->hasErrors() && ! $this->collectionHandler->refreshAggregates(
                $this->collectionHandler->getAllIds(),
                $this->config->sqlChunkSize,
                $direction,
                $this->frontierStamp($headers, $groupMySQL, $direction),
            )) {
                $transaction->markError();
            }
        }

        // Finish transaction
        if (! $transaction->finish()) {
            $this->lastStorageException = $transaction->getLastException()
                ?? $this->partHandler->getLastException()
                ?? $this->binaryHandler->getLastException()
                ?? $this->collectionHandler->getLastException();
            if ($addToPartRepair) {
                $this->attemptFailedNumbers = array_values(array_unique(array_merge(
                    $chunkNumbers,
                    $this->partHandler->getFailedNumbers()
                )));
            }

            return false;
        }

        $this->attemptFailedNumbers = $this->partHandler->getFailedNumbers();

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $headers
     * @param  array<string, mixed>  $group
     */
    private function frontierStamp(array $headers, array $group, HeaderScanDirection $direction): ?string
    {
        if ($direction === HeaderScanDirection::Repair) {
            return $group['last_record_postdate'] ?? null;
        }

        $timestamps = [];
        foreach ($headers as $header) {
            $date = $header['Date'] ?? null;
            $timestamp = is_numeric($date) ? (int) $date : strtotime((string) $date);
            if ($timestamp !== false && $timestamp > 0) {
                $timestamps[] = $timestamp;
            }
        }
        if ($timestamps === []) {
            return null;
        }

        return date('Y-m-d H:i:s', $direction === HeaderScanDirection::Tail ? min($timestamps) : max($timestamps));
    }

    private function isTransientLockError(?\Throwable $exception): bool
    {
        return $exception !== null && SqlError::isTransientLock($exception);
    }

    /**
     * @param  array<int, array<string, mixed>>  $headers
     * @param  array<string, mixed>  $groupMySQL
     */
    private function processHeaderChunk(array $headers, array $groupMySQL, HeaderStorageTransaction $transaction): void
    {
        $totalFilesByIndex = [];
        $fileNumbersByIndex = [];

        foreach ($headers as $index => $header) {
            [$fileNumber, $totalFiles] = $this->extractFileNumberAndTotal($header);
            $fileNumbersByIndex[$index] = $fileNumber;
            $totalFilesByIndex[$index] = $totalFiles;
        }

        $collectionIds = $this->collectionHandler->getOrCreateCollections(
            $headers,
            $groupMySQL['id'],
            $groupMySQL['name'],
            $totalFilesByIndex,
            $transaction->getBatchNoise()
        );

        $binaryRecords = [];
        foreach ($headers as $index => $header) {
            if (! isset($collectionIds[$index])) {
                $this->markHeaderFailed($transaction, HeaderFailureReason::UnresolvedCollection);

                continue;
            }

            $binaryRecords[$index] = [
                'header' => $header,
                'collection_id' => $collectionIds[$index],
                'file_number' => $fileNumbersByIndex[$index],
            ];
        }

        $binaryIds = $this->binaryHandler->getOrCreateBinaries($binaryRecords, $groupMySQL['id']);

        foreach ($binaryRecords as $index => $record) {
            $header = $record['header'];
            if (! isset($binaryIds[$index])) {
                $this->markHeaderFailed($transaction, HeaderFailureReason::UnresolvedBinary);

                continue;
            }

            if (! $this->partHandler->addPart($binaryIds[$index], $header)) {
                $this->markHeaderFailed($transaction, HeaderFailureReason::RejectedPart);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $header
     * @return array{0: int, 1: int}
     */
    private function extractFileNumberAndTotal(array $header): array
    {
        $fileCount = $this->getFileCount($header['matches'][1]);

        return [(int) $fileCount[1], (int) $fileCount[3]];
    }

    private function markHeaderFailed(HeaderStorageTransaction $transaction, HeaderFailureReason $reason): void
    {
        $transaction->markError();
        $this->attemptFailures[] = $reason;
    }

    /**
     * @return array<int, int|string>
     */
    private function getFileCount(string $subject): array
    {
        if (! preg_match('/[[(\s](\d{1,5})(\/|[\s_]of[\s_]|-)(\d{1,5})[])[\s$:]/i', $subject, $fileCount)) {
            $fileCount[1] = $fileCount[3] = 0;
        }

        return $fileCount;
    }
}
