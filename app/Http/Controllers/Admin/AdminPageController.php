<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Services\AdminDashboardSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AdminPageController extends BasePageController
{
    protected AdminDashboardSnapshotService $snapshot;

    public function __construct(AdminDashboardSnapshotService $snapshot)
    {
        parent::__construct();
        $this->snapshot = $snapshot;
    }

    /**
     * @throws \Exception
     */
    public function index(): mixed
    {
        $payload = $this->snapshot->get();

        return view('admin.dashboard', array_merge($this->viewData, [
            'meta_title' => 'Admin Home',
            'meta_description' => 'Admin home page',
            // Lightweight data is rendered server-side; heavy widgets fetch on mount.
            'stats' => $payload['stats'],
            'registrationStatus' => $payload['registrationStatus'],
            'nextRegistrationPeriod' => $payload['nextRegistrationPeriod'],
            'hasRecentPayments' => ! empty($payload['recent_payments']),
        ]));
    }

    /**
     * JSON payload consumed by the dashboard's deferred widgets (User Statistics,
     * System Resources history, Recent Activity, Site Status) and by the
     * client-side auto-refresh which also re-renders the headline stat tiles
     * and the registration status panel from this payload. Reads from the
     * same snapshot cache the index uses, so the warmer keeps it hot.
     */
    public function getDashboardData(): JsonResponse
    {
        $payload = $this->snapshot->get();

        $serviceStatuses = collect($payload['serviceStatuses'])->map(static fn ($svc) => [
            'name' => $svc->name,
            'slug' => $svc->slug,
            'check_type' => $svc->check_type,
            'status' => $svc->status->value,
            'status_label' => $svc->status->label(),
            'uptime_percentage' => (float) $svc->uptime_percentage,
        ])->values();

        $activeIncidents = collect($payload['activeIncidents'])->map(static fn ($incident) => [
            'id' => $incident->id,
            'title' => $incident->title,
            'impact' => $incident->impact->value,
            'impact_label' => $incident->impact->label(),
            'started_at' => $incident->started_at?->toIso8601String(),
            'started_at_human' => $incident->started_at?->diffForHumans(),
            'is_auto' => (bool) $incident->is_auto,
            'services' => $incident->services->pluck('name')->all(),
        ])->values();

        $recentActivity = array_map(static fn ($activity): array => [
            'type' => $activity->type,
            'message' => $activity->message,
            'icon' => $activity->icon,
            'icon_bg' => $activity->icon_bg,
            'icon_color' => $activity->icon_color,
            'created_at' => $activity->created_at?->toIso8601String(),
            'created_at_human' => $activity->created_at?->diffForHumans(),
            'username' => $activity->username,
            'metadata' => $activity->metadata,
        ], $payload['recent_activity']);

        $registrationStatus = $payload['registrationStatus'];
        $serializePeriod = static fn ($period): ?array => $period === null ? null : [
            'id' => $period->id,
            'name' => $period->name,
            'starts_at' => $period->starts_at?->format('Y-m-d H:i'),
            'ends_at' => $period->ends_at?->format('Y-m-d H:i'),
        ];

        $registrationPayload = [
            'manual_status' => $registrationStatus['manual_status'],
            'manual_status_label' => $registrationStatus['manual_status_label'],
            'effective_status' => $registrationStatus['effective_status'],
            'effective_status_label' => $registrationStatus['effective_status_label'],
            'scheduled_override_active' => (bool) $registrationStatus['scheduled_override_active'],
            'message' => $registrationStatus['message'],
            'active_period' => $serializePeriod($registrationStatus['active_period']),
        ];

        $generatedAt = $payload['generated_at'] ?? now()->toIso8601String();
        $generatedAtCarbon = Carbon::parse($generatedAt);

        return response()->json([
            'success' => true,
            'generated_at' => $generatedAt,
            'generated_at_time' => $generatedAtCarbon->format('H:i:s'),
            'stats' => $payload['stats'],
            'registrationStatus' => $registrationPayload,
            'nextRegistrationPeriod' => $serializePeriod($payload['nextRegistrationPeriod']),
            'userStats' => $payload['userStats'],
            'systemMetrics' => $payload['systemMetrics'],
            'recent_activity' => $recentActivity,
            'recent_payments' => $payload['recent_payments'] ?? [],
            'serviceStatuses' => $serviceStatuses,
            'activeIncidents' => $activeIncidents,
        ]);
    }
}
