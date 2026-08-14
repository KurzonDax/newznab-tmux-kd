<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\AdminReleaseListRequest;
use App\Models\Category;
use App\Models\Release;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\View\View;

class AdminReleasesController extends BasePageController
{
    private ReleaseManagementService $releaseManagement;

    public function __construct(ReleaseManagementService $releaseManagement)
    {
        parent::__construct();
        $this->releaseManagement = $releaseManagement;
    }

    /**
     * @throws \Exception
     */
    public function index(AdminReleaseListRequest $request): mixed
    {
        $this->setAdminPrefs();

        $meta_title = $title = 'Release List';

        $page = (int) $request->input('page', 1);
        $search = $request->searchTerm();
        $categoryId = $request->categoryId();

        $releaseList = Release::getReleasesRange($page, $search, $categoryId);
        $releaseList->appends($request->only(['search', 'category_id']));

        return view('admin.releases.index', [
            'releaselist' => $releaseList,
            'catlist' => Category::getForSelect(true),
            'title' => $title,
            'meta_title' => $meta_title,
        ]);
    }

    public function bulkCategory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'guids' => 'required|array|min:1',
            'guids.*' => 'string',
            'categories_id' => 'required|integer|min:1|exists:categories,id',
        ]);

        $count = $this->releaseManagement->bulkUpdateCategory(
            $validated['guids'],
            (int) $validated['categories_id'],
        );

        return back()->with('success', $count.' release(s) re-categorised.');
    }

    /**
     * @return \Illuminate\Contracts\Foundation\Application|Application|RedirectResponse|Redirector|View
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {
        // Set the current action.
        $action = ($request->input('action') ?? 'view');

        switch ($action) {
            case 'submit':
                $validated = $request->validate([
                    'id' => 'required|integer|min:1|exists:releases,id',
                    'guid' => 'required|string',
                    'name' => 'required|string|max:255',
                    'searchname' => 'required|string|max:255',
                    'fromname' => 'nullable|string|max:255',
                    'category' => 'required|integer|min:1|exists:categories,id',
                    'totalpart' => 'nullable|integer|min:0',
                    'grabs' => 'nullable|integer|min:0',
                    'size' => 'nullable|integer|min:0',
                    'postdate' => 'nullable|date',
                    'adddate' => 'nullable|date',
                    'videos_id' => 'nullable|integer|min:0',
                    'tv_episodes_id' => 'nullable|integer|min:0',
                    'imdbid' => 'nullable|string|max:100',
                    'anidbid' => 'nullable|integer|min:0',
                ]);

                Release::updateRelease(
                    $validated['id'],
                    $validated['name'],
                    $validated['searchname'],
                    $validated['fromname'] ?? null,
                    $validated['category'],
                    $validated['totalpart'] ?? null,
                    $validated['grabs'] ?? null,
                    $validated['size'] ?? null,
                    $validated['postdate'] ?? null,
                    $validated['adddate'] ?? null,
                    $validated['videos_id'] ?? null,
                    $validated['tv_episodes_id'] ?? null,
                    $validated['imdbid'] ?? null,
                    $validated['anidbid'] ?? null
                );

                app(PreviewGenerationPolicy::class)->restoreOwedPreviews([(int) $validated['id']]);

                return redirect('details/'.$validated['guid'])->with('success', 'Release updated successfully');

            case 'view':
            default:
                $id = $request->input('id');
                $release = Release::getByGuid($id);
                break;
        }

        $yesno_ids = [1, 0];
        $yesno_names = ['Yes', 'No'];
        $catlist = Category::getForSelect(false);

        return view('admin.releases.edit', [
            'release' => $release,
            'yesno_ids' => $yesno_ids,
            'yesno_names' => $yesno_names,
            'catlist' => $catlist,
            'title' => 'Release Edit',
            'meta_title' => 'Release Edit',
        ]);
    }

    public function destroy(mixed $id): mixed
    {
        try {
            if ($id) {
                $this->releaseManagement->deleteMultiple($id);
                Release::clearAdminReleasesRangeCache();

                // Handle AJAX requests
                if (request()->wantsJson() || request()->ajax()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Release deleted successfully',
                    ]);
                }

                session()->flash('success', 'Release deleted successfully');
            }

            // Handle AJAX requests
            if (request()->wantsJson() || request()->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No release ID provided',
                ], 400);
            }

            // Check if request is coming from the NZB details page
            $referer = request()->headers->get('referer');
            if ($referer && str_contains($referer, '/details/')) {
                // If coming from details page, redirect to home page
                return redirect()->route('All');
            }

            // Default redirection logic for other cases
            $redirectUrl = session('intended_redirect') ?? route('admin.release-list');
            session()->forget('intended_redirect');

            return redirect($redirectUrl);
        } catch (\Exception $e) {
            // Handle AJAX requests
            if (request()->wantsJson() || request()->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error deleting release: '.$e->getMessage(),
                ], 500);
            }

            session()->flash('error', 'Error deleting release: '.$e->getMessage());

            return redirect()->route('admin.release-list');
        }
    }
}
