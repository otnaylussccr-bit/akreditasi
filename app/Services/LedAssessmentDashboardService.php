<?php

namespace App\Services;

use App\Models\AccreditationLedAssessmentCycle;
use App\Models\AccreditationLedRubric;
use App\Models\User;

class LedAssessmentDashboardService
{
    public function __construct(private readonly LedAssessmentAuthorizationService $authorization) {}

    public function summary(AccreditationLedAssessmentCycle $cycle, User $user): array
    {
        $assessmentsQuery = $cycle->assessments()
            ->with(['indicator', 'currentApprovedRevision.rubric'])
            ->orderByRaw('(select position from accreditation_led_indicators where accreditation_led_indicators.id = accreditation_led_assessments.indicator_id)');

        if (! $this->authorization->hasCycleOversight($user, $cycle)) {
            $assessmentsQuery->whereHas('cycle.assignments', fn ($assignments) => $assignments
                ->whereColumn('accreditation_led_assessor_assignments.indicator_id', 'accreditation_led_assessments.indicator_id')
                ->where('user_id', $user->id)
                ->whereNull('revoked_at'));
        }

        $assessments = $assessmentsQuery->get();
        $approved = $assessments->filter(fn ($assessment): bool => $assessment->currentApprovedRevision?->status === 'approved'
            && $assessment->currentApprovedRevision->rubric !== null);
        $scores = $approved->map(fn ($assessment): float => (float) $assessment->currentApprovedRevision->rubric->internal_score);

        $categories = AccreditationLedRubric::query()
            ->whereIn('indicator_id', $assessments->pluck('indicator_id'))
            ->orderByDesc('internal_score')
            ->orderBy('position')
            ->get()
            ->unique(fn (AccreditationLedRubric $rubric): string => (string) $rubric->internal_score)
            ->map(fn (AccreditationLedRubric $rubric): array => [
                'score' => (float) $rubric->internal_score,
                'label' => $rubric->category_label,
                'count' => $scores->filter(fn (float $value): bool => abs($value - (float) $rubric->internal_score) < 0.00001)->count(),
            ])->values()->all();

        return [
            'total' => $assessments->count(),
            'approved' => $approved->count(),
            'pending' => $assessments->count() - $approved->count(),
            'average' => $scores->isEmpty() ? null : round($scores->average(), 2),
            'distribution' => $categories,
            'assessments' => $assessments,
        ];
    }
}
