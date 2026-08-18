<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Services\BlacklistService;
use Illuminate\Support\Facades\Log;

/**
 * Parses and filters raw NNTP headers.
 */
final class HeaderParser
{
    private BlacklistService $blacklistService;

    private int $notYEnc = 0;

    private int $blacklisted = 0;

    private int $rejected = 0;

    public function __construct(?BlacklistService $blacklistService = null)
    {
        $this->blacklistService = $blacklistService ?? new BlacklistService;
    }

    /**
     * Reset counters for a new batch.
     */
    public function reset(): void
    {
        $this->notYEnc = 0;
        $this->blacklisted = 0;
        $this->rejected = 0;
    }

    /**
     * Parse and filter raw headers from NNTP.
     *
     * @param  array<int, array<string, mixed>>  $headers  Raw headers from NNTP
     * @param  string  $groupName  The newsgroup name
     * @param  bool  $partRepair  Whether this is a part repair scan
     * @param  array<int, mixed>|null  $missingParts  Missing part numbers if part repair
     * @return array<string, mixed> Filtered and parsed headers with article info
     */
    public function parse(
        array $headers,
        string $groupName,
        bool $partRepair = false,
        ?array $missingParts = null
    ): array {
        $parsed = [];
        $headersRepaired = [];
        $receivedNumbers = [];
        $missingPartSet = $missingParts === null
            ? null
            : (array_is_list($missingParts)
                ? array_fill_keys(array_map('intval', $missingParts), true)
                : $missingParts);

        foreach ($headers as $header) {
            // Check we got the article, and that its number is usable. A desynchronised
            // overview format (see NNTPService::getXOVER()) shifts every field by one, so
            // 'Number' holds the subject line. Treating those as received would advance the
            // group pointer to a garbage value and queue every requested article for part
            // repair, so drop them before anything else looks at them.
            $number = $header['Number'] ?? null;
            if (! \is_scalar($number) || ! ctype_digit((string) $number)) {
                $this->rejected++;

                continue;
            }

            $receivedNumbers[] = $header['Number'];

            // For part repair, only process missing parts
            if ($partRepair && $missingPartSet !== null) {
                if (! isset($missingPartSet[(int) $header['Number']])) {
                    continue;
                }
                $headersRepaired[] = $header['Number'];
            }

            // Parse subject to get base name and part/total like "(12/45)"
            if (! preg_match('/^\s*(?!Usenet Index Post)(.+?)\s+\((\d+)\/(\d+)\)/', $header['Subject'], $matches)) {
                $this->notYEnc++;

                continue;
            }

            // Normalize to include yEnc if missing
            if (stripos($header['Subject'], 'yEnc') === false) {
                $matches[1] .= ' yEnc';
            }

            $header['matches'] = $matches;

            // Filter subject based on black/white list
            if ($this->blacklistService->isBlackListed($header, $groupName)) {
                $this->blacklisted++;

                continue;
            }

            // Ensure Bytes is set
            if (empty($header['Bytes'])) {
                $header['Bytes'] = $header[':bytes'] ?? 0;
            }

            $parsed[] = $header;
        }

        return [
            'headers' => $parsed,
            'repaired' => $headersRepaired,
            'received' => $receivedNumbers,
            'notYEnc' => $this->notYEnc,
            'blacklisted' => $this->blacklisted,
            'rejected' => $this->rejected,
        ];
    }

    /**
     * Update blacklist last_activity for matched rules.
     */
    public function flushBlacklistUpdates(): void
    {
        $ids = $this->blacklistService->getAndClearIdsToUpdate();
        if (! empty($ids)) {
            $this->blacklistService->updateBlacklistUsage($ids); // @phpstan-ignore argument.type
        }
    }

    /**
     * Get count of non-yEnc headers filtered.
     */
    public function getNotYEncCount(): int
    {
        return $this->notYEnc;
    }

    /**
     * Get count of blacklisted headers.
     */
    public function getBlacklistedCount(): int
    {
        return $this->blacklisted;
    }

    /**
     * Get count of headers dropped because they carried no usable article number.
     */
    public function getRejectedCount(): int
    {
        return $this->rejected;
    }

    /**
     * Extract highest and lowest article info from headers.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @return array<string, mixed>
     */
    public function getArticleRange(array $headers, string $groupName, int $first, int $last): array
    {
        $firstValidHeader = null;
        $lastValidHeader = null;

        foreach ($headers as $header) {
            if (! array_key_exists('Number', $header)) {
                continue;
            }

            $rawNumber = $header['Number'];
            $reason = null;
            if (! \is_string($rawNumber) || preg_match('/^[+-]?\d+$/D', $rawNumber) !== 1) {
                $reason = 'not_an_integer_string';
            } else {
                $articleNumber = filter_var($rawNumber, FILTER_VALIDATE_INT, [
                    'options' => [
                        'min_range' => $first,
                        'max_range' => $last,
                    ],
                ]);
                if ($articleNumber === false) {
                    $reason = 'out_of_range';
                }
            }

            if ($reason !== null) {
                Log::warning('Rejected XOVER article number while determining scan range.', [
                    'group' => $groupName,
                    'requested_first' => $first,
                    'requested_last' => $last,
                    'offending_value' => $rawNumber,
                    'reason' => $reason,
                ]);

                continue;
            }

            $validHeader = [
                'number' => $articleNumber,
                'date' => $header['Date'] ?? null,
            ];
            $firstValidHeader ??= $validHeader;
            $lastValidHeader = $validHeader;
        }

        if ($firstValidHeader === null || $lastValidHeader === null) {
            return [];
        }

        return [
            'firstArticleNumber' => $firstValidHeader['number'],
            'firstArticleDate' => $firstValidHeader['date'],
            'lastArticleNumber' => $lastValidHeader['number'],
            'lastArticleDate' => $lastValidHeader['date'],
        ];
    }
}
