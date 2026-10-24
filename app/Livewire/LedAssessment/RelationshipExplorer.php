<?php

namespace App\Livewire\LedAssessment;

use App\Livewire\Component;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationLedAssessment;
use App\Models\AccreditationLedAssessmentCycle;
use App\Models\AccreditationLedIndicator;
use App\Models\AccreditationLedIndicatorItemMapping;
use App\Models\AccreditationProject;
use App\Services\AccreditationAuthorizationService;
use App\Services\AccreditationProjectContextService;
use App\Services\LedAssessmentAuthorizationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

#[Layout('components.layouts.app')]
class RelationshipExplorer extends Component
{
    #[Url(as: 'proyek', except: null)]
    public ?int $projectId = null;

    #[Url(as: 'siklus', except: null)]
    public ?int $cycleId = null;

    #[Url(as: 'tampilan', except: 'indicator')]
    public string $viewMode = 'indicator';

    #[Url(as: 'kriteria', except: '')]
    public string $criterionFilter = '';

    #[Url(as: 'cari', except: '')]
    public string $search = '';

    #[Url(as: 'indikator', except: null)]
    public ?int $selectedAssessmentId = null;

    #[Url(as: 'item', except: null)]
    public ?int $selectedItemId = null;

    public function mount(LedAssessmentAuthorizationService $authorization, AccreditationProjectContextService $context): void
    {
        abort_unless(Auth::user()?->can('project.view'), 403);
        $project = $context->resolve($context->ordered($authorization->accessibleProjects(Auth::user())->get()), $this->projectId);
        abort_unless($project, 403);
        $this->projectId = $project->id;
        $this->selectInitialCycle();
    }

    public function selectProject(int $projectId, LedAssessmentAuthorizationService $authorization, AccreditationProjectContextService $context): void
    {
        $project = $authorization->accessibleProjects(Auth::user())->whereKey($projectId)->first();
        abort_unless($project, 403);
        $context->select($authorization->accessibleProjects(Auth::user())->get(), $projectId);
        $this->projectId = $project->id;
        $this->cycleId = null;
        $this->selectedAssessmentId = null;
        $this->selectedItemId = null;
        $this->selectInitialCycle();
    }

    public function selectCycle(int $cycleId): void
    {
        $cycle = $this->authorizedCycle($cycleId);
        $this->cycleId = $cycle->id;
        $this->selectedAssessmentId = null;
        $this->selectedItemId = null;
    }

    public function setViewMode(string $viewMode): void
    {
        abort_unless(in_array($viewMode, ['indicator', 'item'], true), 404);
        $this->viewMode = $viewMode;
    }

    public function selectIndicator(int $assessmentId): void
    {
        $assessment = $this->assessmentQuery()->find($assessmentId);
        abort_unless($assessment, 403);
        Gate::authorize('view', $assessment);
        $this->selectedAssessmentId = $assessment->id;
        $this->selectedItemId = null;
    }

    public function selectItem(int $itemId, AccreditationAuthorizationService $authorization): void
    {
        $item = $authorization->accessibleCriteria(Auth::user(), $this->project())->with('items')->get()
            ->flatMap->items->firstWhere('id', $itemId);
        abort_unless($item, 403);
        $this->selectedItemId = $item->id;
        $this->selectedAssessmentId = null;
    }

    public function openMappedItem(int $itemId, AccreditationAuthorizationService $authorization): void
    {
        $this->selectItem($itemId, $authorization);
        $this->viewMode = 'item';
    }

    public function openMappedIndicator(int $indicatorId): void
    {
        $assessment = $this->assessmentQuery()->where('indicator_id', $indicatorId)->first();
        abort_unless($assessment, 403);
        Gate::authorize('view', $assessment);
        $this->selectedAssessmentId = $assessment->id;
        $this->selectedItemId = null;
        $this->viewMode = 'indicator';
    }

