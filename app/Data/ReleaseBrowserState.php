<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\BrowseRoot;
use App\Models\User;
use App\Support\ReleaseCompletion;
use Illuminate\Http\Request;

final readonly class ReleaseBrowserState
{
    /** @param array<string, string> $filters */
    public function __construct(
        public BrowseRoot $root,
        public string $view,
        public string $size,
        public int $per,
        public bool $thumbs,
        public int $page,
        public string $group,
        public string $posterIdentity,
        public ?int $categoryId,
        public string $query,
        public string $sort,
        public array $filters,
        public bool $watching,
        public bool $basketOnly,
        public int $minCompletion,
        public bool $tableOnly = false,
    ) {}

    /** @return list<string> */
    public function availableViews(): array
    {
        return $this->tableOnly ? ['table'] : $this->root->views();
    }

    public function hasFilters(): bool
    {
        return $this->query !== '' || $this->group !== '' || $this->posterIdentity !== '' || $this->filters !== [] || $this->watching || $this->minCompletion > 0;
    }

    /** @return array<string, mixed> */
    public function queryParameters(Request $request): array
    {
        $parameters = $request->query();
        if ($this->posterIdentity !== '') {
            $key = $request->routeIs('poster-identity') && ! $request->has('poster') ? 'name' : 'poster';
            $parameters[$key] = $this->posterIdentity;
        }

        return $parameters;
    }

    public function pageUrl(Request $request, int $page): string
    {
        return $request->url().'?'.http_build_query([...$this->queryParameters($request), 'page' => $page], '', '&', PHP_QUERY_RFC3986);
    }

    public static function fromRequest(Request $request, BrowseRoot $root, User $user, ?int $categoryId = null, bool $basketOnly = false, bool $tableOnly = false): self
    {
        $saved = $user->releaseViewPreferences($root->value);
        $view = $request->input('view', $saved['view']);
        $size = $request->input('size', $saved['size']);
        $per = $request->input('per', $saved['per']);
        $page = $request->input('page', 1);
        $group = $request->input('group', '');
        parse_str((string) $request->server('QUERY_STRING', ''), $originalQuery);
        $posterIdentity = $originalQuery['poster'] ?? ($request->routeIs('poster-identity') ? ($originalQuery['name'] ?? '') : '');
        $tableOnly = $tableOnly || (is_string($group) && $group !== '') || (is_string($posterIdentity) && $posterIdentity !== '');
        $query = $request->input('q', '');
        $sort = $request->input('sort', 'newest');
        $filters = array_filter($request->only(['year', 'genre', 'network', 'label', 'platform', 'publisher', 'author']), static fn ($value): bool => is_string($value) && $value !== '');

        return new self(
            root: $root,
            view: ! $tableOnly && in_array($view, $root->views(), true) ? $view : 'table',
            size: in_array($size, $root->coverSizes(), true) ? $size : 's',
            per: is_scalar($per) && in_array((string) $per, ['24', '48', '100'], true) ? (int) $per : 48,
            thumbs: $request->has('thumbs') ? $request->boolean('thumbs') : $saved['thumbs'],
            page: is_scalar($page) && ctype_digit((string) $page) ? max(1, (int) $page) : 1,
            group: is_string($group) ? $group : '',
            posterIdentity: is_string($posterIdentity) ? $posterIdentity : '',
            categoryId: $categoryId,
            query: is_string($query) ? $query : '',
            sort: in_array($sort, ['newest', 'title', 'year', 'rating', 'artist'], true) ? $sort : 'newest',
            filters: $filters,
            watching: in_array($root, [BrowseRoot::All, BrowseRoot::Movies, BrowseRoot::Tv], true) && $request->boolean('watching'),
            basketOnly: $basketOnly,
            minCompletion: ReleaseCompletion::normalizeThreshold($request->input(ReleaseCompletion::REQUEST_KEY)),
            tableOnly: $tableOnly,
        );
    }
}
