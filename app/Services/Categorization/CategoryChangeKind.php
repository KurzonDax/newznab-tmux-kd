<?php

declare(strict_types=1);

namespace App\Services\Categorization;

/** Why a release moves category, and what that kind of move does besides the move. */
enum CategoryChangeKind
{
    /** The rename listener files a renamed release; the rename already cleared its links. */
    case Rename;

    /** nntmux:recategorize-releases and category refiles move a release to its pipeline category. */
    case Recategorize;

    /** MediaInfo refinement moves a release out of an Other bucket. */
    case Refinement;

    /** Book processing files a junk book or a magazine. */
    case BookFiling;

    /** A movie lookup hands a release a TV match bound to TV. */
    case MatchedTvHandoff;

    /** A movie lookup hands a release IMDb called a series to TV without a TV match. */
    case UnmatchedSeriesHandoff;

    /** The legacy categorizer files an uncategorized Other › Misc release. */
    case LegacyCategorize;

    /** An admin moves several releases in one edit. */
    case AdminBulk;

    /**
     * The metadata links the move clears, with the value each takes.
     *
     * @return array<string, int|null>
     */
    public function clearedLinks(): array
    {
        return match ($this) {
            self::Recategorize => [
                'videos_id' => 0,
                'tv_episodes_id' => 0,
                'imdbid' => null,
                'musicinfo_id' => null,
                'consoleinfo_id' => null,
                'gamesinfo_id' => 0,
                'bookinfo_id' => null,
                'anidbid' => null,
            ],
            default => [],
        };
    }

    /** Whether the move marks the release categorized. */
    public function marksCategorized(): bool
    {
        return ! in_array($this, [self::BookFiling, self::MatchedTvHandoff, self::UnmatchedSeriesHandoff], true);
    }

    /** Whether MediaInfo refinement runs on the release after the move. */
    public function refines(): bool
    {
        return $this === self::Rename || $this === self::MatchedTvHandoff;
    }

    /** Whether the move is refused when the target lies outside the release's forced root. */
    public function respectsForcedRoot(): bool
    {
        return $this === self::MatchedTvHandoff || $this === self::UnmatchedSeriesHandoff;
    }

    /** Whether a preview the old category's policy skipped is queued again. */
    public function restoresPreviews(): bool
    {
        return $this !== self::MatchedTvHandoff && $this !== self::UnmatchedSeriesHandoff;
    }

    /** Whether that preview restore re-syncs the search index itself. */
    public function previewRestoreSynchronizes(): bool
    {
        return $this !== self::Refinement;
    }

    /** Whether the move re-syncs the release to the search index; a rename's writer already does. */
    public function synchronizes(): bool
    {
        return $this !== self::Rename;
    }
}
