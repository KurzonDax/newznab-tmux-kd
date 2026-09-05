<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\GrabStat;
use App\Models\ReleaseStat;
use App\Models\RoleStat;
use App\Models\SignupStat;

/**
 * What is left of the old site admin once the settings hub took the form.
 *
 * `edit()` and its fifteen blade sections were retired in favour of
 * {@see AdminSettingsController}; `admin/site-edit` now redirects there. The stats page has
 * nothing to do with settings and stays.
 */
class AdminSiteController extends BasePageController
{
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
