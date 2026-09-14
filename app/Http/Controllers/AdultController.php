<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdultController extends BasePageController
{
    public function show(Request $request, string $id = ''): RedirectResponse
    {
        $category = null;
        if ($id !== '' && strtolower($id) !== 'all') {
            $category = Category::query()->where('root_categories_id', Category::XXX_ROOT)->where('title', $id)->firstOrFail();
        } elseif ($request->filled('t') && $this->integerInput($request, 't') !== Category::XXX_ROOT) {
            $category = Category::query()->where('root_categories_id', Category::XXX_ROOT)->whereKey($this->integerInput($request, 't'))->firstOrFail();
        }

        return redirect()->route('browse', [
            'parentCategory' => 'xxx', 'id' => $category?->id, ...$request->except(['t', 'parentCategory', 'id']),
        ]);
    }
}
