<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseResolution;
use App\Services\Releases\ReleaseRowFacts;

/**
 * What a release row of the redesigned screens shows beside its title (TvReleaseRow,
 * MovieReleaseRow): the chip line and the group and poster pair.
 */
trait ReleaseRowParts
{
    public function hasChips(): bool
    {
        return $this->completion !== null || $this->passworded || $this->mediaInfo !== null || $this->nfo || $this->preview !== null || $this->sample !== null;
    }

    /** The releases list's group and poster chips close the chip line when the release has either. */
    public function hasOrigin(): bool
    {
        return $this->group !== '' || $this->uploader !== '';
    }

    /** The group chip's label: `a.b.` stands for `alt.binaries.`, the full name stays in its title. */
    public function groupLabel(): string
    {
        return str_starts_with($this->group, 'alt.binaries.') ? 'a.b.'.substr($this->group, strlen('alt.binaries.')) : $this->group;
    }

    /** The details header's media info chip: the row's summary led by the release's resolution when both are known. */
    public function mediaInfoWithResolution(): ?string
    {
        if ($this->mediaInfo === null || $this->mediaInfo === ReleaseRowFacts::MEDIA_INFO_FALLBACK || $this->resolution === ReleaseResolution::Unknown) {
            return $this->mediaInfo;
        }

        return $this->resolution->label().' · '.$this->mediaInfo;
    }
}
