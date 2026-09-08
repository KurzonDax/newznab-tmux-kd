<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\AdminRecoveredReleaseListRequest;
use App\Services\ObfuscationRecovery\RecoveredReleaseList;
use Illuminate\View\View;

final class AdminRecoveredReleasesController extends BasePageController
{
    public function index(AdminRecoveredReleaseListRequest $request, RecoveredReleaseList $recovery): View
    {
        $this->setAdminPrefs();

        $group = $request->validated('group');
        $sort = $request->validated('sort') ?? 'added-desc';
        [$column, $direction] = match ($sort) {
            'added-asc' => ['adddate', 'asc'],
            'posted-desc' => ['postdate', 'desc'],
            'posted-asc' => ['postdate', 'asc'],
            default => ['adddate', 'desc'],
        };

        $releases = $recovery->query()
            ->select(['releases.id', 'releases.guid', 'releases.searchname', 'releases.size',
                'releases.adddate', 'releases.postdate', 'recovery.id as publication_id', 'recovery.profile',
                'recovery.identity_outcome', 'recovery.multi_media_inventory', 'recovery.enrichment_outcome',
                'recovery.protected_files', 'recovery.sealed_plan', 'recovery.detail_retired_at',
                'groups.name as group_name', 'category.title as category_name', 'root.title as root_name'])
            ->when($group !== null, fn ($query) => $query->where('releases.groups_id', (int) $group))
            ->orderBy('releases.'.$column, $direction)->orderBy('releases.id', $direction)
            ->paginate(max(1, (int) config('nntmux.items_per_page', 25)))
            ->appends($request->safe()->only(['group', 'sort']));
        $recovery->addDetails($releases->getCollection());

        return view('admin.releases.recovered', [
            'title' => 'Recovered Releases',
            'meta_title' => 'Recovered Releases',
            'group' => $group,
            'sort' => $sort,
            'groups' => $recovery->query()->whereNotNull('groups.id')->select(['groups.id', 'groups.name'])->distinct()->orderBy('groups.name')->get(),
            'releases' => $releases,
        ]);
    }
}