    public function render(
        LedAssessmentAuthorizationService $ledAuthorization,
        AccreditationAuthorizationService $documentAuthorization,
        AccreditationProjectContextService $context,
    ) {
        $projects = $context->ordered($ledAuthorization->accessibleProjects(Auth::user())->get());
        $project = $projects->firstWhere('id', $this->projectId);
        abort_unless($project, 403);
        $cycles = AccreditationLedAssessmentCycle::query()->with('instrumentVersion')->where('project_id', $project->id)
            ->orderByDesc('cycle_number')->get()->filter(fn ($cycle) => $ledAuthorization->canViewCycle(Auth::user(), $cycle));
        $cycle = $cycles->firstWhere('id', $this->cycleId);
        abort_if($this->cycleId !== null && ! $cycle, 403);

        if (! $cycle) {
            return view('livewire.led-assessment.relationship-explorer', compact('projects', 'project', 'cycles', 'cycle') + $this->emptyData())
                ->title(__('led_explorer.meta.title'));
        }

        $assessments = $this->assessmentQuery()->with([
            'indicator', 'currentWorkingRevision', 'currentApprovedRevision',
        ])->get();
        $assessmentIds = $assessments->pluck('id');
        $indicatorIds = $assessments->pluck('indicator_id');
        $visibleCriteria = $documentAuthorization->accessibleCriteria(Auth::user(), $project)->with(['items' => fn ($items) => $items->orderBy('position')])->orderBy('position')->get();
        $visibleCriterionIds = $visibleCriteria->pluck('id');
        $canViewDocuments = Auth::user()->can('document.view');
        $canViewVerification = $documentAuthorization->canViewReviewQueue(Auth::user());

        $allMappings = AccreditationLedIndicatorItemMapping::query()
            ->where('cycle_id', $cycle->id)->whereNull('revoked_at')->whereIn('indicator_id', $indicatorIds)
            ->with(['item.criterion'])->get();
        $mappings = $allMappings->whereIn('criterion_id', $visibleCriterionIds)->values();
        $assignments = $cycle->assignments()->whereNull('revoked_at')->whereIn('indicator_id', $indicatorIds)
            ->with('user')->get()->groupBy('indicator_id');

        $itemIds = $mappings->pluck('item_id')->unique();
        $requirements = collect();
        if ($canViewDocuments) {
            $requirementQuery = AccreditationDocumentRequirement::query()->whereIn('item_id', $itemIds)->with('documents');
            if ($canViewVerification) {
                $requirementQuery->with(['reviewSubmissions' => fn ($submissions) => $submissions->where('status', 'verified')->whereNotNull('decided_at')->whereNotNull('decided_by')]);
            }
            $requirements = $requirementQuery->get()->groupBy('item_id');
        }
        $evidenceByDocument = collect();
        if ($canViewDocuments && $assessmentIds->isNotEmpty()) {
            $evidenceByDocument = \App\Models\AccreditationLedAssessmentEvidence::query()
                ->whereIn('revision_id', $assessments->flatMap(fn ($assessment) => [$assessment->current_working_revision_id, $assessment->current_approved_revision_id])->filter())
                ->whereIn('document_id', $requirements->flatten(1)->flatMap->documents->pluck('id'))
                ->whereHas('verificationSubmission', fn ($submission) => $submission
                    ->where('status', 'verified')->whereNotNull('decided_at')->whereNotNull('decided_by'))
                ->get()->groupBy('document_id');
        }

        $mappingByIndicator = $mappings->groupBy('indicator_id');
        $mappingByItem = $mappings->groupBy('item_id');
        $indicatorRows = $assessments->map(function ($assessment) use ($mappingByIndicator, $assignments, $requirements, $evidenceByDocument, $canViewDocuments, $canViewVerification): array {
            $itemMappings = $mappingByIndicator->get($assessment->indicator_id, collect());
            return $this->indicatorRow($assessment, $itemMappings, $assignments->get($assessment->indicator_id, collect()), $requirements, $evidenceByDocument, $canViewDocuments, $canViewVerification);
        });
        $itemRows = $visibleCriteria->flatMap(function ($criterion) use ($mappingByItem, $requirements, $indicatorRows, $evidenceByDocument, $canViewDocuments, $canViewVerification) {
            return $criterion->items->map(fn ($item) => $this->itemRow($item, $criterion, $mappingByItem->get($item->id, collect()), $requirements, $indicatorRows, $evidenceByDocument, $canViewDocuments, $canViewVerification));
        });

        $criteria = $assessments->map(fn ($assessment) => $assessment->indicator->criterion_code)->unique()->sort()->values();
        $filteredIndicators = $indicatorRows->filter(fn (array $row) => ($this->criterionFilter === '' || $row['criterion'] === $this->criterionFilter)
            && ($this->search === '' || str_contains(mb_strtolower($row['number'].' '.$row['title']), mb_strtolower($this->search))))->take(100)->values();
        $filteredItems = $itemRows->filter(fn (array $row) => ($this->criterionFilter === '' || $row['criterion_code'] === $this->criterionFilter)
            && ($this->search === '' || str_contains(mb_strtolower($row['code'].' '.$row['title']), mb_strtolower($this->search))))->take(100)->values();
        $selectedIndicator = $filteredIndicators->firstWhere('assessment_id', $this->selectedAssessmentId) ?? $filteredIndicators->first();
        $selectedItem = $filteredItems->firstWhere('id', $this->selectedItemId) ?? ($this->viewMode === 'item' ? $filteredItems->first() : null);

        return view('livewire.led-assessment.relationship-explorer', compact(
            'projects', 'project', 'cycles', 'cycle', 'criteria', 'filteredIndicators', 'filteredItems', 'selectedIndicator', 'selectedItem', 'canViewDocuments',
        ) + [
            'summary' => [
                'indicators' => $assessments->count(),
                'mapped_indicators' => $allMappings->pluck('indicator_id')->unique()->count(),
                'unmapped_indicators' => $assessments->count() - $allMappings->pluck('indicator_id')->unique()->count(),
                'relationships' => $allMappings->count(),
                'verified_submissions' => $canViewVerification ? $requirements->flatten(1)->flatMap->reviewSubmissions->unique('id')->count() : null,
                'linked_evidence' => $canViewDocuments ? $evidenceByDocument->keys()->count() : null,
            ],
        ])->title(__('led_explorer.meta.title'));
    }

