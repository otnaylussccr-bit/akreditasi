<?php

namespace App\Livewire\FollowUpLed;

use App\Models\AccreditationItem;
use App\Models\AccreditationProject;
use App\Services\AccreditationAuthorizationService;
use App\Services\AccreditationProgressService;
use App\Services\AccreditationProjectContextService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Follow Up LED | APU Accreditation')]
class Index extends Component
{
    use WithPagination;

    private const BIOTECH_PROJECT_CODE = 'APU-BIOTEK-2026';

    private const ITEMS_PER_PAGE = 10;

    private const WORKFLOW_STATUSES = [
        'in_progress',
        'not_started',
        'draft',
        'pending_review',
        'need_revision',
        'verified',
    ];

    #[Url(as: 'proyek', except: null)]
    public ?int $projectId = null;

    #[Url(as: 'kriteria', except: null)]
    public ?int $activeCriterionId = null;

    #[Url(as: 'cari', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $workflowStatus = '';

    #[Url(as: 'pic', except: '')]
    public string $sourcePic = '';

    public ?int $selectedItemId = null;

    public function mount(
        AccreditationAuthorizationService $authorization,
        AccreditationProjectContextService $context,
    ): void {
        $projects = $this->authorizedProjects($authorization, $context);
        $project = $context->resolve($projects, $this->projectId);
        abort_unless($project, 403);
        $this->projectId = $project->id;
        Gate::authorize('view', $project);

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
        $project = $context->select($this->authorizedProjects($authorization, $context), $projectId);
        Gate::authorize('view', $project);
        $this->projectId = $project->id;
        $this->activeCriterionId = $this->authorizedCriterionIds($authorization, $project)->first();
        $this->resetFilters();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'workflowStatus', 'sourcePic'], true)) {
            $this->selectedItemId = null;
            $this->resetPage();
        }
    }

    public function selectCriterion(int $criterionId, AccreditationAuthorizationService $authorization): void
    {
        $project = $this->authorizedProject($authorization);
        $criterionIds = $this->authorizedCriterionIds($authorization, $project);

        abort_unless($criterionIds->contains($criterionId), 403);

        $this->activeCriterionId = $criterionId;
        $this->selectedItemId = null;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'workflowStatus', 'sourcePic', 'selectedItemId']);
        $this->resetPage();
    }

    public function openDetail(int $itemId, AccreditationAuthorizationService $authorization): void
    {
        $project = $this->authorizedProject($authorization);
        $this->authorizeActiveCriterion($authorization, $project);

        $item = AccreditationItem::query()
            ->with('criterion')
            ->whereKey($itemId)
            ->where('criterion_id', $this->activeCriterionId)
            ->first();

        abort_unless($item, 403);
        Gate::authorize('view', $item->criterion);

        $this->selectedItemId = $item->id;
    }

    public function closeDetail(): void
    {
        $this->selectedItemId = null;
    }

    public function render(
        AccreditationAuthorizationService $authorization,
        AccreditationProgressService $progress,
        AccreditationProjectContextService $context,
    ) {
        $project = $this->authorizedProject($authorization);
        $projects = $this->authorizedProjects($authorization, $context);
        $criteria = $authorization->accessibleCriteria(Auth::user(), $project)
            ->withCount('items')
            ->orderBy('position')
            ->get();
        $criterionIds = $criteria->pluck('id');

        if ($this->activeCriterionId !== null) {
            abort_unless($criterionIds->contains($this->activeCriterionId), 403);
        }

        $activeCriterion = $criteria->firstWhere('id', $this->activeCriterionId);
        $items = $this->paginator(collect());
        $sourcePics = collect();

        if ($activeCriterion) {
            $items = $this->paginator($this->itemsForActiveCriterion($project, $progress));
            $sourcePics = AccreditationItem::query()
                ->where('criterion_id', $activeCriterion->id)
                ->when($project->code === self::BIOTECH_PROJECT_CODE, fn (Builder $query) => $query
                    ->where('number', '!=', 1))
                ->whereNotNull('source_pic')
                ->where('source_pic', '!=', '')
                ->select('source_pic')
                ->distinct()
                ->orderBy('source_pic')
                ->pluck('source_pic');
        }

        $selectedItemId = $this->resolvedSelectedItemId($items);

        $scopeItems = AccreditationItem::query()
            ->with('requirements')
            ->whereIn('criterion_id', $criterionIds)
            ->get();
        $summary = $progress->summarize($scopeItems, $project);

        return view('livewire.follow-up-led.index', [
            'projects' => $projects,
            'project' => $project,
            'criteria' => $criteria,
            'activeCriterion' => $activeCriterion,
            'items' => $items,
            'sourcePics' => $sourcePics,
            'selectedItem' => $this->selectedItem($project, $selectedItemId, $progress),
            'structureCount' => $summary['structure_count'],
            'headerCount' => $summary['header_count'],
            'workItemCount' => $summary['work_count'],
            'verifiedWorkItemCount' => $summary['verified_count'],
            'workProgress' => $summary['completion_percentage'],
            'statusOptions' => $progress->statusLabels(),
        ]);
    }

    private function authorizedProjects(
        AccreditationAuthorizationService $authorization,
        AccreditationProjectContextService $context,
    ): Collection {
        return $context->ordered($authorization->accessibleProjects(Auth::user())
            ->with('studyProgram')
            ->get());
    }

    private function authorizedProject(AccreditationAuthorizationService $authorization): AccreditationProject
    {
        $project = $authorization->accessibleProjects(Auth::user())
            ->with('studyProgram')
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

    private function authorizeActiveCriterion(
        AccreditationAuthorizationService $authorization,
        AccreditationProject $project,
    ): void {
        abort_unless(
            $this->activeCriterionId !== null
            && $this->authorizedCriterionIds($authorization, $project)->contains($this->activeCriterionId),
            403,
        );
    }

    private function itemsForActiveCriterion(
        AccreditationProject $project,
        AccreditationProgressService $progress,
    ): Collection {
        $baseQuery = AccreditationItem::query()
            ->with(['criterion', 'requirements'])
            ->where('criterion_id', $this->activeCriterionId);
        $headerItem = null;

        if ($project->code === self::BIOTECH_PROJECT_CODE) {
            $headerItem = (clone $baseQuery)
                ->where('number', 1)
                ->first();
            $baseQuery->where('number', '!=', 1);
        }

        $items = $baseQuery
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $search = trim($this->search);

                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('title', 'like', "%{$search}%")
                        ->orWhereHas('requirements', fn (Builder $requirements) => $requirements
                            ->where('title', 'like', "%{$search}%"));
                });
            })
            ->when($this->sourcePic !== '', fn (Builder $query) => $query
                ->where('source_pic', $this->sourcePic))
            ->orderBy('position')
            ->get();

        if ($headerItem) {
            $items->prepend($headerItem);
        }

        $items->each(fn (AccreditationItem $item) => $progress->decorateItem($item, $project));

        if (! in_array($this->workflowStatus, self::WORKFLOW_STATUSES, true)) {
            return $items;
        }

        return $items->filter(function (AccreditationItem $item): bool {
            if ($item->is_led_header) {
                return true;
            }

            if ($this->workflowStatus === 'in_progress') {
                return $item->aggregate_status === 'in_progress';
            }

            return $item->requirements
                ->where('is_required', true)
                ->contains('workflow_status', $this->workflowStatus);
        })->values();
    }

    private function resolvedSelectedItemId(LengthAwarePaginator $items): ?int
    {
        $visibleItems = $items->getCollection();

        if ($this->selectedItemId && $visibleItems->contains('id', $this->selectedItemId)) {
            return $this->selectedItemId;
        }

        return $visibleItems->firstWhere('is_led_header', false)?->id
            ?? $visibleItems->first()?->id;
    }

    private function selectedItem(
        AccreditationProject $project,
        ?int $itemId,
        AccreditationProgressService $progress,
    ): ?AccreditationItem {
        if (! $itemId) {
            return null;
        }

        $item = AccreditationItem::query()
            ->with([
                'criterion',
                'requirements.documents' => fn ($documents) => $documents
                    ->orderByDesc('uploaded_at')
                    ->orderByDesc('id'),
            ])
            ->whereKey($itemId)
            ->where('criterion_id', $this->activeCriterionId)
            ->first();

        abort_unless($item, 403);
        Gate::authorize('view', $item->criterion);

        return $progress->decorateItem($item, $project);
    }

    private function paginator(Collection $items): LengthAwarePaginator
    {
        $page = $this->getPage();

        return new LengthAwarePaginator(
            $items->forPage($page, self::ITEMS_PER_PAGE)->values(),
            $items->count(),
            self::ITEMS_PER_PAGE,
            $page,
            [
                'path' => route('follow-up-led.index'),
                'query' => request()->query(),
            ],
        );
    }
}
