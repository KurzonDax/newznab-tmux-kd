<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Releases\OrphanedCollectionRecovery;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class RecoverOrphanedCollections extends Command
{
    protected $signature = 'releases:recover-orphaned-collections
        {--limit=100 : Maximum collections to report (1-100)}
        {--after-id=0 : Resume reporting after this collection ID}
        {--manifest-out= : Write a new report manifest without overwriting an existing file}
        {--apply : Apply explicitly reviewed manifest selections}
        {--manifest= : Reviewed JSON manifest to apply}';

    protected $description = 'Report dangling collections or reopen explicitly reviewed entries for normal formation';

    public function handle(OrphanedCollectionRecovery $recovery): int
    {
        try {
            if ($this->option('apply')) {
                $path = (string) $this->option('manifest');
                if ($path === '' || ! is_file($path) || filesize($path) > 2097152) {
                    throw new RuntimeException('Apply requires a readable manifest of at most 2 MiB.');
                }
                $contents = file_get_contents($path, length: 2097153);
                if ($contents === false || strlen($contents) > 2097152) {
                    throw new RuntimeException('Cannot read a bounded manifest.');
                }
                $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($manifest) || ($manifest['version'] ?? null) !== 1
                    || ! is_array($manifest['entries'] ?? null) || count($manifest['entries']) > OrphanedCollectionRecovery::MAX_ENTRIES) {
                    throw new RuntimeException('Expected a version 1 manifest with at most 100 entries.');
                }
                foreach ($manifest['entries'] as $entry) {
                    if (! is_array($entry)) {
                        throw new RuntimeException('Invalid manifest entry.');
                    }
                    $this->line(json_encode($recovery->apply($entry), JSON_THROW_ON_ERROR));
                }

                return self::SUCCESS;
            }
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            $afterId = filter_var($this->option('after-id'), FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > OrphanedCollectionRecovery::MAX_ENTRIES || $afterId === false || $afterId < 0) {
                throw new RuntimeException('Use --limit=1..100 and a non-negative --after-id.');
            }
            $report = ['version' => 1, 'entries' => $recovery->discover($limit, $afterId)];
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
            $path = (string) $this->option('manifest-out');
            if ($path !== '') {
                $file = @fopen($path, 'xb');
                if ($file === false) {
                    throw new RuntimeException('Cannot create manifest; choose a new writable path.');
                }
                try {
                    if (fwrite($file, $json) !== strlen($json)) {
                        throw new RuntimeException('Could not write the complete manifest.');
                    }
                } finally {
                    fclose($file);
                }
            }
            $this->line($json);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
