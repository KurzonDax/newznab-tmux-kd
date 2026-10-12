<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Releases\HiddenCategoryGate;
use App\Support\SiteViewSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class BasePageController extends Controller
{
    /**
     * @var Collection<string, mixed>
     */
    public Collection $settings;

    public User $userdata;

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
        $this->middleware(['auth', 'web', '2fa'])->except('api', 'contact', 'showContactForm', 'btcPayCallback', 'getNzb', 'terms', 'privacyPolicy', 'capabilities', 'movie', 'apiSearch', 'tv', 'details', 'failed', 'showRssDesc', 'fullFeedRss', 'categoryFeedRss', 'cartRss', 'myMoviesRss', 'myShowsRss', 'trendingMoviesRss', 'trendingShowsRss', 'showStatusPage', 'showInvitation');

        $settings = app(SiteViewSettings::class);
        $this->settings = $settings->raw();
        $this->viewData = [
            'site' => $settings->converted(),
        ];

        // Initialize userdata property for controllers that need it
        $this->middleware(function ($request, $next) {
            if (Auth::check()) {
                $userId = Auth::id();
                $this->userdata = User::find($userId);
                if (! $this->userdata?->hasVerifiedEmail()) {
                    return $next($request);
                }

                // Category exclusions per user, cached for 5 minutes
                $this->userdata->categoryexclusions = app(HiddenCategoryGate::class)->hiddenCategoryIds((int) $userId);
            }

            return $next($request);
        });
    }

    /**
     * Whether the signed-in user has hidden this release category.
     */
    protected function hidesCategory(mixed $categoryId): bool
    {
        return in_array((int) $categoryId, (array) ($this->userdata->categoryexclusions ?? []), true);
    }

    /**
     * The category-disabled page, status 403, for a release in a hidden category.
     */
    protected function hiddenCategoryPage(int $categoryId): Response
    {
        return app(HiddenCategoryGate::class)->deniedPage($this->userdata, $categoryId);
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
     * Check if request is POST.
     */
    public function isPostBack(Request $request): bool
    {
        return $request->isMethod('POST');
    }

    /**
     * Output the page using Blade views.
     */
    public function pagerender(): View
    {
        return view('layouts.main', $this->viewData);
    }
}