    private function indicatorRow($assessment, $mappings, $assignments, $requirements, $evidenceByDocument, bool $canViewDocuments, bool $canViewVerification): array
    {
        $items = $mappings->map(function ($mapping) use ($requirements, $evidenceByDocument, $canViewDocuments, $canViewVerification): array {
            $state = $this->evidenceState($requirements->get($mapping->item_id, collect()), $evidenceByDocument, $canViewDocuments, $canViewVerification);
            $evidence = $this->evidenceCounts($requirements->get($mapping->item_id, collect()), $evidenceByDocument, $canViewDocuments, $canViewVerification);
            return ['id' => $mapping->item_id, 'code' => $mapping->item->criterion->code.'.'.$mapping->item->number, 'title' => $mapping->item->title, 'role' => $mapping->mapping_role, 'state' => $state] + $evidence;
        });
        return [
            'assessment_id' => $assessment->id, 'indicator_id' => $assessment->indicator_id, 'criterion' => $assessment->indicator->criterion_code,
            'number' => $assessment->indicator->indicator_number, 'title' => $assessment->indicator->title, 'description' => $assessment->indicator->description,
            'mappings' => $items, 'assignments' => $assignments->groupBy('assignment_role')->map(fn ($group) => $group->pluck('user.name')->filter()->values()),
            'assessment_status' => $assessment->currentApprovedRevision?->status === 'approved' ? 'approved' : $assessment->currentWorkingRevision?->status,
        ];
    }

