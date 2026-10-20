<?php

namespace App\Services;

use App\Models\AccreditationCriterion;
use App\Models\AccreditationDocument;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationProject;
use App\Models\AccreditationReviewSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AccreditationAuthorizationService
{
    public function __construct(
        private readonly AccreditationProjectLifecycleService $lifecycle,
    ) {}

    public function accessibleProjects(User $user): Builder
    {
        $query = AccreditationProject::query();

        if (! $user->can('project.view')) {
            return $query->whereKey([]);
        }

        if ($user->hasRole('Super Admin')) {
            return $query;
        }

        $isProjectManager = $user->hasRole('Project Manager');
        $isPic = $user->hasRole('PIC Kriteria');
        $isReviewer = $user->hasRole('Reviewer');
        $isViewer = $user->hasRole('Viewer');

        if (! $isProjectManager && ! $isPic && ! $isReviewer && ! $isViewer) {
            return $query->whereKey([]);
        }

        return $query->where(function (Builder $scope) use (
            $user,
            $isProjectManager,
            $isPic,
            $isReviewer,
            $isViewer,
        ): void {
            if ($isProjectManager) {
                $scope->orWhereHas('memberships', fn (Builder $memberships) => $memberships
                    ->where('user_id', $user->id)
                    ->where('membership_role', 'project_manager')
                    ->where('is_active', true));
            }

            if ($isViewer) {
                $scope->orWhereHas('memberships', fn (Builder $memberships) => $memberships
                    ->where('user_id', $user->id)
                    ->where('membership_role', 'viewer')
                    ->where('is_active', true));
            }

            if ($isPic) {
                $scope->orWhereHas('criteria.assignments', fn (Builder $assignments) => $assignments
                    ->where('user_id', $user->id)
                    ->where('assignment_role', 'pic')
                    ->where('is_active', true));
            }

            if ($isReviewer) {
                $scope->orWhereHas('criteria.assignments', fn (Builder $assignments) => $assignments
                    ->where('user_id', $user->id)
                    ->where('assignment_role', 'reviewer')
                    ->where('is_active', true));
            }
        });
    }

    public function canViewProject(User $user, AccreditationProject $project): bool
    {
        if (! $user->can('project.view')) {
            return false;
        }

        if ($user->hasRole('Super Admin')) {
            return true;
        }

        return $this->accessibleProjects($user)
            ->whereKey($project->id)
            ->exists();
    }

    public function canViewCriterion(User $user, AccreditationCriterion $criterion): bool
    {
        if (! $user->can('criterion.view')) {
            return false;
        }

        if ($user->hasRole('Super Admin')) {
            return true;
        }

        return $this->isActiveProjectManager($user, $criterion->project)
            || $this->isActivePic($user, $criterion)
            || $this->isActiveReviewer($user, $criterion)
            || ($user->hasRole('Viewer') && $this->hasActiveMembership($user, $criterion->project, 'viewer'));
    }

    public function accessibleCriteria(User $user, AccreditationProject $project): Builder
    {
        $query = AccreditationCriterion::query()->where('project_id', $project->id);

        if (! $this->canViewProject($user, $project) || ! $user->can('criterion.view')) {
            return $query->whereKey([]);
        }

        if ($user->hasRole('Super Admin')
            || $this->isActiveProjectManager($user, $project)
            || ($user->hasRole('Viewer') && $this->hasActiveMembership($user, $project, 'viewer'))) {
            return $query;
        }

        $assignmentRoles = [];

        if ($user->hasRole('PIC Kriteria')) {
            $assignmentRoles[] = 'pic';
        }

        if ($user->hasRole('Reviewer')) {
            $assignmentRoles[] = 'reviewer';
        }

        if ($assignmentRoles === []) {
            return $query->whereKey([]);
        }

        return $query->whereHas('assignments', fn (Builder $assignments) => $assignments
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereIn('assignment_role', $assignmentRoles));
    }

    public function isActiveProjectManager(User $user, AccreditationProject $project): bool
    {
        return $user->hasRole('Project Manager')
            && $this->hasActiveMembership($user, $project, 'project_manager');
    }

    public function isActivePic(User $user, AccreditationCriterion $criterion): bool
    {
        return $user->hasRole('PIC Kriteria')
            && $this->hasActiveAssignment($user, $criterion, 'pic');
    }

    public function isActiveReviewer(User $user, AccreditationCriterion $criterion): bool
    {
        return $user->hasRole('Reviewer')
            && $this->hasActiveAssignment($user, $criterion, 'reviewer');
    }

    public function canManageProject(User $user, AccreditationProject $project): bool
    {
        return $user->can('project.manage')
            && ($user->hasRole('Super Admin') || $this->isActiveProjectManager($user, $project));
    }

    public function manageableProjects(User $user): Builder
    {
        $query = $this->accessibleProjects($user);

        if (! $user->is_active || ! $user->can('project.manage')) {
            return $query->whereKey([]);
        }

        if ($user->hasRole('Super Admin')) {
            return $query;
        }

        if (! $user->hasRole('Project Manager')) {
            return $query->whereKey([]);
        }

        return $query->whereHas('memberships', fn (Builder $memberships) => $memberships
            ->where('user_id', $user->id)
            ->where('membership_role', 'project_manager')
            ->where('is_active', true));
    }

    public function canManageAssignments(User $user, AccreditationProject $project): bool
    {
        return $user->can('assignment.manage')
            && ($user->hasRole('Super Admin') || $this->isActiveProjectManager($user, $project));
    }

    public function canUploadForCriterion(User $user, AccreditationCriterion $criterion): bool
    {
        return $this->lifecycle->allowsWorkflowMutation($criterion->project)
            && $this->canAccessUploadForCriterion($user, $criterion);
    }

    public function canAccessUploadForCriterion(User $user, AccreditationCriterion $criterion): bool
    {
        return $user->can('document.upload')
            && ($user->hasRole('Super Admin') || $this->isActivePic($user, $criterion));
    }

    public function canViewRequirement(User $user, AccreditationDocumentRequirement $requirement): bool
    {
        return $user->can('document.view')
            && $this->canViewCriterion($user, $requirement->item->criterion);
    }

    public function canUploadRequirement(User $user, AccreditationDocumentRequirement $requirement): bool
    {
        return $this->canAccessRequirementUpload($user, $requirement)
            && $this->lifecycle->allowsWorkflowMutation($requirement->item->criterion->project)
            && ! in_array($requirement->workflow_status, ['pending_review', 'verified'], true);
    }

    public function canAccessRequirementUpload(User $user, AccreditationDocumentRequirement $requirement): bool
    {
        $criterion = $requirement->item->criterion;

        return $this->canAccessUploadForCriterion($user, $criterion)
            && ! ($criterion->project->code === 'APU-BIOTEK-2026' && $requirement->item->number === 1);
    }

    public function canViewDocument(User $user, AccreditationDocument $document): bool
    {
        return $this->canViewRequirement($user, $document->requirement);
    }

    public function accessibleDocuments(User $user, AccreditationProject $project): Builder
    {
        $query = AccreditationDocument::query();

        if (! $user->can('document.view')) {
            return $query->whereKey([]);
        }

        $criterionIds = $this->accessibleCriteria($user, $project)
            ->select('accreditation_criteria.id');

        return $query->whereHas('requirement.item', fn (Builder $items) => $items
            ->whereIn('criterion_id', $criterionIds));
    }

    public function canSubmitForCriterion(User $user, AccreditationCriterion $criterion): bool
    {
        return $user->can('document.submit')
            && $this->lifecycle->allowsWorkflowMutation($criterion->project)
            && ($user->hasRole('Super Admin')
                || $this->isActiveProjectManager($user, $criterion->project)
                || $this->isActivePic($user, $criterion));
    }

    public function canReviewSubmission(User $user, AccreditationReviewSubmission $submission): bool
    {
        if ($user->hasRole('Super Admin')
            || $submission->submitted_by === $user->id
            || ! $user->can('document.review')) {
            return false;
        }

        if ($submission->documents()
            ->where('accreditation_documents.uploaded_by', $user->id)
            ->exists()) {
            return false;
        }

        $criterion = $submission->requirement->item->criterion;

        return $this->isActiveReviewer($user, $criterion);
    }

    public function canViewReviewQueue(User $user): bool
    {
        return ($user->hasRole('Super Admin') && $user->can('document.view'))
            || ($user->hasRole('Reviewer') && $user->can('document.review'));
    }

    public function reviewableProjects(User $user): Builder
    {
        $query = AccreditationProject::query()->whereHas('criteria');

        if (! $this->canViewReviewQueue($user)) {
            return $query->whereKey([]);
        }

        if ($user->hasRole('Super Admin')) {
            return $query;
        }

        return $query->whereHas('criteria.assignments', fn (Builder $assignments) => $assignments
            ->where('user_id', $user->id)
            ->where('assignment_role', 'reviewer')
            ->where('is_active', true));
    }

    public function canViewReviewSubmission(User $user, AccreditationReviewSubmission $submission): bool
    {
        if ($user->hasRole('Super Admin')) {
            return $this->canViewRequirement($user, $submission->requirement);
        }

        return $this->canReviewSubmission($user, $submission);
    }

    private function hasActiveMembership(User $user, AccreditationProject $project, string $role): bool
    {
        return $project->memberships()
            ->where('user_id', $user->id)
            ->where('membership_role', $role)
            ->where('is_active', true)
            ->exists();
    }

    private function hasActiveAssignment(User $user, AccreditationCriterion $criterion, string $role): bool
    {
        return $criterion->assignments()
            ->where('user_id', $user->id)
            ->where('assignment_role', $role)
            ->where('is_active', true)
            ->exists();
    }
}
