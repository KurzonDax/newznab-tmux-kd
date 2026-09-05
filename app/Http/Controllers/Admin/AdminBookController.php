<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\BookInfo;
use App\Services\BookService;
use App\Services\ReleaseImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AdminBookController extends BasePageController
{
    /**
     * Display a listing of books
     */
    public function index(): View
    {
        $this->setAdminPrefs();

        $meta_title = $title = 'Book List';

        $bookList = BookInfo::query()->orderByDesc('created_at')->paginate(config('nntmux.items_per_page'));

        return view('admin.books.index', compact('bookList', 'title', 'meta_title'));
    }

    /**
     * Show the form for editing a book
     */
    public function edit(Request $request): View|RedirectResponse
    {
        $this->setAdminPrefs();
        $bookService = new BookService;

        $meta_title = $title = 'Book Edit';

        // set the current action
        $action = $request->input('action') ?? 'view';

        if ($request->has('id')) {
            $id = $this->integerInput($request, 'id');
            $b = $bookService->getBookInfo($id);

            if (! $b) {
                abort(404);
            }

            switch ($action) {
                case 'submit':
                    $validated = $request->validate([
                        'title' => ['required', 'string', 'max:255'],
                        'author' => ['required', 'string', 'max:255'],
                        'publishdate' => ['nullable', 'date'],
                    ]);

                    $coverDirectory = storage_path('covers/book/');
                    $imageService = app(ReleaseImageService::class);

                    if ($request->hasFile('cover') && $request->file('cover')->isValid()) {
                        $imageService->saveUploadedImage((string) $id, $request->file('cover'), $coverDirectory);
                    }

                    $hasCover = (int) $imageService->imageExists($coverDirectory, (string) $id);
                    $publishdateInput = $this->scalarInput($request, 'publishdate');
                    $publishdate = ($publishdateInput === '' || ! strtotime($publishdateInput))
                        ? $this->storedAttribute($b, 'publishdate')
                        : Carbon::parse($publishdateInput)->toDateTimeString();

                    $bookService->update(
                        $id,
                        $validated['title'],
                        $request->input('asin'),
                        $request->input('url'),
                        $validated['author'],
                        $request->input('publisher'),
                        $publishdate,
                        $hasCover
                    );

                    return redirect()->route('admin.book-list')->with('success', 'Book updated successfully');
                case 'view':
                default:
                    return view('admin.books.edit', compact('title', 'meta_title'))->with('book', $b);
            }
        }

        return redirect()->route('admin.book-list');
    }
}
