<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Models\Settings;

/**
 * Configuration DTO for Binaries processing.
 * Encapsulates all settings in an immutable object for easier testing and injection.
 */
final readonly class BinariesConfig
{
    public function __construct(
        public int $messageBuffer = 20000,
        public bool $compressedHeaders = true,
        public bool $partRepair = true,
        public bool $newGroupScanByDays = false,
        public int $newGroupMessagesToScan = 50000,
        public int $newGroupDaysToScan = 3,
        public int $partRepairLimit = 15000,
        public int $partRepairMaxTries = 3,
        public bool $echoCli = false,
        // Number of headers processed (and bulk-inserted) at a time inside
        // HeaderStorageService. This MUST stay small because each chunk
        // produces multi-row INSERTs/SELECTs whose binding count and SQL size
        // grow linearly with the value. Large unbounded chunks caused MySQL
        // and PHP to allocate hundreds of MB per scan and run out of RAM.
        public int $headerChunkSize = 500,
        // One shared hard upper bound for every bulk SELECT/INSERT/UPDATE.
        public int $sqlChunkSize = 500,
        public int $reconcileBatchSize = 500,
        public int $nzbStreamRows = 5000,
    ) {}

    /**
     * Create configuration from application settings.
     */
    public static function fromSettings(): self
    {
        return new self(
            messageBuffer: self::getPositiveSettingInt('maxmssgs', 20000, whenNonPositive: 20000),
            compressedHeaders: (bool) config('nntmux_nntp.compressed_headers'),
            partRepair: self::getSettingInt('partrepair', 1) === 1,
            newGroupScanByDays: self::getSettingInt('newgroupscanmethod', 0) === 1,
            newGroupMessagesToScan: self::getSettingInt('newgroupmsgstoscan', 50000),
            newGroupDaysToScan: self::getSettingInt('newgroupdaystoscan', 3),
            partRepairLimit: self::getSettingInt('maxpartrepair', 15000),
            partRepairMaxTries: self::getPositiveSettingInt('partrepairmaxtries', 3, whenNonPositive: 1),
            echoCli: (bool) config('nntmux.echocli'),
            headerChunkSize: max(50, min(2000, (int) config('nntmux.cbp.header_chunk_size', 500))),
            sqlChunkSize: max(50, min(1000, (int) config('nntmux.cbp.sql_chunk_size', 500))),
            reconcileBatchSize: max(50, min(2000, (int) config('nntmux.cbp.reconcile_batch_size', 500))),
            nzbStreamRows: max(500, min(20000, (int) config('nntmux.cbp.nzb_stream_rows', 5000))),
        );
    }

    private static function getSettingInt(string $key, int $default): int
    {
        return (int) Settings::settingValueOr($key, $default);
    }

    /**
     * Read a setting that only means something as a positive number, substituting
     * $whenNonPositive for anything below 1.
     *
     * Both settings read this way are unvalidated admin text inputs feeding arithmetic
     * that misbehaves at zero, and they differ only in what a hostile value should become.
     *
     * The message buffer is a chunk width: stored as 0 it left both chunk walks standing
     * still, because the header-update article-range loop recomputed an identical window
     * every pass and the backfill walk stepped back to exactly where it started. Any
     * positive width works, so it falls back to the coded default. Clamping here covers
     * both walks, since backfill takes the buffer off the binaries service's config rather
     * than reading the setting again; the tmux binaries runner keeps its own warned
     * substitution for the fan-out math it does before any config exists, and this lands
     * on the same value.
     *
     * The part-repair budget is a count of attempts: cleanup deletes missed parts whose
     * attempts reached the maximum, so a stored 0 both selected nothing for repair and
     * emptied the group's queue on every pass. The `partrepair` on/off setting is the
     * sanctioned way to disable repair, so anything below 1 is misconfiguration rather
     * than intent and buys a single attempt.
     */
    private static function getPositiveSettingInt(string $key, int $default, int $whenNonPositive): int
    {
        $value = self::getSettingInt($key, $default);

        return $value >= 1 ? $value : $whenNonPositive;
    }
}
