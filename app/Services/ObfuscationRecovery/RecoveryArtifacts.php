<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class RecoveryArtifacts
{
    private readonly string $root;

    private readonly RecoveryArtifactJournal $journal;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? (string) config('filesystems.disks.recovery.root');
        if ($this->root === '' || (! is_dir($this->root) && ! mkdir($this->root, 0700, true) && ! is_dir($this->root))) {
            throw new RuntimeException('artifact_storage_unavailable');
        }
        $this->journal = new RecoveryArtifactJournal($this->root);
    }

    /** @param iterable<string> $chunks */
    public function put(iterable $chunks, int $maximumBytes): RecoveryArtifact
    {
        $lock = $this->lock(LOCK_SH);
        $temporaryName = '.'.bin2hex(random_bytes(16));
        $temporary = $this->root.'/'.$temporaryName;
        $this->journal->retain($temporaryName);
        $stream = fopen($temporary, 'xb');
        if ($stream === false) {
            fclose($lock);
            throw new RuntimeException('artifact_storage_unavailable');
        }
        chmod($temporary, 0600);
        $context = hash_init('sha256');
        $bytes = 0;
        $block = '';
        $blocks = [];
        try {
            foreach ($chunks as $chunk) {
                $length = strlen($chunk);
                if ($length > $maximumBytes - $bytes) {
                    throw new RuntimeException('artifact_size_limit');
                }
                for ($offset = 0; $offset < $length;) {
                    $written = fwrite($stream, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('artifact_write_failed');
                    }
                    $offset += $written;
                }
                hash_update($context, $chunk);
                $bytes += $length;
                $block .= $chunk;
                while (strlen($block) >= 65536) {
                    $blocks[] = hash('sha256', substr($block, 0, 65536));
                    $block = substr($block, 65536);
                }
            }
            if (! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('artifact_sync_failed');
            }
            $artifact = new RecoveryArtifact(hash_final($context), $bytes);
            $this->journal->retain($artifact->digest);
            if ($block !== '') {
                $blocks[] = hash('sha256', $block);
            }
            $this->journal->blocks($artifact->digest, $blocks);
            if (Schema::hasTable('obfuscation_recovery_artifacts')) {
                DB::table('obfuscation_recovery_artifacts')->upsert([
                    'digest' => $artifact->digest, 'bytes' => $artifact->bytes, 'retained_at' => now(),
                ], ['digest'], ['retained_at']);
            }
            $destination = $this->root.'/'.$artifact->digest;
            if (! is_file($destination) && ! @link($temporary, $destination) && ! is_file($destination)) {
                throw new RuntimeException('artifact_finalize_failed');
            }
            foreach ($this->read($artifact) as $_) {
                // Verify the durable object before returning a reference to it.
            }
            $directory = fopen($this->root, 'r');
            if ($directory === false) {
                throw new RuntimeException('artifact_directory_unavailable');
            }
            try {
                if (! fsync($directory)) {
                    throw new RuntimeException('artifact_directory_sync_failed');
                }
            } finally {
                fclose($directory);
            }

            return $artifact;
        } finally {
            fclose($stream);
            if (is_file($temporary)) {
                unlink($temporary);
            }
            $this->journal->forget($temporaryName);
            fclose($lock);
        }
    }

    /** @return Generator<int, string> */
    public function read(RecoveryArtifact $artifact): Generator
    {
        $lock = $this->lock(LOCK_SH);
        $stream = @fopen($this->root.'/'.$artifact->digest, 'rb');
        if ($stream === false) {
            fclose($lock);
            throw new RuntimeException('artifact_missing');
        }
        try {
            $context = hash_init('sha256');
            $bytes = hash_update_stream($context, $stream);
            if ($bytes !== $artifact->bytes || ! hash_equals($artifact->digest, hash_final($context))) {
                throw new RuntimeException('artifact_integrity_failure');
            }
            if (! rewind($stream)) {
                throw new RuntimeException('artifact_read_failed');
            }
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('artifact_read_failed');
                }
                if ($chunk !== '') {
                    yield $chunk;
                }
            }
        } finally {
            fclose($stream);
            fclose($lock);
        }
    }

    public function remove(RecoveryArtifact $artifact): bool
    {
        $lock = $this->lock(LOCK_EX | LOCK_NB);
        if ($lock === false) {
            return false;
        }
        try {
            $path = $this->root.'/'.$artifact->digest;
            $removed = ! is_file($path) || unlink($path);
            if ($removed) {
                $this->journal->forget($artifact->digest);
            }

            return $removed;
        } finally {
            fclose($lock);
        }
    }

    /** @return Generator<int,string> */
    public function range(RecoveryArtifact $artifact, int $offset, int $maximum): Generator
    {
        if ($offset < 0 || $offset > $artifact->bytes || $maximum < 1 || $maximum > 8388608) {
            throw new RuntimeException('artifact_range_invalid');
        }
        $lock = $this->lock(LOCK_SH);
        $stream = @fopen($this->root.'/'.$artifact->digest, 'rb');
        if ($stream === false) {
            fclose($lock);
            throw new RuntimeException('artifact_missing');
        }
        try {
            if (fstat($stream)['size'] !== $artifact->bytes) {
                throw new RuntimeException('artifact_integrity_failure');
            }
            $start = intdiv($offset, 65536) * 65536;
            $end = min($artifact->bytes, $offset + $maximum);
            if (fseek($stream, $start) !== 0) {
                throw new RuntimeException('artifact_read_failed');
            }
            while ($start < $end) {
                $block = fread($stream, 65536);
                $digest = $this->journal->block($artifact->digest, $start);
                if ($block === false || $digest === null || ! hash_equals($digest, hash('sha256', $block))) {
                    throw new RuntimeException('artifact_integrity_failure');
                }
                $skip = max(0, $offset - $start);
                yield substr($block, $skip, min(strlen($block) - $skip, $end - $start - $skip));
                $start += 65536;
            }
        } finally {
            fclose($stream);
            fclose($lock);
        }
    }

    public function collectOrphans(int $limit): int
    {
        $removed = 0;
        foreach ($this->journal->due($limit) as $name) {
            if (preg_match('/^(?:[a-f0-9]{64}|\.[a-f0-9]{32})$/D', $name) !== 1) {
                throw new RuntimeException('artifact_journal_invalid');
            }
            $removed += DB::transaction(function () use ($name): int {
                $catalog = DB::table('obfuscation_recovery_artifacts')->where('digest', $name)->lockForUpdate()->first();
                if ($catalog !== null || DB::table('obfuscation_recovery_references')->where('resource_type', 'artifact')->where('resource_digest', $name)->exists()) {
                    $this->journal->retain($name);

                    return 0;
                }
                $lock = $this->lock(LOCK_EX | LOCK_NB);
                if ($lock === false) {
                    return 0;
                }
                try {
                    if (! $this->journal->expired($name)) {
                        return 0;
                    }
                    $path = $this->root.'/'.$name;
                    $exists = is_file($path);
                    if ($exists && ! unlink($path)) {
                        return 0;
                    }
                    $this->journal->forget($name);

                    return (int) $exists;
                } finally {
                    fclose($lock);
                }
            }, 1);
        }

        return $removed;
    }

    /** @return resource|false */
    private function lock(int $operation)
    {
        $lock = fopen($this->root.'/.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('artifact_lock_unavailable');
        }
        chmod($this->root.'/.lock', 0600);
        if (! flock($lock, $operation)) {
            fclose($lock);
            if (($operation & LOCK_NB) === 0) {
                throw new RuntimeException('artifact_lock_unavailable');
            }

            return false;
        }

        return $lock;
    }
}
