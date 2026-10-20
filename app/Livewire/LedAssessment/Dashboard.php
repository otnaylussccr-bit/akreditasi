<?php

namespace App\Livewire\LedAssessment;

use App\Livewire\Component;
use App\Models\AccreditationLedAssessmentCycle;
use App\Services\AccreditationProjectContextService;
use App\Services\LedAssessmentAuthorizationService;
use App\Services\LedAssessmentDashboardService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    #[Url(as: 'proyek', except: null)]
    public ?int $projectId = null;

    #[Url(as: 'siklus', except: null)]
    public ?int $cycleId = null;

    public function mount(LedAssessmentAuthorizationService $authorization, AccreditationProjectContextService $context): void
    {
        abort_unless(Auth::user()->can('dashboard.view'), 403);
        $projects = $context->ordered($authorization->accessibleProjects(Auth::user())->get());
        $project = $context->resolve($projects, $this->projectId);
        abort_unless($project, 403);
        $this->projectId = $project->id;
        $cycles = $this->reportableCycles($project->id, $authorization);
        $cycle = $this->cycleId ? $cycles->firstWhere('id', $this->cycleId) : $cycles->first();
        abort_if($this->cycleId !== null && ! $cycle, 403);
        $this->cycleId = $cycle?->id;
    }

    public function selectProject(int $projectId, LedAssessmentAuthorizationService $authorization, AccreditationProjectContextService $context): void
    {
        $project = $authorization->accessibleProjects(Auth::user())->whereKey($projectId)->first();
        abort_unless($project, 403);
        $context->select($authorization->accessibleProjects(Auth::user())->get(), $projectId);
        $this->projectId = $project->id;
        $this->cycleId = $this->reportableCycles($project->id, $authorization)->first()?->id;
    }

    public function selectCycle(int $cycleId, LedAssessmentAuthorizationService $authorization): void
    {
        $cycle = $this->reportableCycles((int) $this->projectId, $authorization)->firstWhere('id', $cycleId);
        abort_unless($cycle, 403);
        $this->cycleId = $cycle->id;
    }

    public function render(LedAssessmentAuthorizationService $authorization, AccreditationProjectContextService $context, LedAssessmentDashboardService $dashboard)
    {
        $projects = $context->ordered($authorization->accessibleProjects(Auth::user())->get());
        $project = $projects->firstWhere('id', $this->projectId);
        abort_unless($project, 403);
        $cycles = $this->reportableCycles($project->id, $authorization);
        $cycle = $this->cycleId ? $cycles->firstWhere('id', $this->cycleId) : $cycles->first();
        abort_if($this->cycleId !== null && ! $cycle, 403);

        return view('livewire.led-assessment.dashboard', [
            'projects' => $projects,
            'project' => $project,
            'cycle' => $cycle,
            'cycles' => $cycles,
            'hasAnyCycle' => AccreditationLedAssessmentCycle::query()->where('project_id', $project->id)->exists(),
            'canOpenAssessment' => ! Auth::user()->hasExactRoles('Viewer')
                || Auth::user()->ledAssessorAssignments()
                    ->whereHas('cycle', fn ($query) => $query->where('project_id', $project->id))
                    ->whereNull('revoked_at')
                    ->exists(),
            'summary' => $cycle ? $dashboard->summary($cycle, Auth::user()) : null,
        ])->title(__('led_assessment.meta.dashboard'));
    }

    private function reportableCycles(int $projectId, LedAssessmentAuthorizationService $authorization)
    {
        return AccreditationLedAssessmentCycle::query()
            ->where('project_id', $projectId)
            ->whereIn('status', ['active', 'closed', 'finalized'])
            ->orderByDesc('cycle_number')
            ->get()
            ->filter(fn (AccreditationLedAssessmentCycle $cycle): bool => $authorization->canViewCycle(Auth::user(), $cycle))
            ->values();
    }
}
