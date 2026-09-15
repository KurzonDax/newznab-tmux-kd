<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\AdminPromotionRequest;
use App\Models\RolePromotion;
use App\Models\RolePromotionStat;
use App\Support\DateRangeFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AdminPromotionController extends BasePageController
{
    /**
     * Display a listing of promotions.
     */
    public function index(): View
    {
        $promotions = RolePromotion::orderBy('is_active', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('admin.promotions.index', [
            'promotions' => $promotions,
        ]);
    }

    /**
     * Show the form for creating a new promotion.
     */
    public function create(): View
    {
        $customRoles = RolePromotion::getCustomRoles();

        return view('admin.promotions.create', [
            'customRoles' => $customRoles,
        ]);
    }

    /**
     * Store a newly created promotion in storage.
     */
    public function store(AdminPromotionRequest $request): RedirectResponse
    {
        RolePromotion::create([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'applicable_roles' => $request->input('applicable_roles', []),
            'additional_days' => $request->input('additional_days'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()->route('admin.promotions.index')
            ->with('success', 'Promotion created successfully.');
    }

    /**
     * Show the form for editing the specified promotion.
     */
    public function edit(int $id): View
    {
        $promotion = RolePromotion::findOrFail($id);
        $customRoles = RolePromotion::getCustomRoles();

        return view('admin.promotions.edit', [
            'promotion' => $promotion,
            'customRoles' => $customRoles,
        ]);
    }

    /**
     * Update the specified promotion in storage.
     */
    public function update(AdminPromotionRequest $request, int $id): RedirectResponse
    {
        $promotion = RolePromotion::findOrFail($id);

        $promotion->update([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'applicable_roles' => $request->input('applicable_roles', []),
            'additional_days' => $request->input('additional_days'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()->route('admin.promotions.index')
            ->with('success', 'Promotion updated successfully.');
    }

    /**
     * Remove the specified promotion from storage.
     */
    public function destroy(int $id): RedirectResponse
    {
        $promotion = RolePromotion::findOrFail($id);
        $promotion->delete();

        return redirect()->route('admin.promotions.index')
            ->with('success', 'Promotion deleted successfully.');
    }

    /**
     * Toggle the active status of the specified promotion.
     */
    public function toggle(int $id): RedirectResponse
    {
        $promotion = RolePromotion::findOrFail($id);
        $promotion->update(['is_active' => ! $promotion->is_active]);

        $status = $promotion->is_active ? 'activated' : 'deactivated';

        return redirect()->route('admin.promotions.index')
            ->with('success', "Promotion {$status} successfully.");
    }

    /**
     * Display overall promotion statistics.
     */
    public function statistics(Request $request): View
    {
        [$startDate, $endDate] = DateRangeFilter::fromRequest($request);

        $promotionQuery = RolePromotion::withCount(['statistics' => function ($query) use ($startDate, $endDate) {
            if ($startDate) {
                $query->whereBetween('applied_at', [$startDate, $endDate]);
            }
        }]);
        $statistics = RolePromotionStat::query()
            ->when($startDate, fn ($query) => $query->whereBetween('applied_at', [$startDate, $endDate]));
        $overallStats = [
            'total_promotions' => RolePromotion::query()->count(),
            'active_promotions' => RolePromotion::query()->where('is_active', true)->count(),
            'total_applications' => (clone $statistics)->whereHas('promotion')->count(),
            'unique_users' => (clone $statistics)->distinct()->count('user_id'),
            'total_days_added' => (int) (clone $statistics)->sum('days_added'),
        ];
        $topPromotions = (clone $promotionQuery)->orderByDesc('statistics_count')->orderBy('id')->limit(5)->get();
        $promotions = $promotionQuery->orderBy('id')->paginate(25, ['*'], 'promotions_page')->withQueryString();

        // Get recent activity
        $recentActivity = RolePromotionStat::with(['user:id,username', 'promotion:id,name', 'role:id,name'])
            ->when($startDate, fn ($q) => $q->whereBetween('applied_at', [$startDate, $endDate]))
            ->latest('applied_at')
            ->limit(10)
            ->get();

        // Get statistics by role
        $statsByRole = RolePromotionStat::query()
            ->selectRaw('role_id, COUNT(*) as total_upgrades, SUM(days_added) as total_days, COUNT(DISTINCT user_id) as unique_users')
            ->when($startDate, fn ($q) => $q->whereBetween('applied_at', [$startDate, $endDate]))
            ->groupBy('role_id')
            ->with('role')
            ->get();

        return view('admin.promotions.statistics', [
            'promotions' => $promotions,
            'overallStats' => $overallStats,
            'topPromotions' => $topPromotions,
            'recentActivity' => $recentActivity,
            'statsByRole' => $statsByRole,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'selectedPeriod' => $request->input('period', '30days'),
        ]);
    }

    /**
     * Display statistics for a specific promotion.
     */
    public function showStatistics(int $id, Request $request): View
    {
        $promotion = RolePromotion::findOrFail($id);

        [$startDate, $endDate] = DateRangeFilter::fromRequest($request);

        // Get promotion statistics
        $stats = $promotion->getStatisticsForPeriod($startDate ?? Carbon::createFromTimestamp(0), $endDate);
        $statsByRole = $promotion->getStatisticsByRole();

        // Get applications with users
        $applications = RolePromotionStat::forPromotion($id)
            ->when($startDate, fn ($q) => $q->whereBetween('applied_at', [$startDate, $endDate]))
            ->with(['user', 'role'])
            ->latest('applied_at')
            ->paginate(20);

        // Get daily statistics for chart
        $dailyStats = RolePromotionStat::forPromotion($id)
            ->when($startDate, fn ($q) => $q->whereBetween('applied_at', [$startDate, $endDate]))
            ->selectRaw('DATE(applied_at) as date, COUNT(*) as count, SUM(days_added) as days')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return view('admin.promotions.show-statistics', [
            'promotion' => $promotion,
            'stats' => $stats,
            'statsByRole' => $statsByRole,
            'applications' => $applications,
            'dailyStats' => $dailyStats,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'selectedPeriod' => $request->input('period', '30days'),
        ]);
    }
}
