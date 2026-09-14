<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BrowseRoot;
use App\Models\Category;
use App\Services\Releases\TitleMetadataLoader;
use App\Services\Releases\TitleReleaseBrowser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TitleController extends BasePageController
{
    public function show(Request $request, string $root, string $id, TitleMetadataLoader $metadata, TitleReleaseBrowser $browser): mixed
    {
        $category = BrowseRoot::fromRoute($root);
        abort_unless(in_array($category, [BrowseRoot::Movies, BrowseRoot::Tv, BrowseRoot::Audio, BrowseRoot::Console, BrowseRoot::Games, BrowseRoot::Books], true), 404);
        $permission = 'view '.($category === BrowseRoot::Games ? 'pc' : $category->value);
        abort_unless($this->userdata->getDirectPermissions()->contains('name', $permission), 403);
        $id = $category === BrowseRoot::Movies ? preg_replace('/^tt/i', '', $id) : $id;
        abort_unless(is_string($id) && ctype_digit($id) && (int) $id > 0, 404);
        if ($category !== BrowseRoot::Movies) {
            $id = (string) (int) $id;
        }
        $title = $metadata->load($category, $id);
        $data = array_merge($this->viewData, $browser->load($category, $id, $this->userdata, $request), [
            'title' => $title, 'meta_title' => $title->entity->title,
            ...$this->watchState($category, $id),
        ]);
        if ($request->input('_fragment') === 'releases') {
            return view('title.partials.releases', $data);
        }

        return view('title.index', $data);
    }

    /** @return array{watched:bool, watchCategories:list<string>, watchUrl:?string, removeWatchUrl:?string} */
    private function watchState(BrowseRoot $root, string $id): array
    {
        $watched = false;
        $watchCategories = [];
        $watchUrl = $removeWatchUrl = null;
        if (in_array($root, [BrowseRoot::Movies, BrowseRoot::Tv], true)) {
            $movie = $root === BrowseRoot::Movies;
            $record = DB::table($movie ? 'user_movies' : 'user_series')->where('users_id', $this->userdata->id)
                ->where($movie ? 'imdbid' : 'videos_id', $id)->first();
            $watched = $record !== null;
            $saved = (string) ($record->categories ?? '');
            if ($watched) {
                if (in_array($saved, ['', 'NULL'], true)) {
                    $watchCategories = ['All categories'];
                } else {
                    $ids = array_map('intval', explode('|', $saved));
                    $watchCategories = Category::query()->where('root_categories_id', $root->categoryId())->whereIn('id', $ids)->pluck('title')->all();
                    if (in_array($root->categoryId(), $ids, true)) {
                        array_unshift($watchCategories, 'All '.$root->label());
                    }
                }
            }
            $path = url($movie ? '/mymovies' : '/myshows');
            $identity = [$movie ? 'imdb' : 'id' => $id];
            $action = $movie ? 'id' : 'action';
            $watchUrl = $path.'?'.http_build_query([...$identity, $action => $watched ? 'edit' : 'add']);
            $removeWatchUrl = $path.'?'.http_build_query([...$identity, $action => 'delete']);
        }

        return compact('watched', 'watchCategories', 'watchUrl', 'removeWatchUrl');
    }
}
