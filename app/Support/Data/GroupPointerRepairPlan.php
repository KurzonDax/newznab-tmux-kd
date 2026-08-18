<?php

declare(strict_types=1);

namespace App\Support\Data;

use App\Models\UsenetGroup;

/**
 * What `groups:repair-article-pointers` decided to do with one group, and how to
 * say so in its report.
 */
final readonly class GroupPointerRepairPlan
{
    public const string ACTION_RESUME = 'resume';

    public const string ACTION_RE_ANCHOR = 're-anchor';

    public const string ACTION_SKIP = 'skip';

    /**
     * @param  array{number: int, date: string|null}|null  $resumeFrom
     */
    private function __construct(
        public UsenetGroup $group,
        public string $action,
        public ?array $resumeFrom,
        public string $serverRangeLabel,
        public string $outcomeLabel,
    ) {}

    /**
     * @param  array{number: int, date: string|null}  $resumeFrom
     */
    public static function resume(UsenetGroup $group, array $resumeFrom, string $serverRangeLabel, string $outcomeLabel): self
    {
        return new self($group, self::ACTION_RESUME, $resumeFrom, $serverRangeLabel, $outcomeLabel);
    }

    public static function reAnchor(UsenetGroup $group, string $serverRangeLabel, string $outcomeLabel): self
    {
        return new self($group, self::ACTION_RE_ANCHOR, null, $serverRangeLabel, $outcomeLabel);
    }

    public static function skip(UsenetGroup $group, string $serverRangeLabel, string $outcomeLabel): self
    {
        return new self($group, self::ACTION_SKIP, null, $serverRangeLabel, $outcomeLabel);
    }

    public function isRepairable(): bool
    {
        return $this->action !== self::ACTION_SKIP;
    }
}