    private function itemRow($item, $criterion, $mappings, $requirements, $indicatorRows, $evidenceByDocument, bool $canViewDocuments, bool $canViewVerification): array
    {
        return [
            'id' => $item->id, 'criterion_code' => $criterion->code, 'criterion_name' => $criterion->name, 'code' => $criterion->code.'.'.$item->number,
            'title' => $item->title, 'workflow' => $canViewDocuments ? $requirements->get($item->id, collect())->pluck('workflow_status')->unique()->values() : collect(),
            'state' => $this->evidenceState($requirements->get($item->id, collect()), $evidenceByDocument, $canViewDocuments, $canViewVerification),
            'indicators' => $mappings->map(fn ($mapping) => ($row = $indicatorRows->firstWhere('indicator_id', $mapping->indicator_id)) ? ['id' => $row['indicator_id'], 'number' => $row['number'], 'title' => $row['title'], 'role' => $mapping->mapping_role] : null)->filter()->values(),
        ];
    }

    private function evidenceState($requirements, $evidenceByDocument, bool $canViewDocuments, bool $canViewVerification): string
    {
        if (! $canViewDocuments) return 'restricted';
        $documents = $requirements->flatMap->documents;
        if ($documents->isEmpty()) return 'no_document';
        if (! $canViewVerification) return 'verification_restricted';
        $verified = $requirements->flatMap->reviewSubmissions;
        if ($verified->isEmpty()) return 'submitted_unverified';
        return $documents->contains(fn ($document) => $evidenceByDocument->has($document->id)) ? 'linked' : 'verified_unlinked';
    }

    private function evidenceCounts($requirements, $evidenceByDocument, bool $canViewDocuments, bool $canViewVerification): array
    {
        if (! $canViewDocuments) return ['document_count' => null, 'verified_submission_count' => null, 'linked_evidence_count' => null];
        $documents = $requirements->flatMap->documents;
        return [
            'document_count' => $documents->count(),
            'verified_submission_count' => $canViewVerification ? $requirements->flatMap->reviewSubmissions->unique('id')->count() : null,
            'linked_evidence_count' => $documents->filter(fn ($document) => $evidenceByDocument->has($document->id))->count(),
        ];
    }

    private function selectInitialCycle(): void
    {
        $cycles = AccreditationLedAssessmentCycle::query()->with('project')->where('project_id', $this->projectId)->orderByDesc('cycle_number')->get();
        $cycle = $cycles->first(fn ($candidate) => app(LedAssessmentAuthorizationService::class)->canViewCycle(Auth::user(), $candidate));
        abort_if($this->cycleId !== null && ! $cycle, 403);
        $this->cycleId = $cycle?->id;
    }

    private function authorizedCycle(?int $id = null): AccreditationLedAssessmentCycle
    {
        $cycle = AccreditationLedAssessmentCycle::query()->with('project')->where('project_id', $this->projectId)->find($id ?? $this->cycleId);
        abort_unless($cycle, 403);
        Gate::authorize('view', $cycle);
        return $cycle;
    }

    private function assessmentQuery()
    {
        $cycle = $this->authorizedCycle();
        $query = AccreditationLedAssessment::query()->where('cycle_id', $cycle->id)->orderBy(AccreditationLedIndicator::query()->select('position')->whereColumn('accreditation_led_indicators.id', 'accreditation_led_assessments.indicator_id'));
        if (! app(LedAssessmentAuthorizationService::class)->hasCycleOversight(Auth::user(), $cycle)) {
            $query->whereIn('indicator_id', $cycle->assignments()->where('user_id', Auth::id())->whereNull('revoked_at')->select('indicator_id'));
        }
        return $query;
    }

    private function project(): AccreditationProject
    {
        return AccreditationProject::query()->findOrFail($this->projectId);
    }

    private function emptyData(): array
    {
        return ['criteria' => collect(), 'filteredIndicators' => collect(), 'filteredItems' => collect(), 'selectedIndicator' => null, 'selectedItem' => null, 'canViewDocuments' => false, 'summary' => ['indicators' => 0, 'mapped_indicators' => 0, 'unmapped_indicators' => 0, 'relationships' => 0, 'verified_submissions' => null, 'linked_evidence' => null]];
    }
}
