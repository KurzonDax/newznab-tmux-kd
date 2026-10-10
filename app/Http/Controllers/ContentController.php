<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\GenericListContext;
use App\Enums\HomeShelf;
use App\Enums\UserRole;
use App\Models\Content;
use App\Services\Releases\HomeShelfPreferences;
use App\Services\Releases\HomeShelves;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContentController extends BasePageController
{
    /**
     * Display content page(s).
     *
     * @return JsonResponse|View
     *
     * @throws \Exception
     */
    public function show(Request $request)
    {
        $role = $this->contentRole();

        /* The role column in the content table values are:
         * 1 = logged in users
         * 2 = admins
         *
         * The user role values are:
         * 1 = user
         * 2 = admin
         * 3 = disabled
         * 4 = moderator
         *
         * Admins and mods should be the only ones to see admin content.
         */
        $isAdmin = \in_array($role, [UserRole::ADMIN->value, UserRole::MODERATOR->value], true);

        $contentId = $this->integerInput($request, 'id');
        $contentPage = $this->scalarInput($request, 'page');

        if ($contentId === 0 && $contentPage === 'content') {
            // Show all content except front page
            $content = $this->getAllButFront()->all();
            $isFront = false;
            $meta_title = 'Contents page';
            $meta_keywords = 'contents';
            $meta_description = 'This is the contents page.';
        } elseif ($contentId !== 0 && $contentPage !== '') {
            // Show specific content by ID
            $contentItem = $this->getContentById($contentId, $role);
            $content = $contentItem ? [$contentItem] : [];
            $isFront = false;
            $meta_title = 'Contents page';
            $meta_keywords = 'contents';
            $meta_description = 'This is the contents page.';
        } else {
            // Show front page content
            $content = $this->getFrontPageContent()->all();
            $index = $this->getIndexContent();
            $isFront = true;
            $meta_title = filled($index?->title) ? $index->title : 'Contents page';
            $meta_keywords = $index->metakeywords ?? 'contents';
            $meta_description = $index->metadescription ?? 'This is the contents page.';
        }

        if (empty($content) && ! $isFront) {
            return response()->json(['message' => 'There is nothing to see here, no content provided.'], 404);
        }

        $this->viewData = array_merge($this->viewData, [
            'content' => $content,
            'admin' => $isAdmin,
            'front' => $isFront,
            'meta_title' => $meta_title,
            'meta_keywords' => $meta_keywords,
            'meta_description' => $meta_description,
        ]);

        if ($isFront) {
            return $this->home($request);
        }

        return view('content.index', $this->viewData);
    }

    /**
     * The home page (docs/proposals/home-redesign/SPEC.md): the user's ticked shelves in the user's
     * order, with the admin's front-page content under them. Two fragments of the same address, in
     * the lists' `?_fragment=` convention: `shelves`, the shelves drawn again after the dialog saved
     * a change, and `panel` (with `shelf`, `kind` and `id`), the releases of one opened tile. Only
     * the full page counts as a visit (SPEC 3.4).
     */
    private function home(Request $request): View
    {
        $user = $this->userdata;
        $exclusions = array_values(array_map('intval', (array) $user->categoryexclusions));
        $preferences = app(HomeShelfPreferences::class);
        $viewable = $preferences->viewable($user);
        $fragment = $request->query('_fragment');
        if ($fragment === 'panel') {
            $shelf = HomeShelf::tryFrom($this->scalarInput($request, 'shelf'));
            $panel = $shelf === null ? null : app(HomeShelves::class)
                ->panel($shelf, $this->scalarInput($request, 'kind'), $this->integerInput($request, 'id'), $viewable, $user, $exclusions);
            abort_if($shelf === null || $panel === null, 404);

            // the Category cell: "Root > Sub" in Following, the sub-category alone inside one section (the generic row's two forms)
            return view('home.panel', ['panel' => $panel, 'mixed' => $shelf === HomeShelf::Following,
                'context' => $shelf === HomeShelf::Following ? GenericListContext::all() : GenericListContext::other()]);
        }
        $stored = HomeShelfPreferences::read($user->view_prefs);
        // only a full-page GET is a visit: a fragment, or a POST to the address, writes neither time
        $lastVisit = $fragment === null && $request->isMethod('GET') ? $preferences->visit((int) $user->id) : $stored['lastVisit'];
        $listed = array_values(array_filter($stored['order'], static fn (HomeShelf $shelf): bool => in_array($shelf, $viewable, true)));
        $shown = array_values(array_filter($listed, static fn (HomeShelf $shelf): bool => in_array($shelf, $stored['ticked'], true)));
        $data = ['shelves' => app(HomeShelves::class)->shelves($shown, $viewable, $user, $exclusions, $lastVisit)];
        if ($fragment === 'shelves') {
            return view('home.shelves', $data);
        }

        return view('content.home', [...$this->viewData, ...$data,
            'shelfRows' => array_map(static fn (HomeShelf $shelf): array => ['shelf' => $shelf, 'ticked' => in_array($shelf, $stored['ticked'], true)], $listed),
            'nzbLinkBase' => url('/api/v1/api'),
            'apiToken' => (string) $user->api_token,
        ]);
    }

    private function contentRole(): int
    {
        if (! isset($this->userdata)) {
            return Content::ROLE_EVERYONE;
        }

        if ($this->userdata->hasRole('Admin')) {
            return UserRole::ADMIN->value;
        }

        if ($this->userdata->hasRole('Moderator')) {
            return UserRole::MODERATOR->value;
        }

        return Content::ROLE_LOGGED_IN;
    }

    /**
     * Get all active content ordered by type and ordinal.
     *
     * @return Collection<int, mixed>
     */
    protected function getActiveContent(): Collection
    {
        return Content::active()->ordered()->get();
    }

    /**
     * Get all content except the front page.
     *
     * @return Collection<int, mixed>
     */
    protected function getAllButFront(): Collection
    {
        return Content::query()
            ->where('id', '<>', 1)
            ->ordered()
            ->get();
    }

    /**
     * Get content by ID with role-based access control.
     */
    protected function getContentById(int $id, int $role): ?Content
    {
        return Content::query()
            ->where('id', $id)
            ->forRole($role)
            ->first();
    }

    /**
     * Get front page content.
     *
     * @return Collection<int, mixed>
     */
    protected function getFrontPageContent(): Collection
    {
        return Content::frontPage()->get();
    }

    /**
     * Get index content metadata.
     */
    protected function getIndexContent(): ?Content
    {
        return Content::active()
            ->ofType(Content::TYPE_INDEX)
            ->ordered()
            ->first();
    }
}
