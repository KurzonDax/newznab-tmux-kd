<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

use App\Services\MusicIdentity\Support\MusicIdentityValueNormalizer;

/**
 * @phpstan-type NormalizedReleaseQuery array{
 *     artist: string|null,
 *     artistId: string|null,
 *     barcode: string|null,
 *     catalogNumber: string|null,
 *     fuzzy: bool,
 *     label: string|null,
 *     limit: int|null,
 *     offset: int,
 *     title: string|null,
 *     year: int|null
 * }
 */
final readonly class ReleaseQuery
{
    public function __construct(
        public ?string $title = null,
        public ?string $artist = null,
        public ?string $artistId = null,
        public ?int $year = null,
        public ?string $barcode = null,
        public ?string $catalogNumber = null,
        public ?string $label = null,
        public bool $fuzzy = false,
        public ?int $limit = null,
        public int $offset = 0,
    ) {}

    /** @return NormalizedReleaseQuery */
    public function normalized(): array
    {
        return [
            'artist' => MusicIdentityValueNormalizer::text($this->artist),
            'artistId' => MusicIdentityValueNormalizer::identifier($this->artistId),
            'barcode' => MusicIdentityValueNormalizer::text($this->barcode),
            'catalogNumber' => MusicIdentityValueNormalizer::text($this->catalogNumber),
            'fuzzy' => $this->fuzzy,
            'label' => MusicIdentityValueNormalizer::text($this->label),
            'limit' => $this->limit === null ? null : min(100, max(1, $this->limit)),
            'offset' => max(0, $this->offset),
            'title' => MusicIdentityValueNormalizer::text($this->title),
            'year' => $this->year === null ? null : min(9999, max(1, $this->year)),
        ];
    }
}
