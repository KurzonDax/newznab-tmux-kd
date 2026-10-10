<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\GenericReleaseFilters;
use App\Data\MovieFilmWallFilters;
use App\Data\ReleaseListFilters;
use App\Data\TvShowFilters;
use App\Enums\BrowseRoot;
use App\Enums\HomeShelf;
use App\Services\Releases\HomeShelfPreferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateReleaseViewRequest extends FormRequest
{
    /** The list roots' keys, refused for the home page. */
    private const array LIST_KEYS = ['view', 'size', 'per', 'thumbs', 'sort', 'shows_sort', 'films_sort'];

    /** The home page's root (docs/proposals/home-redesign/DATA-NOTES.md 4): no BrowseRoot case, it is not a list. */
    public function isHome(): bool
    {
        return $this->input('root') === HomeShelfPreferences::KEY;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->isHome()) {
            $names = ['array', 'max:'.count(HomeShelf::cases())];
            $name = ['string', 'distinct', Rule::enum(HomeShelf::class)];

            return [
                'root' => ['required'],
                // the ticks travel with the rows the dialog lists, so a shelf it does not list keeps its own
                'shelves' => [Rule::requiredIf($this->has('ticked')), ...$names, 'min:1'],
                'shelves.*' => $name,
                'ticked' => ['sometimes', ...$names],
                'ticked.*' => $name,
                ...array_fill_keys(self::LIST_KEYS, ['prohibited']),
            ];
        }
        $value = $this->input('root');
        $root = is_string($value) ? BrowseRoot::tryFrom($value) : null;

        return [
            'root' => ['required', Rule::enum(BrowseRoot::class)],
            'shelves' => ['prohibited'],
            'ticked' => ['prohibited'],
            'view' => ['sometimes', 'string', Rule::in($root?->views() ?? ['table'])],
            'size' => ['sometimes', 'string', Rule::in($root?->coverSizes() ?? ['s'])],
            'per' => ['sometimes', 'integer', 'in:24,48,100'],
            'thumbs' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'string', Rule::in(match (true) {
                in_array($root, [BrowseRoot::All, BrowseRoot::Other], true) => array_keys(GenericReleaseFilters::SORTS),
                in_array($root, [BrowseRoot::Tv, BrowseRoot::Movies, BrowseRoot::Adult, BrowseRoot::Books, BrowseRoot::Games, BrowseRoot::Console, BrowseRoot::Audio], true) => array_keys(ReleaseListFilters::SORTS),
                default => [],
            })],
            'shows_sort' => ['sometimes', 'string', Rule::in($root === BrowseRoot::Tv ? array_keys(TvShowFilters::SORTS) : [])],
            'films_sort' => ['sometimes', 'string', Rule::in($root === BrowseRoot::Movies ? array_keys(MovieFilmWallFilters::SORTS) : [])],
        ];
    }

    /**
     * The home page's posted lists; null for a list the request leaves out.
     *
     * @return array{shelves: list<string>|null, ticked: list<string>|null}
     */
    public function homeShelves(): array
    {
        return [
            'shelves' => $this->has('shelves') ? array_values((array) $this->validated('shelves')) : null,
            'ticked' => $this->has('ticked') ? array_values((array) $this->validated('ticked')) : null,
        ];
    }

    /** @return array{view?: string, size?: string, per?: int, thumbs?: bool, sort?: string, shows_sort?: string, films_sort?: string} */
    public function preferences(): array
    {
        $preferences = $this->safe()->except(['root', 'shelves', 'ticked']);
        if ($this->has('per')) {
            $preferences['per'] = $this->integer('per');
        }
        if ($this->has('thumbs')) {
            $preferences['thumbs'] = $this->boolean('thumbs');
        }

        return $preferences;
    }
}
