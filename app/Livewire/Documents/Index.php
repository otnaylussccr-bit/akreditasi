<?php

namespace App\Livewire\Documents;

use App\Livewire\Component;
use App\Models\AccreditationItem;
use App\Models\AccreditationProject;
use App\Services\AccreditationAuthorizationService;
use App\Services\AccreditationProjectContextService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

#[Layout('components.layouts.app')]
class Index extends Component
{
    private const BIOTECH_PROJECT_CODE = 'APU-BIOTEK-2026';

    #[Url(as: 'proyek', except: null)]
    public ?int $projectId = null;

    #[Url(as: 'kriteria', except: null)]
    public ?int $activeCriterionId = null;

    public function mount(
        AccreditationAuthorizationService $authorization,
        AccreditationProjectContextService $context,
    ): void {
        $projects = $context->ordered($this->authorizedProjects($authorization));
        $project = $context->resolve($projects, $this->projectId);
        abort_unless($project, 403);
        $this->projectId = $project->id;
        $criterionIds = $this->authorizedCriterionIds($authorization, $project);

        if ($this->activeCriterionId !== null) {
            abort_unless($criterionIds->contains($this->activeCriterionId), 403);
        } else {
            $this->activeCriterionId = $criterionIds->first();
        }
    }

    public function selectProject(
        int $projectId,
        AccreditationAuthorizationService $authorization,
        AccreditationProjectContextService $context,
    ): void {
        $project = $authorization->accessibleProjects(Auth::user())
            ->whereKey($projectId)
            ->first();

        abort_unless($project, 403);
        Gate::authorize('view', $project);

        $this->projectId = $context->select($this->authorizedProjects($authorization), $projectId)->id;
        $this->activeCriterionId = $this->authorizedCriterionIds($authorization, $project)->first();
    }

    public function selectCriterion(int $criterionId, AccreditationAuthorizationService $authorization): void
    {
        $project = $this->authorizedProject($authorization);
        abort_unless($this->authorizedCriterionIds($authorization, $project)->contains($criterionId), 403);

        $this->activeCriterionId = $criterionId;
    }

    public function render(
        AccreditationAuthorizationService $authorization,
        AccreditationProjectContextService $context,
    ) {
        $projects = $context->ordered($this->authorizedProjects($authorization));
        $project = $projects->firstWhere('id', $this->projectId);
        abort_unless($project, 403);
        Gate::authorize('view', $project);

        $criteria = $authorization->accessibleCriteria(Auth::user(), $project)
            ->withCount('items')
            ->orderBy('position')
            ->get();
        $criterionIds = $criteria->pluck('id');

        if ($this->activeCriterionId !== null) {
            abort_unless($criterionIds->contains($this->activeCriterionId), 403);
        }

        $activeCriterion = $criteria->firstWhere('id', $this->activeCriterionId);
        $items = collect();

        if ($activeCriterion) {
            Gate::authorize('view', $activeCriterion);
            $items = AccreditationItem::query()
                ->with([
                    'requirements.documents' => fn ($documents) => $documents
                        ->orderByDesc('uploaded_at')
                        ->orderByDesc('id'),
                ])
                ->where('criterion_id', $activeCriterion->id)
                ->orderBy('position')
                ->get()
                ->each(fn (AccreditationItem $item) => $item->setAttribute(
                    'is_led_header',
                    $project->code === self::BIOTECH_PROJECT_CODE && $item->number === 1,
                ));
        }

        return view('livewire.documents.index', [
            'projects' => $projects,
            'project' => $project,
            'criteria' => $criteria,
            'activeCriterion' => $activeCriterion,
            'items' => $items,
            'statusLabels' => __('documents.status'),
        ])->title(__('documents.meta.index_title'));
    }

    private function authorizedProjects(AccreditationAuthorizationService $authorization): Collection
    {
        return $authorization->accessibleProjects(Auth::user())
            ->with('studyProgram')
            ->get();
    }

    private function authorizedProject(AccreditationAuthorizationService $authorization): AccreditationProject
    {
        $project = $authorization->accessibleProjects(Auth::user())
            ->whereKey($this->projectId)
            ->first();

        abort_unless($project, 403);
        Gate::authorize('view', $project);

        return $project;
    }

    private function authorizedCriterionIds(
        AccreditationAuthorizationService $authorization,
        AccreditationProject $project,
    ): Collection {
        return $authorization->accessibleCriteria(Auth::user(), $project)
            ->orderBy('position')
            ->pluck('id');
    }
}
