<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\AdminRegexTestRequest;
use App\Models\Category;
use App\Services\RegexService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminReleaseNamingRegexesController extends BasePageController
{
    /**
     * @throws \Exception
     */
    public function index(Request $request): mixed
    {
        $this->setAdminPrefs();
        $regexes = new RegexService('release_naming_regexes');

        $meta_title = $title = 'Release Naming Regex List';

        $group = $this->scalarInput($request, 'group');
        $regex = $regexes->getRegex($group);

        $this->viewData = array_merge($this->viewData, [
            'group' => $group,
            'regex' => $regex,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.regexes.release-naming-list', $this->viewData);
    }

    /**
     * @return RedirectResponse|View
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {
        $this->setAdminPrefs();
        $regexes = new RegexService('release_naming_regexes');

        // Set the current action.
        $action = $request->input('action') ?? 'view';
        $error = '';
        $regex = ['id' => '', 'group_regex' => '', 'regex' => '', 'description' => '', 'ordinal' => '', 'status' => 1];
        $meta_title = $title = 'Release Naming Regex';

        switch ($action) {
            case 'submit':
                if (empty($request->input('group_regex'))) {
                    $error = 'Group regex must not be empty!';
                    break;
                }

                if (empty($request->input('regex'))) {
                    $error = 'Regex cannot be empty';
                    break;
                }

                if (empty($request->input('description'))) {
                    $request->merge(['description' => '']);
                }

                if (! is_numeric($request->input('ordinal')) || $request->input('ordinal') < 0) {
                    $error = 'Ordinal must be a number, 0 or higher.';
                    break;
                }

                if (empty($request->input('id'))) {
                    $regexes->addRegex($request->all());
                } else {
                    $regexes->updateRegex($request->all());
                }

                return redirect()->to('admin/release_naming_regexes-list');

            case 'view':
            default:
                if ($request->has('id')) {
                    $meta_title = $title = 'Release Naming Regex Edit';
                    $id = filter_var($request->input('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($id === false) {
                        abort(404);
                    }

                    $regex = $regexes->getRegexByID($id);
                } else {
                    $meta_title = $title = 'Release Naming Regex Add';
                    $regex = ['status' => 1];
                }
                break;
        }

        $this->viewData = array_merge($this->viewData, [
            'error' => $error,
            'regex' => (object) $regex,
            'status_ids' => [Category::STATUS_ACTIVE, Category::STATUS_INACTIVE],
            'status_names' => ['Yes', 'No'],
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.regexes.release-naming-edit', $this->viewData);
    }

    /**
     * @throws \Exception
     */
    public function testRegex(AdminRegexTestRequest $request): mixed
    {
        $this->setAdminPrefs();
        $meta_title = $title = 'Release Naming Regex Test';

        $group = (string) $request->input('group', '');
        $regex = (string) $request->input('regex', '');
        $showLimit = $request->integer('showlimit', 250);
        $queryLimit = $request->integer('querylimit', 100000);

        $data = null;
        $summary = null;
        if ($request->isSubmitted()) {
            try {
                $summary = (new RegexService('release_naming_regexes'))->testReleaseNamingRegex($group, $regex, $showLimit, $queryLimit);
            } catch (ValidationException $exception) {
                throw $exception->redirectTo($request->url());
            }
            $data = $summary['rows'];
        }

        $this->viewData = array_merge($this->viewData, [
            'group' => $group,
            'regex' => $regex,
            'showlimit' => $showLimit,
            'querylimit' => $queryLimit,
            'data' => $data,
            'summary' => $summary,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.regexes.release-naming-test', $this->viewData);
    }
}
