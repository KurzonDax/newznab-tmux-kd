<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * The declarative description of every admin-editable setting.
 *
 * This is the single whitelist of legitimate setting keys. The hub renders from it, the save
 * path validates against it, and the sidebar search indexes it, so a setting that is not
 * described here cannot be shown, written, or found -- which is the point: the old forms
 * accepted whatever the browser posted.
 *
 * Sections are declared one provider class per page. Resolve this through the container
 * (it is registered as a singleton) rather than constructing it, so the tree is built once
 * per request; tests bind their own provider list to work against a fixture.
 */
class SettingsRegistry
{
    /**
     * The hub's pages, in sidebar order.
     *
     * @var list<class-string<SettingsSectionProvider>>
     */
    public const array SECTION_PROVIDERS = [
        Sections\WebsiteSection::class,
        Sections\EngineSection::class,
        Sections\UsenetIngestSection::class,
        Sections\ReleaseFormationSection::class,
    ];

    /** @var array<string, SettingSection>|null */
    private ?array $sections = null;

    /** @var array<string, SettingLocation>|null */
    private ?array $index = null;

    /**
     * @param  list<class-string<SettingsSectionProvider>>  $providers
     */
    public function __construct(private readonly array $providers = self::SECTION_PROVIDERS) {}

    /**
     * @return array<string, SettingSection>
     */
    public function sections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        $sections = [];

        foreach ($this->providers as $provider) {
            $section = $provider::section();
            $sections[$section->id] = $section;
        }

        return $this->sections = $sections;
    }

    public function section(string $id): ?SettingSection
    {
        return $this->sections()[$id] ?? null;
    }

    /**
     * The first page of the hub, used as the landing section and as the redirect target for
     * the retired legacy URLs.
     */
    public function defaultSectionId(): ?string
    {
        return array_key_first($this->sections());
    }

    public function card(string $sectionId, string $cardId): ?SettingCard
    {
        return $this->section($sectionId)?->card($cardId);
    }

    public function definition(string $key): ?SettingDefinition
    {
        return $this->locate($key)?->definition;
    }

    public function locate(string $key): ?SettingLocation
    {
        return $this->locations()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->locations()[$key]);
    }

    /**
     * Every registered key, per-root toggle sets included.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->locations());
    }

    /**
     * Free-text search over key, label, help, and option labels.
     *
     * Exact key matches sort first, then key fragments, then everything else, because an admin
     * who types a key name is looking for that setting and not for the six that mention it.
     *
     * @return list<SettingLocation>
     */
    public function search(string $term): array
    {
        $term = mb_strtolower(trim($term));

        if ($term === '') {
            return [];
        }

        $hits = [];

        foreach ($this->locations() as $key => $location) {
            if (! str_contains($location->definition->searchHaystack(), $term)) {
                continue;
            }

            $rank = match (true) {
                $key === $term => 0,
                str_contains(mb_strtolower($key), $term) => 1,
                str_contains(mb_strtolower($location->definition->label), $term) => 2,
                default => 3,
            };

            $hits[] = ['rank' => $rank, 'location' => $location];
        }

        usort($hits, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return array_map(static fn (array $hit): SettingLocation => $hit['location'], $hits);
    }

    /**
     * @return array<string, SettingLocation>
     */
    private function locations(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];

        foreach ($this->sections() as $section) {
            foreach ($section->cards as $card) {
                foreach ($card->settings as $definition) {
                    $index[$definition->key] = new SettingLocation($section, $card, $definition);
                }
            }
        }

        return $this->index = $index;
    }
}
