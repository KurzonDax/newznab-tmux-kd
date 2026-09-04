<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\GrabStat;
use App\Models\ReleaseStat;
use App\Models\RoleStat;
use App\Models\RootCategory;
use App\Models\Settings;
use App\Models\SignupStat;
use App\Services\NNTP\NntpProviderPool;
use App\Services\Nzb\NzbService;
use App\Services\Releases\ClipGenerationPolicy;
use App\Services\Releases\DynamicPreviewBudgetPolicy;
use App\Support\RepairSettingRules;
use App\Support\SizeUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class AdminSiteController extends BasePageController
{
    /**
     * @return RedirectResponse|View
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {

        $meta_title = $title = 'Site Edit';
        $error = '';

        // set the current action
        $action = $request->input('action') ?? 'view';

        switch ($action) {
            case 'submit':
                $data = $request->all();

                // The repair and re-scan budgets are the only settings here a scheduled job
                // reads as a bound; a negative one misbehaves rather than merely looking wrong.
                // The NZB split level joins them because it names a directory depth: 0 is the
                // legal "store flat" depth and the write path fans out at most
                // NzbService::MAX_SPLIT_LEVEL GUID characters, so anything outside that range
                // cannot address a path any release was ever written to. A blank value is left
                // alone here and resolves to the coded default when the service reads it.
                $validator = Validator::make($data, RepairSettingRules::rules() + [
                    'nzbsplitlevel' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.NzbService::MAX_SPLIT_LEVEL],
                ]);
                $validator->setAttributeNames(RepairSettingRules::attributes() + [
                    'nzbsplitlevel' => 'NZB storage depth',
                ]);

                if ($validator->fails()) {
                    return redirect()->to('admin/site-edit')->withErrors($validator)->withInput();
                }

                foreach (SizeUnit::SITE_SIZE_SETTINGS as $sizeKey) {
                    $data[$sizeKey] = SizeUnit::toBytes($data[$sizeKey] ?? null, $data[$sizeKey.'_unit'] ?? 'MB');
                    unset($data[$sizeKey.'_unit']);
                }

                // Per-root discard and Preview Generation toggles live on
                // root_categories, not in settings. HTML forms omit unchecked
                // checkboxes, so absence means "off".
                $discardToggles = (array) ($data['discard_executables'] ?? []);
                unset($data['discard_executables']);
                $previewToggles = (array) ($data['generate_previews'] ?? []);
                unset($data['generate_previews']);
                $dynamicBudgetToggles = (array) ($data['dynamic_preview_budget'] ?? []);
                unset($data['dynamic_preview_budget']);
                $clipToggles = (array) ($data['generate_clips'] ?? []);
                unset($data['generate_clips']);

                foreach (RootCategory::query()->get() as $rootCategory) {
                    $updates = [];

                    $discardEnabled = ! empty($discardToggles[$rootCategory->id]);
                    if ($rootCategory->discard_executables !== $discardEnabled) {
                        $updates['discard_executables'] = $discardEnabled;
                    }

                    $previewsEnabled = ! empty($previewToggles[$rootCategory->id]);
                    if ($rootCategory->generate_previews !== $previewsEnabled) {
                        $updates['generate_previews'] = $previewsEnabled;
                    }

                    // Only Movies/TV/XXX are surfaced in the form; ineligible
                    // roots never flip, so their absent checkboxes stay off.
                    if (in_array((int) $rootCategory->id, DynamicPreviewBudgetPolicy::ELIGIBLE_ROOT_IDS, true)) {
                        $dynamicBudgetEnabled = ! empty($dynamicBudgetToggles[$rootCategory->id]);
                        if ($rootCategory->dynamic_preview_budget !== $dynamicBudgetEnabled) {
                            $updates['dynamic_preview_budget'] = $dynamicBudgetEnabled;
                        }
                    }

                    if (in_array((int) $rootCategory->id, ClipGenerationPolicy::ELIGIBLE_ROOT_IDS, true)) {
                        $clipsEnabled = ! empty($clipToggles[$rootCategory->id]);
                        if ($rootCategory->generate_clips !== $clipsEnabled) {
                            $updates['generate_clips'] = $clipsEnabled;
                        }
                    }

                    if ($updates !== []) {
                        $rootCategory->update($updates);
                    }
                }

                Settings::settingsUpdate($data);

                return redirect()->to('admin/site-edit')->with('success', 'Settings updated successfully');

            case 'view':
            default:
                break;
        }

        // Header compression is a primary-only concern: provider 1 is the only backbone that
        // ever serves headers, so it is the only host worth warning about.
        $headerProvider = NntpProviderPool::tryPrimaryProvider();
        $compress_headers_warning = $headerProvider !== null && str_contains($headerProvider->host, 'astra')
            ? ''
            : 'compress_headers_warning';

        $sizeFields = [];
        foreach (SizeUnit::SITE_SIZE_SETTINGS as $sizeKey) {
            $sizeFields[$sizeKey] = SizeUnit::fromBytes($this->viewData['site'][$sizeKey] ?? 0);
        }

        $rootCategories = RootCategory::query()->orderBy('id')->get();

        $this->viewData = array_merge($this->viewData, [
            'error' => $error,
            'sizeFields' => $sizeFields,
            'sizeUnits' => SizeUnit::UNITS,
            'discardRoots' => $rootCategories,
            'previewRoots' => $rootCategories,
            'dynamicBudgetRoots' => $rootCategories->whereIn('id', DynamicPreviewBudgetPolicy::ELIGIBLE_ROOT_IDS)->values(),
            'clipRoots' => $rootCategories->whereIn('id', ClipGenerationPolicy::ELIGIBLE_ROOT_IDS)->values(),
            'yesno' => [
                'ids' => [1, 0],
                'names' => ['Yes', 'No'],
            ],
            'passwd' => [
                'ids' => [1, 0],
                'names' => ['Deep (requires unrar)', 'None'],
            ],
            'langlist' => [
                'ids' => [0, 2, 3, 1],
                'names' => ['English', 'Danish', 'French', 'German'],
            ],
            'imdblang' => [
                'ids' => ['en', 'da', 'nl', 'fi', 'fr', 'de', 'it', 'tlh', 'no', 'po', 'ru', 'es', 'sv'],
                'names' => ['English', 'Danish', 'Dutch', 'Finnish', 'French', 'German', 'Italian', 'Klingon', 'Norwegian', 'Polish', 'Russian', 'Spanish', 'Swedish'],
            ],
            'newgroupscan_names' => ['Days', 'Posts'],
            'registerstatus' => [
                'ids' => [Settings::REGISTER_STATUS_OPEN, Settings::REGISTER_STATUS_INVITE, Settings::REGISTER_STATUS_CLOSED],
                'names' => ['Open', 'Invite', 'Closed'],
            ],
            'passworded' => [
                'ids' => [0, 1],
                'names' => ['Hide passworded', 'Show everything'],
            ],
            'lookuplanguage' => [
                'iso' => ['en', 'de', 'es', 'fr', 'it', 'nl', 'pt', 'sv'],
                'names' => ['English', 'Deutsch', 'Español', 'Français', 'Italiano', 'Nederlands', 'Português', 'Svenska'],
            ],
            'imdb_urls' => [
                'ids' => [0, 1],
                'names' => ['imdb.com', 'akas.imdb.com'],
            ],
            'lookupbooks' => [
                'ids' => [0, 1, 2],
                'names' => ['Disabled', 'Lookup All Books', 'Lookup Renamed Books'],
            ],
            'lookupgames' => [
                'ids' => [0, 1, 2],
                'names' => ['Disabled', 'Lookup All Consoles', 'Lookup Renamed Consoles'],
            ],
            'lookupmusic' => [
                'ids' => [0, 1, 2],
                'names' => ['Disabled', 'Lookup All Music', 'Lookup Renamed Music'],
            ],
            'lookupmovies' => [
                'ids' => [0, 1, 2],
                'names' => ['Disabled', 'Lookup All Movies', 'Lookup Renamed Movies'],
            ],
            'lookuptv' => [
                'ids' => [0, 1, 2],
                'names' => ['Disabled', 'Lookup All TV', 'Lookup Renamed TV'],
            ],
            'lookup_reqids' => [
                'ids' => [0, 1, 2],
                'names' => ['Disabled', 'Lookup Request IDs', 'Lookup Request IDs Threaded'],
            ],
            'compress_headers_warning' => $compress_headers_warning,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.site.edit', $this->viewData);
    }

    /**
     * @throws \Exception
     */
    public function stats(): mixed
    {

        $meta_title = $title = 'Site Stats';

        $topGrabs = GrabStat::getTopGrabbers();
        $recent = ReleaseStat::getRecentlyAdded();
        $usersByMonth = SignupStat::getUsersByMonth();
        $usersByRole = RoleStat::getUsersByRole();

        $this->viewData = array_merge($this->viewData, [
            'topgrabs' => $topGrabs,
            'recent' => $recent,
            'usersbymonth' => $usersByMonth,
            'usersbyrole' => $usersByRole,
            'totusers' => 0,
            'totrusers' => 0,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.site.stats', $this->viewData);
    }
}
