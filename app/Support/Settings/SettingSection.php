<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * One page of the settings hub: a slug, a place in the pipeline, and its cards in order.
 */
final readonly class SettingSection
{
    /**
     * @param  list<SettingCard>  $cards
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $icon,
        public ?PipelineStage $stage,
        public array $cards,
    ) {}

    public function card(string $id): ?SettingCard
    {
        foreach ($this->cards as $card) {
            if ($card->id === $id) {
                return $card;
            }
        }

        return null;
    }
}
