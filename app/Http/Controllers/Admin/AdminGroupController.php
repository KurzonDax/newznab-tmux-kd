<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\AdminGroupListRequest;
use App\Models\RootCategory;
use App\Models\UsenetGroup;
use App\Support\SizeUnit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminGroupController extends BasePageController
{
    /**
     * @throws \Exception
     */
    public function index(AdminGroupListRequest $request): mixed
    {
        $groupname = $request->groupName();
        $grouplist = UsenetGroup::getGroupsRange($groupname);
        $rootCategories = $this->rootCategories();
        $title = 'Group List';

        return view('admin.groups.index', compact('title', 'groupname', 'grouplist', 'rootCategories'));
    }

    /**
     * @throws \Exception
     */
    public function createBulk(Request $request): mixed
    {
        // set the current action
        $action = $request->input('action') ?? 'view';
        $groupmsglist = '';

        if ($action === 'submit') {
            $groupFilter = $request->input('groupfilter');

            if (is_string($groupFilter) && $groupFilter !== '') {
                $active = $request->has('active') ? $request->integer('active') : 1;
                $backfill = $request->has('backfill') ? $request->integer('backfill') : 1;

                $groupmsglist = UsenetGroup::addBulk($groupFilter, $active, $backfill);
            }
        }

        $title = 'Bulk Add Newsgroups';

        return view('admin.groups.bulk', compact('title', 'groupmsglist'));
    }

    /**
     * @throws \Exception
     */
    public function edit(Request $request): RedirectResponse|View
    {
        // Set the current action.
        $action = $request->input('action') ?? 'view';

        $group = [
            'id' => '',
            'name' => '',
            'description' => '',
            'minfilestoformrelease' => 0,
            'active' => 0,
            'backfill' => 0,
            'minsizetoformrelease' => 0,
            'first_record' => 0,
            'last_record' => 0,
            'backfill_target' => 0,
            'route_obfuscated_names' => false,
            'obfuscated_default_root_categories_id' => null,
        ];

        switch ($action) {
            case 'submit':
                $request->merge([
                    'route_obfuscated_names' => $request->boolean('route_obfuscated_names'),
                    'obfuscated_default_root_categories_id' => $request->filled('obfuscated_default_root_categories_id')
                        ? $request->integer('obfuscated_default_root_categories_id')
                        : null,
                ]);
                $request->validate([
                    'route_obfuscated_names' => ['required', 'boolean'],
                    'obfuscated_default_root_categories_id' => [
                        'nullable',
                        Rule::requiredIf($request->boolean('route_obfuscated_names')),
                        'integer',
                        'exists:root_categories,id',
                    ],
                ]);

                $minSizeInput = $request->input('minsizetoformrelease');
                if ($minSizeInput !== null && $minSizeInput !== '') {
                    try {
                        $minSizeUnit = $request->input('minsizetoformrelease_unit');
                        $minimumSizeInBytes = is_string($minSizeUnit)
                            ? SizeUnit::toBytes($minSizeInput, $minSizeUnit)
                            : parse_group_file_size($minSizeInput);

                        $request->merge(['minsizetoformrelease' => $minimumSizeInBytes]);
                    } catch (\InvalidArgumentException $exception) {
                        throw ValidationException::withMessages([
                            'minsizetoformrelease' => $exception->getMessage(),
                        ]);
                    }
                }
                $request->request->remove('minsizetoformrelease_unit');

                if (empty($request->input('id'))) {
                    // Add a new group.
                    $request->merge(['name' => UsenetGroup::isValidGroup($request->input('name'))]);
                    if ($request->input('name') !== false) {
                        UsenetGroup::addGroup($request->all());
                    }
                } else {
                    // Update an existing group.
                    UsenetGroup::updateGroup($request->all());
                }

                return redirect()->to('admin/group-list');

            case 'view':
            default:
                $title = 'Group Edit';
                if ($request->has('id')) {
                    $title = 'Newsgroup Edit';
                    $id = $request->input('id');
                    $group = UsenetGroup::getGroupByID($id);
                } else {
                    $title = 'Newsgroup Add';
                }
                break;
        }

        $groupMinSize = SizeUnit::fromBytes($group['minsizetoformrelease'] ?? 0);
        $rootCategories = $this->rootCategories();

        return view('admin.groups.edit', compact('title', 'group', 'groupMinSize', 'rootCategories') + ['sizeUnits' => SizeUnit::UNITS]);
    }

    /**
     * @throws \Exception
     */
    public function active(AdminGroupListRequest $request): mixed
    {
        $groupname = $request->groupName();
        $grouplist = UsenetGroup::getGroupsRange($groupname, true);
        $rootCategories = $this->rootCategories();
        $title = 'Active Groups';

        return view('admin.groups.index', compact('title', 'groupname', 'grouplist', 'rootCategories'));
    }

    /**
     * @throws \Exception
     */
    public function inactive(AdminGroupListRequest $request): mixed
    {
        $groupname = $request->groupName();
        $grouplist = UsenetGroup::getGroupsRange($groupname, false);
        $rootCategories = $this->rootCategories();
        $title = 'Inactive Groups';

        return view('admin.groups.index', compact('title', 'groupname', 'grouplist', 'rootCategories'));
    }

    /**
     * @return Collection<int, RootCategory>
     */
    private function rootCategories(): Collection
    {
        return RootCategory::query()->orderBy('title')->get(['id', 'title']);
    }
}
