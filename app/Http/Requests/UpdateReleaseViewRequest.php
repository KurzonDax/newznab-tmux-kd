<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\TvReleaseFilters;
use App\Data\TvShowFilters;
use App\Enums\BrowseRoot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateReleaseViewRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $value = $this->input('root');
        $root = is_string($value) ? BrowseRoot::tryFrom($value) : null;

        return [
            'root' => ['required', Rule::enum(BrowseRoot::class)],
            'view' => ['sometimes', 'string', Rule::in($root?->views() ?? ['table'])],
            'size' => ['sometimes', 'string', Rule::in($root?->coverSizes() ?? ['s'])],
            'per' => ['sometimes', 'integer', 'in:24,48,100'],
            'thumbs' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'string', Rule::in($root === BrowseRoot::Tv ? array_keys(TvReleaseFilters::SORTS) : [])],
            'shows_sort' => ['sometimes', 'string', Rule::in($root === BrowseRoot::Tv ? array_keys(TvShowFilters::SORTS) : [])],
        ];
    }

    /** @return array{view?: string, size?: string, per?: int, thumbs?: bool, sort?: string, shows_sort?: string} */
    public function preferences(): array
    {
        $preferences = $this->safe()->except('root');
        if ($this->has('per')) {
            $preferences['per'] = $this->integer('per');
        }
        if ($this->has('thumbs')) {
            $preferences['thumbs'] = $this->boolean('thumbs');
        }

        return $preferences;
    }
}
