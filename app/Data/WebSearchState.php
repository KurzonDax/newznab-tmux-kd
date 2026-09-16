<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\BrowseRoot;
use App\Enums\ReleaseSort;
use App\Models\Category;
use App\Models\User;
use App\Support\ReleaseCompletion;
use App\Support\WebSearchQuery;
use Illuminate\Http\Request;

final readonly class WebSearchState
{
    /** @param array<string, string> $parameters */
    private function __construct(public ReleaseBrowserState $browser, public WebSearchQuery $terms, public array $parameters) {}

    public static function fromRequest(Request $request, User $user): self
    {
        $input = $request->query();
        parse_str((string) $request->server('QUERY_STRING', ''), $originalQuery);
        foreach (['poster', 'searchadvposter'] as $posterKey) {
            if (isset($originalQuery[$posterKey]) && is_string($originalQuery[$posterKey])) {
                $input[$posterKey] = $originalQuery[$posterKey];
            }
        }
        $input['q'] = self::scalar($input['q'] ?? ($request->has('q') ? '' : ($input['search'] ?? $input['subject'] ?? $input['id'] ?? $input['searchadvr'] ?? '')));
        foreach (['minage' => 'searchadvdaysnew', 'maxage' => 'searchadvdaysold', 'minsize' => 'searchadvsizefrom', 'maxsize' => 'searchadvsizeto', 'group' => 'searchadvgroups', 'poster' => 'searchadvposter'] as $key => $legacy) {
            $input[$key] ??= $input[$legacy] ?? '';
        }
        $terms = WebSearchQuery::fromInput($input);
        $filter = self::scalar($input['filter'] ?? '');
        $value = self::scalar($input['filter_value'] ?? '');
        if (in_array($filter, ['actors', 'director', 'title', 'plot'], true)) {
            $fields = [...$terms->indexTerms(), 'all' => $terms->freeText()];
            $fields[$filter] = $value;
            $terms = WebSearchQuery::fromInput(['q' => self::queryText(array_filter($fields))]);
        } elseif (in_array($filter, ['cat', 'minc'], true)) {
            $input[$filter] = $value;
        } elseif (in_array($filter, ['age', 'size'], true)) {
            $input['min'.$filter] = $value;
            $input['max'.$filter] = self::scalar($input['filter_max'] ?? '');
        }
        if ($filter !== '') {
            unset($input['page']);
        }
        $parameters = ['q' => self::queryText([...$terms->indexTerms(), 'all' => $terms->freeText()])];
        foreach (['t', 'per', 'page', 'thumbs', 'sort', 'group', 'poster'] as $key) {
            if (($value = self::scalar($input[$key] ?? '')) !== '') {
                $parameters[$key] = $key === 'poster' ? (string) $input[$key] : $value;
            }
        }
        $scope = BrowseRoot::fromRoute($parameters['t'] ?? '');
        if ($scope !== null) {
            $parameters['t'] = (string) ($scope->categoryId() ?? 0);
        }
        $parameters['t'] ??= self::scalar($input['searchadvcat'] ?? '0');
        if (in_array($parameters['t'], ['', '0', '-1'], true)) {
            unset($parameters['t']);
        }
        $root = isset($parameters['t']) && ctype_digit($parameters['t']) ? BrowseRoot::fromCategoryId((int) $parameters['t']) : BrowseRoot::All;
        foreach (['cat', 'minage', 'maxage', 'minsize', 'maxsize'] as $key) {
            $value = self::scalar($input[$key] ?? '');
            $pattern = in_array($key, ['minsize', 'maxsize'], true) ? '/^\d{1,9}(?:\.\d{1,3})?$/' : '/^\d{1,6}$/';
            if (preg_match($pattern, $value) === 1 && (float) $value > 0) {
                $parameters[$key] = $value;
            }
        }
        if (($completion = ReleaseCompletion::normalizeThreshold($input['minc'] ?? null)) > 0) {
            $parameters['minc'] = (string) $completion;
        }
        $parameters['sort'] = ReleaseSort::resolve($parameters['sort'] ?? (($input['ob'] ?? '') === 'name_asc' ? 'title' : 'newest'))->value;
        $request->query->replace($parameters);
        $browser = ReleaseBrowserState::fromRequest($request, $root, $user, tableOnly: true);

        return new self($browser, $terms, $parameters);
    }

    public function suggestionUrl(string $suggestion): string
    {
        return $this->url(['q' => self::queryText([...$this->terms->indexTerms(), 'all' => $suggestion])]);
    }

    public function rssUrl(User $user): string
    {
        $parameters = ['t' => 'search', 'apikey' => $user->api_token, 'o' => 'xml'];
        if ($this->terms->freeText() !== '') {
            $parameters['q'] = $this->terms->freeText();
        }
        if (isset($this->parameters['cat']) || isset($this->parameters['t'])) {
            $parameters['cat'] = $this->parameters['cat'] ?? $this->parameters['t'];
        }
        foreach (['maxage', 'group'] as $key) {
            if (isset($this->parameters[$key])) {
                $parameters[$key] = $this->parameters[$key];
            }
        }
        if (isset($this->parameters['minsize'])) {
            $parameters['minsize'] = (string) (int) round((float) $this->parameters['minsize'] * 1024 * 1024);
        }

        return url('/api').'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return list<string> */
    public function websiteOnlyFilters(): array
    {
        return array_values(array_map(static fn (array $chip): string => $chip['label'], array_filter($this->chips(),
            static fn (array $chip): bool => in_array($chip['key'], ['title', 'actors', 'director', 'plot', 'minage', 'maxsize', 'minc', 'poster'], true))));
    }

    public function clearUrl(): string
    {
        return route('search', array_intersect_key($this->parameters, array_flip(['per', 'thumbs', 'sort'])));
    }

    /** @return list<array{key:string, label:string, url:string}> */
    public function chips(): array
    {
        $chips = [];
        $fields = array_filter([...$this->terms->indexTerms(), 'all' => $this->terms->freeText()]);
        foreach ($fields as $field => $value) {
            $remaining = $fields;
            unset($remaining[$field]);
            $chips[] = ['key' => $field, 'label' => $field === 'all' ? '“'.$value.'”' : ($field === 'actors' ? 'actor' : $field).': '.trim($value, '"'),
                'url' => $this->url(['q' => self::queryText($remaining)])];
        }
        if (isset($this->parameters['t'])) {
            $ids = array_filter(explode(',', $this->parameters['t']), ctype_digit(...));
            $labels = array_map(static function (string $id): string {
                $root = BrowseRoot::fromCategoryId((int) $id);

                return $root->categoryId() === (int) $id ? $root->label() : ($root->label().' · '.(Category::query()->whereKey($id)->value('title') ?? $id));
            }, $ids);
            $chips[] = ['key' => 't', 'label' => 'Scope: '.implode(', ', $labels), 'url' => $this->url(['t' => null])];
        }

        foreach (['cat' => 'Category: ', 'minage' => 'Age ≥ ', 'maxage' => 'Age ≤ ', 'minsize' => 'Size ≥ ', 'maxsize' => 'Size ≤ ', 'minc' => 'Completion ≥ ', 'group' => 'Group: ', 'poster' => 'Poster: '] as $key => $label) {
            if (! isset($this->parameters[$key])) {
                continue;
            }
            $value = $this->parameters[$key];
            if ($key === 'cat') {
                $value = BrowseRoot::fromCategoryId((int) $value)->label().' · '.(Category::query()->whereKey($value)->value('title') ?? $value);
            }
            $suffix = match ($key) {
                'minage', 'maxage' => ' d', 'minsize', 'maxsize' => ' MB', 'minc' => '%', default => '',
            };
            $chips[] = ['key' => $key, 'label' => $label.$value.$suffix, 'url' => $this->url([$key => null])];
        }

        return $chips;
    }

    /** @param array<string, string|null> $changes */
    public function url(array $changes = []): string
    {
        return route('search', array_filter([...$this->parameters, 'page' => null, ...$changes], static fn ($value): bool => $value !== null && $value !== ''));
    }

    /** @param array<string, string> $fields */
    private static function queryText(array $fields): string
    {
        $query = $fields['all'] ?? '';
        foreach ($fields as $field => $value) {
            if ($field !== 'all') {
                $expression = ! preg_match('/\s/', $value) || preg_match('/^[!-]?(?:"[^"]*"|(\((?:[^()]|(?1))*\)))$/u', $value) ? $value : '('.$value.')';
                $query .= ' '.($field === 'actors' ? 'actor' : $field).':'.$expression;
            }
        }

        return trim($query);
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
