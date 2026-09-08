<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use UnexpectedValueException;

/** Immutable source inventory. Overview sizes are deliberately not decoded payload lengths. */
final readonly class PostingFile
{
    public string $filename;

    public int $ordinal;

    public int $total;

    /** @var list<string> */
    public array $groups;

    /**
     * @param  list<array{number: int, messageid: string, bytes: int}>  $segments
     * @param  list<string>  $groups
     */
    public function __construct(
        public string $sourceId,
        public string $fileId,
        public string $subject,
        public string $group,
        public string $poster,
        public int $date,
        public int $declaredParts,
        public array $segments,
        array $groups = [],
    ) {
        $this->groups = $groups === [] ? [$group] : $groups;
        $parsed = self::subject($subject);
        $this->filename = $parsed['filename'];
        $this->ordinal = $parsed['ordinal'];
        $this->total = $parsed['total'];
    }

    /** @return array{filename: string, ordinal: int, total: int} */
    public static function subject(string $subject): array
    {
        if (! preg_match('/^\[(\d{1,5})\/(\d{1,5})\] - "([^"\r\n]+)"[ _-]{0,3}yEnc(?: \(\d+\/\d+\))?$/uD', $subject, $match)
            || (int) $match[1] < 1 || (int) $match[1] > (int) $match[2]) {
            throw new UnexpectedValueException('unsupported_subject');
        }

        return ['filename' => $match[3], 'ordinal' => (int) $match[1], 'total' => (int) $match[2]];
    }

    public function firstArticle(): string
    {
        foreach ($this->segments as $segment) {
            if ($segment['number'] === 1) {
                return '<'.trim($segment['messageid'], '<>').'>';
            }
        }
        throw new UnexpectedValueException('missing_first_article');
    }

    public function isPar2(): bool
    {
        return preg_match('/\.par2$/iD', $this->filename) === 1;
    }

    public function isBasePar2(): bool
    {
        return $this->isPar2() && preg_match('/\.vol\d+\+\d+\.par2$/iD', $this->filename) !== 1;
    }

    public function hasCompleteSegments(): bool
    {
        $numbers = array_column($this->segments, 'number');
        sort($numbers);

        return $this->declaredParts > 0 && count($numbers) === $this->declaredParts
            && $numbers === range(1, $this->declaredParts);
    }
}
