<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\BrowseRoot;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LegacyCoverRedirect
{
    public function redirect(Request $request, BrowseRoot $root, string $categoryPath = ''): RedirectResponse
    {
        $parameters = $request->except(['t', 'ob', 'parentCategory', 'id', '_token']);
        if ($root !== BrowseRoot::Movies) {
            unset($parameters['title']);
        }
        if ($root !== BrowseRoot::Movies && ! $request->filled('q') && is_string($request->input('title'))) {
            $parameters['q'] = $request->input('title');
        }
        if (! $request->has('sort') && $request->has('ob')) {
            $parameters['sort'] = match ($request->input('ob')) {
                'title_asc' => 'title', 'year_desc' => 'year', 'rating_desc' => 'rating',
                'artist_asc' => 'artist', 'stats_desc' => 'grabs', default => 'newest',
            };
        }
        if (in_array($root, [BrowseRoot::Audio, BrowseRoot::Console, BrowseRoot::Games], true)) {
            $genre = $parameters['genre'] ?? null;
            if (is_string($genre) && ctype_digit($genre)) {
                $parameters['genre'] = (string) (DB::table('genres')->where('id', $genre)->value('title') ?? $genre);
            }
        }
        $parameters['view'] ??= 'covers';
        $category = null;
        $categoryInput = $request->input('t');
        abort_if($request->filled('t') && (! is_scalar($categoryInput) || ! ctype_digit((string) $categoryInput)), 404);
        if ($root === BrowseRoot::Tv) {
            if ($categoryPath !== '') {
                abort_unless(preg_match('/^(0-9|[A-Z])$/i', $categoryPath) === 1, 404);
                $parameters['letter'] = $categoryPath === '0-9' ? '#' : strtoupper($categoryPath);
            }
        } elseif ($categoryPath !== '' && strtolower($categoryPath) !== 'all') {
            $category = Category::query()->where('root_categories_id', $root->categoryId())
                ->where('title', $categoryPath === 'WiiVare' ? 'WiiVareVC' : $categoryPath)->firstOrFail();
        } elseif ($request->filled('t') && (string) $categoryInput !== (string) $root->categoryId()) {
            $category = Category::query()->where('root_categories_id', $root->categoryId())->whereKey($categoryInput)->firstOrFail();
        }

        return redirect()->route('browse', ['parentCategory' => $root->value, 'id' => $category?->id, ...$parameters]);
    }
}
