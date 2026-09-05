<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * Where a setting lives: the answer the sidebar search needs in order to link to it.
 */
final readonly class SettingLocation
{
    public function __construct(
        public SettingSection $section,
        public SettingCard $card,
        public SettingDefinition $definition,
    ) {}

    /**
     * "Usenet Ingest ▸ Backfill", as shown on a search result.
     */
    public function breadcrumb(): string
    {
        return $this->section->title.' ▸ '.$this->card->title;
    }
}
