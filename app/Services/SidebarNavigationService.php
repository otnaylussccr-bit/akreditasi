<?php

namespace App\Services;

use App\Models\AccreditationProject;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

class SidebarNavigationService
{
    public function __construct(
        private readonly AccreditationAuthorizationService $authorization,
        private readonly AccreditationProjectContextService $context,
        private readonly AccreditationWorkflowActivityService $activity,
        private readonly LedAssessmentAuthorizationService $ledAuthorization,
        private readonly OperationalModuleAccessService $operational,
    ) {}

    public function for(User $user): array
    {
        $phaseOneProjects = $this->projects($this->authorization->accessibleProjects($user)->get());
        $phaseOneProject = $this->context->preferred($phaseOneProjects);
        $operationalAccess = $this->operational->allows($user, 'documents.index');

        $dashboardProjects = $this->projects($this->ledAuthorization->dashboardProjects($user)->get());
        $dashboardProject = $this->context->preferred($dashboardProjects);
        $dashboardCycle = $dashboardProject
            ? $this->ledAuthorization->reportableCycles($user, $dashboardProject->id)
                ->orderByDesc('cycle_number')
                ->first()
            : null;

        [$ledProject, $ledCycleId] = $this->ledAssessmentContext($user);

        $reviewableProjects = $operationalAccess
            ? $this->projects($this->authorization->reviewableProjects($user)->get())
            : collect();
        $reviewableProject = $this->context->preferred($reviewableProjects);

        $manageableProjects = $operationalAccess
            ? $this->projects($this->authorization->manageableProjects($user)->get())
            : collect();
        $manageableProject = $this->context->preferred($manageableProjects);

        $canManageUsers = Gate::forUser($user)->allows('viewAny', User::class);
        $canManageRoles = Gate::forUser($user)->allows('viewAny', Role::class);

        return [
            'dashboard' => $this->entry($user->can('dashboard.view'), $this->projectParams($phaseOneProject)),
            'assessment_dashboard' => $this->entry(
                $dashboardProject !== null,
                $this->projectParams($dashboardProject, $dashboardCycle?->id),
            ),
            'documents' => $this->entry($operationalAccess && $phaseOneProject !== null, $this->projectParams($phaseOneProject)),
            'repository' => $this->entry(
                $operationalAccess && $phaseOneProject !== null && $user->can('document.view'),
                $this->projectParams($phaseOneProject),
            ),
            'verification' => $this->entry($reviewableProject !== null, $this->projectParams($reviewableProject)),
            'follow_up_led' => $this->entry($operationalAccess && $phaseOneProject !== null, $this->projectParams($phaseOneProject)),
            'led_assessment' => $this->entry($ledProject !== null, $this->projectParams($ledProject, $ledCycleId)),
            'led_configuration' => $this->entry($manageableProject !== null, $this->projectParams($manageableProject)),
            'team_activity' => $this->entry(
                $operationalAccess && $phaseOneProject !== null && $this->activity->canView($user),
                $this->projectParams($phaseOneProject),
            ),
            'users' => $this->entry($operationalAccess && $canManageUsers),
            'roles' => $this->entry($operationalAccess && $canManageRoles),
        ];
    }

    private function ledAssessmentContext(User $user): array
    {
        if (! $this->operational->allows($user, 'led-assessment.index')) {
            return [null, null];
        }

        if (! $user->hasExactRoles('Viewer')) {
            $projects = $this->projects($this->ledAuthorization->accessibleProjects($user)->get());

            return [$this->context->preferred($projects), null];
        }

        $assignments = $user->ledAssessorAssignments()
            ->whereNull('revoked_at')
            ->with('cycle.project')
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get();
        $projects = $this->projects($assignments->pluck('cycle.project')->filter()->unique('id')->values());
        $project = $this->context->preferred($projects);
        $assignment = $project
            ? $assignments->first(fn ($candidate): bool => $candidate->cycle->project_id === $project->id)
            : null;

        return [$project, $assignment?->cycle_id];
    }

    private function projects(Collection $projects): Collection
    {
        return $this->context->ordered($projects);
    }

    private function projectParams(?AccreditationProject $project, ?int $cycleId = null): array
    {
        if (! $project) {
            return [];
        }

        return array_filter([
            'proyek' => $project->id,
            'siklus' => $cycleId,
        ], fn ($value): bool => $value !== null);
    }

    private function entry(bool $visible, array $params = []): array
    {
        return compact('visible', 'params');
    }
}
