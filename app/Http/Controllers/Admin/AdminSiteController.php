<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\GrabStat;
use App\Models\ReleaseStat;
use App\Models\RoleStat;
use App\Models\SignupStat;
use App\Services\NNTP\NntpProviderPool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What is left of the old site admin once the settings hub took the form.
 *
 * `edit()` and its fifteen blade sections were retired in favour of
 * {@see AdminSettingsController}. The stats page has
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
            'providerDownloads' => $this->providerDownloads(),
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.site.stats', $this->viewData);
    }

    /**
     * Bytes downloaded from each configured provider today, over the last 7 full days and over
     * the last 30 full days, with "today" in the app timezone. Rows for names no longer
     * configured are not read.
     *
     * @return array{windows: list<array{title: string, range: string}>, providers: list<array{name: string, address: string, began: ?string, bytes: list<int>}>}
     */
    private function providerDownloads(): array
    {
        $today = Carbon::now((string) config('app.timezone'))->startOfDay();
        $earliest = $today->copy()->subDays(30);
        $windows = [
            ['title' => 'Today', 'from' => $today, 'to' => $today],
            ['title' => 'Last 7 full days', 'from' => $today->copy()->subDays(7), 'to' => $today->copy()->subDay()],
            ['title' => 'Last 30 full days', 'from' => $earliest, 'to' => $today->copy()->subDay()],
        ];

        $providers = NntpProviderPool::configuredProviders();
        $names = array_map(static fn ($provider): string => $provider->name, $providers);

        $rows = DB::table('nntp_provider_traffic')->whereIn('provider', $names)
            ->where('day', '>=', $earliest->toDateString())->get(['provider', 'day', 'bytes_received']);
        $firstDays = DB::table('nntp_provider_traffic')->whereIn('provider', $names)
            ->groupBy('provider')->selectRaw('provider, MIN(day) AS first_day')->pluck('first_day', 'provider');

        $list = [];
        foreach ($providers as $provider) {
            $bytes = [];
            foreach ($windows as $window) {
                $from = $window['from']->toDateString();
                $to = $window['to']->toDateString();
                $bytes[] = (int) $rows->where('provider', $provider->name)
                    ->filter(static fn ($row): bool => (string) $row->day >= $from && (string) $row->day <= $to)
                    ->sum('bytes_received');
            }
            $firstDay = $firstDays[$provider->name] ?? null;
            $list[] = [
                'name' => $provider->name,
                'address' => substr($provider->label(), strlen($provider->name) + 1),
                'began' => $firstDay === null ? null : Carbon::parse((string) $firstDay)->format('M j, Y'),
                'bytes' => $bytes,
            ];
        }

        return [
            'windows' => array_map(static fn (array $window): array => [
                'title' => $window['title'],
                'range' => self::dateRange($window['from'], $window['to']),
            ], $windows),
            'providers' => $list,
        ];
    }

    /**
     * "Oct 16, 2026" for one day, "Oct 9 – Oct 15, 2026" within a year, and both years when
     * the range crosses one.
     */
    private static function dateRange(Carbon $from, Carbon $to): string
    {
        if ($from->isSameDay($to)) {
            return $to->format('M j, Y');
        }

        return ($from->year === $to->year ? $from->format('M j') : $from->format('M j, Y')).' – '.$to->format('M j, Y');
    }
}
