<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Settings;
use App\Models\User;
use App\Support\ReleaseCompletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class BasePageController extends Controller
{
    /**
     * @var Collection<int, mixed>
     */
    public Collection $settings;

    public string $title = '';

    public string $content = '';

    public string $meta_keywords = '';

    public string $meta_title = '';

    public string $meta_description = '';

    /**
     * Current page the user is browsing. ie browse.
     */
    public string $page = '';

    public User $userdata;

    /**
     * User's theme.
     */
    protected string $theme = 'Gentele';

    /**
     * View data array for Blade templates
     *
     * @var array<string, mixed>
     */
    protected array $viewData = [];

    /**
     * BasePageController constructor.
     *
     * @throws \Exception
     */
    public function __construct()
    {
        $this->middleware(['auth', 'web', '2fa'])->except('api', 'contact', 'showContactForm', 'callback', 'btcPayCallback', 'getNzb', 'terms', 'privacyPolicy', 'capabilities', 'movie', 'apiSearch', 'tv', 'details', 'failed', 'showRssDesc', 'fullFeedRss', 'categoryFeedRss', 'cartRss', 'myMoviesRss', 'myShowsRss', 'trendingMoviesRss', 'trendingShowsRss', 'release', 'reset', 'showLinkRequestForm', 'showStatusPage');

        // Load settings as collection with caching (5 minutes)
        $this->settings = $this->rememberWithCacheFallback('site_settings', 300, function () {
            return Settings::query()->pluck('value', 'name');
        });

        // Initialize view data FIRST with serverroot
        $this->viewData = [
            'serverroot' => url('/'),
        ];

        // Then add the converted settings array as 'site' with caching
        $this->viewData['site'] = $this->rememberWithCacheFallback('site_settings_converted', 300, function () {
            return $this->settings->map(function ($value) {
                return Settings::convertValue($value);
            })->all();
        });

        // Initialize userdata property for controllers that need it
        $this->middleware(function ($request, $next) {
            if (Auth::check()) {
                $userId = Auth::id();
                $this->userdata = User::find($userId);
                if (! $this->userdata?->hasVerifiedEmail()) {
                    return $next($request);
                }

                // Cache category exclusions per user (5 minutes)
                $this->userdata->categoryexclusions = $this->rememberWithCacheFallback(
                    User::categoryExclusionCacheKey($userId),
                    300,
                    fn () => User::getCategoryExclusionById($userId)
                );
            }

            return $next($request);
        });
    }

    /**
     * Use the cache when available; if the store is unreachable (e.g. Redis down), run the callback directly.
     *
     * @param  callable(): mixed  $callback
     */
    private function rememberWithCacheFallback(string $key, int|\DateInterval $ttl, callable $callback): mixed
    {
        try {
            return Cache::remember($key, $ttl, $callback);
        } catch (\Throwable $e) {
            if (config('app.debug')) {
                Log::debug('BasePageController cache bypassed: '.$e->getMessage());
            }

            return $callback();
        }
    }

    /**
     * @return LengthAwarePaginator<int, mixed>
     */
    public function paginate(mixed $query, mixed $totalCount, mixed $items, mixed $page, mixed $path, mixed $reqQuery): LengthAwarePaginator
    {
        return new LengthAwarePaginator($query, $totalCount, $items, $page, ['path' => $path, 'query' => $reqQuery]);
    }

    protected function resolvePage(Request $request, string $key = 'page'): int
    {
        $page = $request->input($key, 1);
        if (! is_scalar($page)) {
            return 1;
        }

        $page = (string) $page;
        if ($page === '' || preg_match('/^\d+$/', $page) !== 1) {
            return 1;
        }

        return max(1, (int) $page);
    }

    /**
     * @param  array<int, string>  $ordering
     */
    protected function resolveOrderBy(Request $request, array $ordering, string $key = 'ob'): string
    {
        $orderByInput = $request->input($key, '');
        $orderBy = is_scalar($orderByInput) ? (string) $orderByInput : '';

        return \in_array($orderBy, $ordering, true) ? $orderBy : '';
    }

    protected function paginationOffset(int $page, int $perPage): int
    {
        return ($page - 1) * $perPage;
    }

    /**
     * The minimum-completion threshold the browse/search toolbar asked for.
     *
     * Anything outside the offered menu falls back to "All releases", so a
     * hand-edited URL cannot invent a threshold the UI cannot show back.
     */
    protected function resolveMinCompletion(Request $request): int
    {
        return ReleaseCompletion::normalizeThreshold($request->input(ReleaseCompletion::REQUEST_KEY));
    }

    /**
     * @param  array<int, string>  $ordering
     * @return array<string, string>
     */
    protected function buildOrderByUrls(array $ordering, string $basePath, string $prefix = 'orderby'): array
    {
        $orderByUrls = [];
        foreach ($ordering as $orderType) {
            $orderByUrls[$prefix.$orderType] = url($basePath.'?ob='.$orderType);
        }

        return $orderByUrls;
    }

    protected function scalarInput(Request $request, string $key, string $default = ''): string
    {
        $value = $request->input($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    protected function integerInput(Request $request, string $key, int $default = 0): int
    {
        $value = $this->scalarInput($request, $key, (string) $default);

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }

    /**
     * An optional integer from the request: absent, blank or non-numeric all read as null.
     *
     * Request input is always a string, so a raw `$request->input()` handed to a typed `?int`
     * service parameter is a TypeError under `declare(strict_types=1)`.
     */
    protected function nullableIntegerInput(Request $request, string $key): ?int
    {
        $value = $this->scalarInput($request, $key);

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * A model attribute as it is stored, read past any cast.
     *
     * A form that leaves a field alone has to write the stored value back. Reading it through
     * the model would hand back the cast value instead: GamesInfo casts `releasedate` to a
     * date, which truncates the time, so a submission that touched nothing would still rewrite
     * a stored 13:45:00 to 00:00:00.
     */
    protected function storedAttribute(Model $model, string $key): ?string
    {
        $stored = $model->getRawOriginal($key);

        return $stored === null ? null : (string) $stored;
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function arrayInput(Request $request, string $key): array
    {
        $value = $request->input($key, []);

        return \is_array($value) ? $value : [];
    }

    protected function localReturnUrl(Request $request, string $fallback, string $key = 'from'): string
    {
        $from = $this->scalarInput($request, $key);
        if ($from === '') {
            return url($fallback);
        }

        if (filter_var($from, FILTER_VALIDATE_URL) !== false) {
            return parse_url($from, PHP_URL_HOST) === $request->getHost() ? $from : url($fallback);
        }

        return url($from);
    }

    /**
     * Check if request is POST.
     */
    public function isPostBack(Request $request): bool
    {
        return $request->isMethod('POST');
    }

    /**
     * Show 404 page.
     *
     * @param  string|null  $message
     */
    public function show404($message = null): View
    {
        if ($message !== null) {
            return view('errors.404')->with('Message', $message);
        }

        return view('errors.404');
    }

    /**
     *  Set admin preferences.
     */
    public function setAdminPrefs(): void
    {
        $this->viewData['catClass'] = Category::class;
    }

    /**
     * Output the page using Blade views.
     */
    public function pagerender(): View
    {
        return view('layouts.main', $this->viewData);
    }

    /**
     * Output a page using the admin template.
     *
     * @throws \Exception
     */
    public function adminrender(): View
    {
        return view('layouts.admin', $this->viewData);
    }

    /**
     * @throws \Exception
     */
    public function adminBasePage(): View
    {
        $this->setAdminPrefs();
        $this->viewData = array_merge($this->viewData, [
            'meta_title' => 'Admin Home',
            'meta_description' => 'Admin home page',
        ]);

        return $this->adminrender();
    }
}
