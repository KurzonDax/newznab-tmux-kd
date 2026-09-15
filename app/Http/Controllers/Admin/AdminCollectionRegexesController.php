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

class AdminCollectionRegexesController extends BasePageController
{
    /**
     * @throws \Exception
     */
    public function index(Request $request): mixed
    {
        $this->setAdminPrefs();
        $regexes = new RegexService('collection_regexes');

        $meta_title = $title = 'Collections Regex List';

        $group = $this->scalarInput($request, 'group');
        $regex = $regexes->getRegex($group);

        $this->viewData = array_merge($this->viewData, [
            'group' => $group,
            'regex' => $regex,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.regexes.collection-list', $this->viewData);
    }

    /**
     * @return RedirectResponse|View
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {
        $this->setAdminPrefs();
        $regexes = new RegexService('collection_regexes');
        $error = '';
        $regex = ['id' => '', 'regex' => '', 'description' => '', 'group_regex' => '', 'ordinal' => '', 'status' => 1];
        $meta_title = $title = 'Collections Regex';

        switch ($request->input('action') ?? 'view') {
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

                return redirect()->to('admin/collection_regexes-list');

            case 'view':
            default:
                if ($request->has('id')) {
                    $meta_title = $title = 'Collections Regex Edit';
                    $id = filter_var($request->input('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($id === false) {
                        abort(404);
                    }

                    $regex = $regexes->getRegexByID($id);
                } else {
                    $meta_title = $title = 'Collections Regex Add';
                    $regex += ['status' => 1];
                }
                break;
        }

        $this->viewData = array_merge($this->viewData, [
            'regex' => (object) $regex,
            'error' => $error,
            'status_ids' => [Category::STATUS_ACTIVE, Category::STATUS_INACTIVE],
            'status_names' => ['Yes', 'No'],
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.regexes.collection-edit', $this->viewData);
    }

    /**
     * @throws \Exception
     */
    public function testRegex(AdminRegexTestRequest $request): mixed
    {
        $this->setAdminPrefs();
        $meta_title = $title = 'Collections Regex Test';

        $group = (string) $request->input('group', '');
        $regex = (string) $request->input('regex', '');
        $limit = $request->integer('limit', 50);

        $data = null;
        $summary = null;
        if ($request->isSubmitted()) {
            try {
                $summary = (new RegexService('collection_regexes'))->testCollectionRegex($group, $regex, $limit);
            } catch (ValidationException $exception) {
                throw $exception->redirectTo($request->url());
            }
            $data = $summary['rows'];
        }

        $this->viewData = array_merge($this->viewData, [
            'group' => $group,
            'regex' => $regex,
            'limit' => $limit,
            'data' => $data,
            'summary' => $summary,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.regexes.collection-test', $this->viewData);
    }
}
