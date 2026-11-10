<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\SevenZip;

/**
 * The outcome of `7z l -slt` over a working set: its exit code, its output
 * (stdout and, through -bse1, stderr) and the entries it listed, folders left
 * out, in listing order.
 *
 * @phpstan-type Entry array{path: string, size: int, packedSize: int, block: string}
 */
final readonly class Listing
{
    /** @param list<Entry> $entries */
    public function __construct(
        public int $exitCode,
        public string $output,
        public array $entries = [],
    ) {}

    public static function fromOutput(int $exitCode, string $output): self
    {
        $entries = [];
        $entry = null;
        $listed = false;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (! $listed) {
                $listed = trim($line) === '----------';

                continue;
            }
            if (trim($line) === '') {
                $entries[] = $entry;
                $entry = null;

                continue;
            }
            if (preg_match('/^([^=]+?) = ?(.*)$/', $line, $field) === 1) {
                $entry ??= [];
                $entry[$field[1]] = $field[2];
            }
        }
        $entries[] = $entry;

        $listing = [];
        foreach ($entries as $fields) {
            if (! isset($fields['Path']) || str_starts_with($fields['Attributes'] ?? '', 'D')) {
                continue;
            }
            $listing[] = [
                'path' => $fields['Path'],
                'size' => max(0, (int) ($fields['Size'] ?? 0)),
                'packedSize' => max(0, (int) ($fields['Packed Size'] ?? 0)),
                'block' => trim($fields['Block'] ?? ''),
            ];
        }

        return new self($exitCode, $output, $listing);
    }

    /** The shell could not run 7-Zip: it is missing or not executable. */
    public function unavailable(): bool
    {
        return $this->exitCode === 126 || $this->exitCode === 127;
    }

    /** 7-Zip could not open the archive with the password it was given. */
    public function wrongPassword(): bool
    {
        return $this->exitCode !== 0 && str_contains($this->output, 'Wrong password');
    }

    /**
     * The entries as release files, recorded as open: the listing only
     * succeeds once the archive is open.
     *
     * @return list<array{name: string, size: int, date: int, pass: int}>
     */
    public function releaseFiles(): array
    {
        return array_map(
            static fn (array $entry): array => ['name' => $entry['path'], 'size' => $entry['size'], 'date' => 0, 'pass' => 0],
            $this->entries,
        );
    }
}
