<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\SevenZip;

use App\Services\AdditionalProcessing\Enums\PasswordVerdict;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The on-disk copy of an inspected archive that 7-Zip reads: one sparse file
 * per volume, archive.7z.001 onward, at its volume's size, holding every
 * decoded article the inspection fetched at its yEnc offset. Nothing is
 * written until it is first used, and only after a verified inspection, when
 * every volume has been resolved. Its owner deletes it before returning.
 */
final class WorkingSet
{
    private ?PasswordVerdict $inspectionVerdict = null;

    private ?Listing $listing = null;

    private bool $built = false;

    public function __construct(
        public readonly ArchiveRanges $ranges,
        private readonly string $directory,
    ) {}

    public function recordInspection(PasswordVerdict $verdict): void
    {
        $this->inspectionVerdict = $verdict;
    }

    public function inspectionVerdict(): ?PasswordVerdict
    {
        return $this->inspectionVerdict;
    }

    public function listing(): ?Listing
    {
        return $this->listing;
    }

    public function rememberListing(Listing $listing): void
    {
        $this->listing = $listing;
    }

    /** The path 7-Zip opens, writing the set first when it is not yet on disk. */
    public function firstVolumePath(): string
    {
        $this->build();

        return $this->path(0);
    }

    /** Writes decoded bytes into one volume at an offset inside it. */
    public function write(int $volume, int $offset, string $data): void
    {
        $this->build();
        $size = $this->ranges->volumes()[$volume]['size'] ?? null;
        if ($size === null || $offset < 0 || $offset > $size - strlen($data)) {
            throw new RuntimeException('working-set-range');
        }
        $this->writeAt($this->path($volume), $offset, $data);
    }

    public function delete(): void
    {
        if (File::isDirectory($this->directory)) {
            File::deleteDirectory($this->directory);
        }
    }

    private function build(): void
    {
        if ($this->built) {
            return;
        }
        if (! in_array($this->inspectionVerdict, [PasswordVerdict::VerifiedEncrypted, PasswordVerdict::VerifiedUnencrypted], true)) {
            throw new RuntimeException('unverified-working-set');
        }
        $volumes = $this->ranges->volumes();
        if ($volumes === [] || count($volumes) > 999) {
            throw new RuntimeException('working-set-volumes');
        }
        File::ensureDirectoryExists($this->directory);
        foreach ($volumes as $index => $volume) {
            $handle = fopen($this->path($index), 'c+b');
            if ($handle === false || ! ftruncate($handle, $volume['size'])) {
                throw new RuntimeException('working-set-write');
            }
            fclose($handle);
        }
        $this->built = true;
        foreach ($this->ranges->heldArticles() as $article) {
            $this->writeAt($this->path($article['volume']), $article['offset'], $article['data']);
        }
    }

    private function writeAt(string $path, int $offset, string $data): void
    {
        $handle = fopen($path, 'r+b');
        if ($handle === false) {
            throw new RuntimeException('working-set-write');
        }
        try {
            if (fseek($handle, $offset) !== 0 || fwrite($handle, $data) !== strlen($data)) {
                throw new RuntimeException('working-set-write');
            }
        } finally {
            fclose($handle);
        }
    }

    private function path(int $volume): string
    {
        return rtrim($this->directory, '/').'/archive.7z.'.sprintf('%03d', $volume + 1);
    }
}
