<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BrowseRoot;
use App\Models\Category;
use Illuminate\Http\Request;

class BrowseController extends BasePageController
{
    /**
     * /browse/{root}/{id?}: TV and Movies redirect to their lists, the retired roots are not
     * found, and Other, the one root still rendered here, opens the generic Other list with the
     * sub-category the address names (by id or title) set as its Category.
     */
    public function show(Request $request, string $parentCategory, string $id = 'All'): mixed
    {
        $root = BrowseRoot::fromRoute($parentCategory);
        abort_if($root === null || in_array($root, [BrowseRoot::All, BrowseRoot::Adult, BrowseRoot::Audio, BrowseRoot::Console, BrowseRoot::Games, BrowseRoot::Books], true), 404);
        $category = null;
        if (strtolower($id) !== 'all') {
            $query = Category::query()->where('root_categories_id', $root->categoryId());
            $category = ctype_digit($id)
                ? $query->whereKey((int) $id)->firstOrFail()
                : $query->where('title', $id)->firstOrFail();
            abort_if(in_array((int) $category->id, (array) $this->userdata->categoryexclusions), 403);
        }
        if ($root === BrowseRoot::Tv) {
            return redirect()->route('tv.releases', $category === null ? [] : ['category' => [(int) $category->id]]);
        }
        if ($root === BrowseRoot::Movies) {
            return redirect()->route('movies.releases', $category === null ? [] : ['category' => [(int) $category->id]]);
        }

        return app(GenericReleasesController::class)->other($request, $this->userdata, $category);
    }

    public function group(Request $request): mixed
    {
        $group = $request->input('g');
        if (! is_string($group) || $group === '') {
            return redirect()->back()->with('error', 'Group parameter is required');
        }

        return redirect()->route('browse.all', [...$request->except('g'), 'group' => $group]);
    }
}
