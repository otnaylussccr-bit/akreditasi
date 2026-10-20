<?php

namespace App\Services;

use App\Models\AccreditationLedAssessment;
use App\Models\AccreditationLedAssessmentCycle;
use App\Models\AccreditationLedAssessmentRevision;
use App\Models\AccreditationProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class LedAssessmentAuthorizationService
{
    public function __construct(
        private readonly AccreditationAuthorizationService $projects,
        private readonly AccreditationProjectLifecycleService $lifecycle,
    ) {}

    public function accessibleProjects(User $user): Builder
    {
        $query = AccreditationProject::query();

        if (! $user->is_active || ! $user->can('project.view')) {
            return $query->whereKey([]);
        }

        if ($user->hasRole('Super Admin')) {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($user): void {
            $scope->whereIn('id', $this->projects->accessibleProjects($user)->select('accreditation_projects.id'))
                ->orWhereHas('assessmentCycles.assignments', fn (Builder $assignments) => $assignments
                    ->where('user_id', $user->id)
                    ->whereNull('revoked_at'));
        });
    }

    public function canViewCycle(User $user, AccreditationLedAssessmentCycle $cycle): bool
    {
        if (! $user->is_active || ! $user->can('project.view')) {
            return false;
        }

        if ($this->hasCycleOversight($user, $cycle)) {
            return true;
        }

        return $cycle->assignments()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->exists();
    }

    public function canManageCycle(User $user, AccreditationLedAssessmentCycle $cycle): bool
    {
        return $user->is_active
            && $user->can('project.manage')
            && $this->lifecycle->allowsWorkflowMutation($cycle->project)
            && ($user->hasRole('Super Admin') || $this->projects->isActiveProjectManager($user, $cycle->project));
    }

    public function canManageAssignments(User $user, AccreditationLedAssessmentCycle $cycle): bool
    {
        return $user->can('assignment.manage') && $this->canManageCycle($user, $cycle);
    }

    public function hasCycleOversight(User $user, AccreditationLedAssessmentCycle $cycle): bool
    {
        return $user->is_active
            && $user->can('project.view')
            && ($user->hasRole('Super Admin') || $this->projects->isActiveProjectManager($user, $cycle->project));
    }

    public function canViewAssessment(User $user, AccreditationLedAssessment $assessment): bool
    {
        return $this->canViewCycle($user, $assessment->cycle)
            && ($this->hasCycleOversight($user, $assessment->cycle)
                || $this->hasActiveAssignment($user, $assessment, ['assessor_primary', 'assessor_support', 'reviewer']));
    }

    public function canEditAssessment(User $user, AccreditationLedAssessment $assessment): bool
    {
        return $user->is_active
            && $user->can('project.view')
            && $assessment->cycle->status === 'active'
            && $this->lifecycle->allowsWorkflowMutation($assessment->project)
            && $this->hasActiveAssignment($user, $assessment, ['assessor_primary', 'assessor_support']);
    }

    public function canSubmitAssessment(User $user, AccreditationLedAssessmentRevision $revision): bool
    {
        return $this->canEditAssessment($user, $revision->assessment)
            && $this->hasActiveAssignment($user, $revision->assessment, ['assessor_primary']);
    }

    public function canReviewRevision(User $user, AccreditationLedAssessmentRevision $revision): bool
    {
        return $user->is_active
            && $user->can('project.view')
            && $revision->assessment->cycle->status === 'active'
            && $this->lifecycle->allowsWorkflowMutation($revision->assessment->project)
            && $revision->created_by !== $user->id
            && $revision->submitted_by !== $user->id
            && $this->hasActiveAssignment($user, $revision->assessment, ['reviewer']);
    }

    public function canApproveRevision(User $user, AccreditationLedAssessmentRevision $revision): bool
    {
        return $this->canReviewRevision($user, $revision);
    }

    private function hasActiveAssignment(User $user, AccreditationLedAssessment $assessment, array $roles): bool
    {
        return $assessment->cycle->assignments()
            ->where('indicator_id', $assessment->indicator_id)
            ->where('user_id', $user->id)
            ->whereIn('assignment_role', $roles)
            ->whereNull('revoked_at')
            ->exists();
    }
}
