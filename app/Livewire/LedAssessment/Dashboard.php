<?php

namespace App\Livewire\LedAssessment;

use App\Livewire\Component;
use App\Services\AccreditationProjectContextService;
use App\Services\LedAssessmentAuthorizationService;
use App\Services\LedAssessmentDashboardService;
use App\Services\OperationalModuleAccessService;
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

    public string $lastUpdated = '';

    public function mount(LedAssessmentAuthorizationService $authorization, AccreditationProjectContextService $context): void
    {
        abort_unless(Auth::user()->can('dashboard.view'), 403);
        $projects = $context->ordered($authorization->dashboardProjects(Auth::user())->get());
        $project = $context->resolve($projects, $this->projectId);
        abort_unless($project, 403);
        $this->projectId = $project->id;
        $cycles = $authorization->reportableCycles(Auth::user(), $project->id)->orderByDesc('cycle_number')->get();
        $cycle = $this->cycleId ? $cycles->firstWhere('id', $this->cycleId) : $cycles->first();
        abort_if($this->cycleId !== null && ! $cycle, 403);
        $this->cycleId = $cycle?->id;
        $this->lastUpdated = now()->toIso8601String();
    }

    public function selectProject(int $projectId, LedAssessmentAuthorizationService $authorization, AccreditationProjectContextService $context): void
    {
        $project = $authorization->dashboardProjects(Auth::user())->whereKey($projectId)->first();
        abort_unless($project, 403);
        $context->select($authorization->dashboardProjects(Auth::user())->get(), $projectId);
        $this->projectId = $project->id;
        $this->cycleId = $authorization->reportableCycles(Auth::user(), $project->id)->orderByDesc('cycle_number')->value('id');
    }

    public function selectCycle(int $cycleId, LedAssessmentAuthorizationService $authorization): void
    {
        $cycle = $authorization->reportableCycles(Auth::user(), (int) $this->projectId)->find($cycleId);
        abort_unless($cycle, 403);
        $this->cycleId = $cycle->id;
    }

    public function refreshDashboard(LedAssessmentAuthorizationService $authorization): void
    {
        $user = Auth::user();
        abort_unless($user !== null, 403);

        $project = $authorization->dashboardProjects($user)->whereKey($this->projectId)->first();
        abort_unless($project, 403);

        if ($this->cycleId !== null) {
            $cycle = $authorization->reportableCycles($user, $project->id)->find($this->cycleId);
            abort_unless($cycle, 403);
        }

        $this->lastUpdated = now()->toIso8601String();
    }

    public function render(
        LedAssessmentAuthorizationService $authorization,
        AccreditationProjectContextService $context,
        LedAssessmentDashboardService $dashboard,
        OperationalModuleAccessService $operational,
    ) {
        $projects = $context->ordered($authorization->dashboardProjects(Auth::user())->get());
        $project = $projects->firstWhere('id', $this->projectId);
        abort_unless($project, 403);
        $cycles = $authorization->reportableCycles(Auth::user(), $project->id)->orderByDesc('cycle_number')->get();
        $cycle = $this->cycleId ? $cycles->firstWhere('id', $this->cycleId) : $cycles->first();
        abort_if($this->cycleId !== null && ! $cycle, 403);

        return view('livewire.led-assessment.dashboard', [
            'projects' => $projects,
            'project' => $project,
            'cycle' => $cycle,
            'cycles' => $cycles,
            'canOpenAssessment' => $operational->allows(Auth::user(), 'led-assessment.index'),
            'summary' => $cycle !== null && $cycle->status !== 'draft'
                ? $dashboard->summary($cycle, Auth::user())
                : null,
        ])->title(__('led_assessment.meta.dashboard'));
    }
}
