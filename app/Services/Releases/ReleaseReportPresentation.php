<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\ReleaseReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final class ReleaseReportPresentation
{
    /**
     * @return array{
     *     reportCount: int, totalReportCount: int, reportReasons: string, allReportReasons: string,
     *     originalReportData: LengthAwarePaginator<int, ReleaseReport>,
     *     publicReportResponses: LengthAwarePaginator<int, ReleaseReport>
     * }
     */
    public function forRelease(int $id, int $reportsPage = 1, int $responsesPage = 1): array
    {
        $reports = ReleaseReport::query()->where('releases_id', $id);
        $active = (clone $reports)->whereIn('status', ['pending', 'reviewed', 'resolved']);

        return [
            'reportCount' => (clone $active)->count(),
            'totalReportCount' => (clone $reports)->count(),
            'reportReasons' => $this->reasonLabels(clone $active),
            'allReportReasons' => $this->reasonLabels(clone $reports),
            'originalReportData' => (clone $reports)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(25, ['id', 'reason', 'status', 'description', 'created_at'], 'reports_page', max(1, $reportsPage))
                ->withQueryString()->fragment('reports'),
            'publicReportResponses' => (clone $reports)
                ->where('response_is_public', true)->whereNotNull('response')->where('response', '!=', '')
                ->with('responder:id,username')
                ->orderByDesc('responded_at')->orderByDesc('id')
                ->paginate(25, ['id', 'response', 'responded_at', 'responded_by'], 'responses_page', max(1, $responsesPage))
                ->withQueryString()->fragment('reports'),
        ];
    }

    /** @param Builder<ReleaseReport> $reports */
    private function reasonLabels(Builder $reports): string
    {
        $reasons = $reports->whereIn('reason', array_keys(ReleaseReport::REASONS))
            ->distinct()->orderBy('reason')->pluck('reason');

        return ReleaseReport::reasonKeysToLabels($reasons->implode(', '));
    }
}
