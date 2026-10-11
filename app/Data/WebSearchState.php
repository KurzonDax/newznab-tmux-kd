<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\BrowseRoot;
use App\Models\Category;
use App\Models\User;
use App\Support\ReleaseCompletion;
use App\Support\WebSearchQuery;
use Illuminate\Http\Request;

/**
 * A search as its address states it (issue #1050): the words and their field prefixes, the scope
 * (`t`: root or sub-category ids, comma separated), the sub-category, age, size, completion, group,
 * poster and page, with the legacy forms of these. It builds the query row's chips, the Clear all
 * and feed URLs, and what the generic list's two cells show. The old page's `per`, `thumbs`, `sort`
 * and `ob` are no longer read: the list has one page size and takes its sort from the All releases
 * list's remembered sort.
 */
final readonly class WebSearchState
{
    /** The Add filter kinds that set a movie field of the query. */
    public const array MOVIE_FIELDS = ['actors' => 'Actor', 'director' => 'Director', 'title' => 'Movie title', 'plot' => 'Movie plot'];

    /**
     * @param  array<string, string>  $parameters  the search's canonical address parameters, the page left out
     * @param  int  $page  the page asked for, 1 or more
     */
    private function __construct(public WebSearchQuery $terms, public array $parameters, public int $page = 1) {}

    public static function fromRequest(Request $request): self
    {
        $input = $request->query();
        parse_str((string) $request->server('QUERY_STRING', ''), $originalQuery);
        foreach (['poster', 'searchadvposter'] as $posterKey) {
            if (isset($originalQuery[$posterKey]) && is_string($originalQuery[$posterKey])) {
                $input[$posterKey] = $originalQuery[$posterKey];
            }
        }
        $input['q'] = self::queryInput($input);
        foreach (['minage' => 'searchadvdaysnew', 'maxage' => 'searchadvdaysold', 'minsize' => 'searchadvsizefrom', 'maxsize' => 'searchadvsizeto', 'group' => 'searchadvgroups', 'poster' => 'searchadvposter'] as $key => $legacy) {
            $input[$key] ??= $input[$legacy] ?? '';
        }
        $terms = WebSearchQuery::fromInput($input);
        // + Add filter's forms (search/filter-menu.blade.php): the kind, its value and, for a range, its maximum.
        $filter = self::scalar($input['filter'] ?? '');
        $value = self::scalar($input['filter_value'] ?? '');
        if (array_key_exists($filter, self::MOVIE_FIELDS)) {
            $fields = [...$terms->indexTerms(), 'all' => $terms->freeText()];
            $fields[$filter] = $value;
            $terms = WebSearchQuery::fromInput(['q' => self::queryText(array_filter($fields))]);
        } elseif ($filter === 'cat') {
            $input['cat'] = $value;
        } elseif (in_array($filter, ['age', 'size'], true)) {
            $input['min'.$filter] = $value;
            $input['max'.$filter] = self::scalar($input['filter_max'] ?? '');
        }
        if ($filter !== '') {
            unset($input['page']);
        }
        $parameters = ['q' => self::queryText([...$terms->indexTerms(), 'all' => $terms->freeText()])];
        foreach (['t', 'group', 'poster'] as $key) {
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
        foreach (['cat', 'minage', 'maxage', 'minsize', 'maxsize'] as $key) {
            $value = self::scalar($input[$key] ?? '');
            $pattern = in_array($key, ['minsize', 'maxsize'], true) ? '/^\d{1,9}(?:\.\d{1,3})?$/' : '/^\d{1,6}$/';
            if (preg_match($pattern, $value) === 1 && (float) $value > 0) {
                $parameters[$key] = $value;
            }
        }
        // The Completion cell's choice is explicit (Any included) and wins over today's minc link, as on All releases.
        if (array_key_exists('completion', $input)) {
            $choice = ReleaseListFilters::completionChoice(self::scalar($input['completion']));
            if ($choice !== null) {
                $parameters['completion'] = (string) $choice;
            }
        } elseif (($completion = ReleaseCompletion::normalizeThreshold($input['minc'] ?? null)) > 0) {
            $parameters['minc'] = (string) $completion;
        }
        $parameters = array_filter($parameters, static fn (string $value): bool => $value !== '');
        $page = self::scalar($input['page'] ?? '');
        // The header's search form reads the canonical words and scope from the request.
        $request->query->replace($parameters);

        return new self($terms, $parameters, ctype_digit($page) ? max(1, (int) $page) : 1);
    }

    /**
     * The words of a search address, for the heading a release page names its search by
     * (GenericListContext::fromReferer()).
     *
     * @param  array<array-key, mixed>  $query  the address's query
     */
    public static function wordsOf(array $query): string
    {
        return WebSearchQuery::fromInput([...$query, 'q' => self::queryInput($query)])->freeText();
    }

    /** The words: the query without its field prefixes; '' for a search with none. */
    public function words(): string
    {
        return $this->terms->freeText();
    }

    /** The Completion cell's choice: a listed threshold, Imported only, or null for Any completion. */
    public function completion(): int|string|null
    {
        return ReleaseListFilters::completionChoice($this->parameters['completion'] ?? null);
    }

    /** Today's `minc` link, honoured while the Completion cell is not set; 0 for none. */
    public function legacyThreshold(): int
    {
        return isset($this->parameters['minc']) ? (int) $this->parameters['minc'] : 0;
    }

    /** The completion threshold applied: the cell's, else the minc link's; null for none and for Imported only. */
    public function threshold(): ?int
    {
        $completion = $this->completion();
        if ($completion !== null) {
            return is_int($completion) ? $completion : null;
        }

        return $this->legacyThreshold() > 0 ? $this->legacyThreshold() : null;
    }

    public function importedOnly(): bool
    {
        return $this->completion() === ReleaseListFilters::IMPORTED_ONLY;
    }

    /**
     * The ids the scope names, each once in the order written: roots and sub-categories.
     *
     * @return list<int>
     */
    public function scopeIds(): array
    {
        return array_values(array_unique(array_map('intval', array_filter(explode(',', $this->parameters['t'] ?? ''), ctype_digit(...)))));
    }

    /**
     * The root ids the scope names itself, never the root of a sub-category it names.
     *
     * @return list<int>
     */
    public function scopeRoots(): array
    {
        return array_values(array_filter($this->scopeIds(), static fn (int $id): bool => in_array($id, self::rootIds(), true)));
    }

    /**
     * A legacy scope: it names a sub-category. The Category cell then reads "Custom scope", the
     * `Scope: …` chip names the whole scope, and the exact scope is kept until roots are chosen.
     */
    public function customScope(): bool
    {
        return $this->scopeIds() !== $this->scopeRoots();
    }

    /**
     * What the Category cell ticks: the scope's own roots that the menu lists. A scope of every
     * listed root but Other is the Exclude Other mode, as on All releases; a legacy scope never is.
     *
     * @param  list<int>  $menuRoots  the roots the cell lists, in menu order
     * @return array{categories: list<int>, excludeOther: bool}
     */
    public function scopeCell(array $menuRoots): array
    {
        $ticked = array_values(array_intersect($menuRoots, $this->scopeRoots()));
        $other = GenericReleaseFilters::excludableRoot($menuRoots);
        $allButOther = $other === null ? [] : array_values(array_diff($menuRoots, [$other]));

        return ['categories' => $ticked, 'excludeOther' => $other !== null && ! $this->customScope() && $ticked === $allButOther];
    }

    /**
     * What the header's scope picker shows and what its search form sends: the scope's root when
     * it is exactly one root the picker lists, otherwise All; a legacy scope is sent as written
     * while the picker reads All, so a new search keeps it until a scope is picked.
     *
     * @param  list<int>  $menuRoots  the roots the picker lists
     * @return array{choice: string, value: string} the picker's option ('0' is All) and the form's `t`
     */
    public function headerScope(array $menuRoots): array
    {
        if ($this->customScope()) {
            return ['choice' => '0', 'value' => $this->parameters['t']];
        }
        $roots = $this->scopeRoots();
        $choice = count($roots) === 1 && in_array($roots[0], $menuRoots, true) ? (string) $roots[0] : '0';

        return ['choice' => $choice, 'value' => $choice];
    }

    /**
     * The sub-categories the scope admits, for + Add filter's Sub-category list: every one the
     * viewer may see while no scope is set, else those of the scope's roots and the sub-categories
     * it names. Roots keep their given order.
     *
     * @param  array<int, array{label: string, categories: array<int, string>}>  $roots  root id => its label and sub-category titles by id
     * @return array<int, string> sub-category id => "Root > Sub"
     */
    public function admittedSubCategories(array $roots): array
    {
        $scope = $this->scopeIds();
        $admitted = [];
        foreach ($roots as $rootId => $root) {
            foreach ($root['categories'] as $id => $title) {
                if ($scope === [] || in_array($rootId, $scope, true) || in_array($id, $scope, true)) {
                    $admitted[$id] = $root['label'].' > '.$title;
                }
            }
        }

        return $admitted;
    }

    /**
     * Whether + Add filter offers the four movie fields: the scope is unset or admits a Movies category.
     *
     * @param  array<int, array{label: string, categories: array<int, string>}>  $roots
     */
    public function admitsMovies(array $roots): bool
    {
        $scope = $this->scopeIds();

        return $scope === [] || in_array(Category::MOVIE_ROOT, $scope, true)
            || array_intersect($scope, array_keys($roots[Category::MOVIE_ROOT]['categories'] ?? [])) !== [];
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

    /**
     * The filters the feed cannot carry, as the dialog names them: their chips, then the
     * completion threshold or Imported only.
     *
     * @return list<string>
     */
    public function websiteOnlyFilters(): array
    {
        $filters = array_values(array_map(static fn (array $chip): string => $chip['label'], array_filter($this->chips(),
            static fn (array $chip): bool => in_array($chip['key'], ['title', 'actors', 'director', 'plot', 'minage', 'maxsize', 'poster'], true))));
        if ($this->importedOnly()) {
            $filters[] = ReleaseListFilters::COMPLETIONS[ReleaseListFilters::IMPORTED_ONLY][0];
        } elseif ($this->threshold() !== null) {
            $filters[] = 'Completion ≥ '.$this->threshold().'%';
        }

        return $filters;
    }

    /** Clear all: the words, every chip and both cells. */
    public function clearUrl(): string
    {
        return route('search');
    }

    /**
     * The query row's chips, one per constraint the two cells do not show: the words, the movie
     * fields, a legacy scope (one that names a sub-category; a scope of roots is the Category
     * cell's), the sub-category, age, size, group and poster. Completion has no chip.
     *
     * @return list<array{key:string, label:string, url:string}>
     */
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
        if ($this->customScope()) {
            $labels = array_map(static function (int $id): string {
                $root = BrowseRoot::fromCategoryId($id);

                return $root->categoryId() === $id ? $root->label() : ($root->label().' · '.(Category::query()->whereKey($id)->value('title') ?? $id));
            }, $this->scopeIds());
            $chips[] = ['key' => 't', 'label' => 'Scope: '.implode(', ', $labels), 'url' => $this->url(['t' => null])];
        }

        foreach (['cat' => 'Category: ', 'minage' => 'Age ≥ ', 'maxage' => 'Age ≤ ', 'minsize' => 'Size ≥ ', 'maxsize' => 'Size ≤ ', 'group' => 'Group: ', 'poster' => 'Poster: '] as $key => $label) {
            if (! isset($this->parameters[$key])) {
                continue;
            }
            $value = $this->parameters[$key];
            if ($key === 'cat') {
                $value = BrowseRoot::fromCategoryId((int) $value)->label().' > '.(Category::query()->whereKey($value)->value('title') ?? $value);
            }
            $suffix = match ($key) {
                'minage', 'maxage' => ' d', 'minsize', 'maxsize' => ' MB', default => '',
            };
            $chips[] = ['key' => $key, 'label' => $label.$value.$suffix, 'url' => $this->url([$key => null])];
        }

        return $chips;
    }

    /**
     * The search with some parameters changed, on page 1 unless a page is among them.
     *
     * @param  array<string, string|null>  $changes
     */
    public function url(array $changes = []): string
    {
        return route('search', array_filter([...$this->parameters, 'page' => null, ...$changes], static fn ($value): bool => $value !== null && $value !== ''));
    }

    /**
     * The words as a search address gives them: `q`, or without one the legacy keys.
     *
     * @param  array<array-key, mixed>  $input
     */
    private static function queryInput(array $input): string
    {
        return self::scalar($input['q'] ?? (array_key_exists('q', $input) ? '' : ($input['search'] ?? $input['subject'] ?? $input['id'] ?? $input['searchadvr'] ?? '')));
    }

    /** @return list<int> the root categories' ids */
    private static function rootIds(): array
    {
        return array_values(array_filter(array_map(static fn (BrowseRoot $root): ?int => $root->categoryId(), BrowseRoot::cases()), is_int(...)));
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
