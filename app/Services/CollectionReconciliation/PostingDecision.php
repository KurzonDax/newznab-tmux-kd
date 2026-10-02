<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Services\Nzb\CompletionTally;
use App\Services\Nzb\PhantomTrailingFile;

final readonly class PostingDecision
{
    /**
     * @param  list<PostingFile>  $accepted
     * @param  array<string, string>  $unresolved
     * @param  array<string, string>  $evidence
     */
    public function __construct(public array $accepted, public int $declaredTotal, public ?string $setId,
        public string $label, public array $unresolved, public array $evidence, public string $reason) {}

    /** @return list<string> */
    public function sources(): array
    {
        return array_values(array_unique(array_map(static fn (PostingFile $file): string => $file->sourceId, $this->accepted)));
    }

    public function completion(): float
    {
        // A post declaring one file it never posted is measured against the files it holds; the
        // declared total itself stays, because late collections are matched on it.
        $declared = PhantomTrailingFile::declaredFiles(array_column($this->accepted, 'subject'), $this->declaredTotal);
        $tally = new CompletionTally;
        foreach ($this->accepted as $file) {
            $tally->addFile(count($file->segments), $file->declaredParts, $declared);
        }

        return $tally->signals()->percentage();
    }

    public function complete(): bool
    {
        if ($this->declaredTotal < 1 || count($this->accepted) !== $this->declaredTotal
            || array_any($this->accepted, static fn (PostingFile $file): bool => ! $file->hasCompleteSegments())) {
            return false;
        }
        $ordinals = array_column($this->accepted, 'ordinal');
        sort($ordinals);

        return $ordinals === range(1, $this->declaredTotal) && $this->completion() === 100.0;
    }

    public function independentVideos(): bool
    {
        return self::hasIndependentVideos($this->accepted);
    }

    /** @param list<PostingFile> $files */
    public static function hasIndependentVideos(array $files): bool
    {
        return count(array_filter($files, static fn (PostingFile $file): bool => preg_match('/\.(mkv|mp4|avi|mov|wmv|m4v)$/iD', $file->filename) === 1)) > 1;
    }
}
