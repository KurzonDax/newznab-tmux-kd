<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Services\ConsoleService;
use App\Services\GenreService;
use App\Services\ReleaseImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AdminConsoleController extends BasePageController
{
    protected ConsoleService $consoleService;

    protected ReleaseImageService $imageService;

    public function __construct(ConsoleService $consoleService, ReleaseImageService $imageService)
    {
        parent::__construct();
        $this->consoleService = $consoleService;
        $this->imageService = $imageService;
    }

    /**
     * Display a listing of console games
     */
    public function index(): View
    {
        $this->setAdminPrefs();

        $meta_title = $title = 'Console List';

        $consoleList = getRange('consoleinfo');

        return view('admin.console.index', compact('consoleList', 'title', 'meta_title'));
    }

    /**
     * Show the form for editing a console game
     */
    public function edit(Request $request): View|RedirectResponse
    {
        $this->setAdminPrefs();
        $gen = new GenreService;
        $meta_title = $title = 'Console Edit';

        // set the current action
        $action = $request->input('action', 'view');

        if ($request->has('id')) {
            $id = $this->integerInput($request, 'id');
            $con = $this->consoleService->getConsoleInfo($id);

            if (! $con) {
                abort(404);
            }

            switch ($action) {
                case 'submit':
                    $coverDirectory = storage_path('covers/console/');

                    if ($request->hasFile('cover') && $request->file('cover')->isValid()) {
                        $this->imageService->saveUploadedImage((string) $id, $request->file('cover'), $coverDirectory);
                    }

                    $hasCover = (int) $this->imageService->imageExists($coverDirectory, (string) $id);
                    $salesrank = $this->nullableIntegerInput($request, 'salesrank');
                    $genreId = $this->nullableIntegerInput($request, 'genre');
                    $releasedateInput = $this->scalarInput($request, 'releasedate');
                    $releasedate = ($releasedateInput === '' || ! strtotime($releasedateInput))
                        ? $this->storedAttribute($con, 'releasedate')
                        : Carbon::parse($releasedateInput)->toDateTimeString();

                    $this->consoleService->update(
                        $id,
                        $request->input('title'),
                        $request->input('asin'),
                        $request->input('url'),
                        $salesrank,
                        $request->input('platform'),
                        $request->input('publisher'),
                        $releasedate,
                        $request->input('esrb'),
                        $hasCover,
                        $genreId
                    );

                    return redirect()->route('admin.console-list')->with('success', 'Console game updated successfully');

                case 'view':
                default:
                    $genres = $gen->getGenres((string) GenreService::CONSOLE_TYPE);

                    return view('admin.console.edit', compact('con', 'genres', 'title', 'meta_title'));
            }
        }

        return redirect()->route('admin.console-list');
    }
}
