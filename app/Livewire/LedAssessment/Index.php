<?php

namespace App\Livewire\LedAssessment;

use App\Livewire\Component;
use App\Models\AccreditationDocument;
use App\Models\AccreditationLedAssessment;
use App\Models\AccreditationLedAssessmentCycle;
use App\Models\AccreditationLedAssessmentRevision;
use App\Models\AccreditationLedIndicator;
use App\Models\AccreditationLedIndicatorItemMapping;
use App\Services\AccreditationProjectContextService;
use App\Services\LedAssessmentAuthorizationService;
use App\Services\LedAssessmentEvidenceService;
use App\Services\LedAssessmentWorkflowService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

#[Layout('components.layouts.app')]
class Index extends Component
{
    #[Url(as: 'proyek', except: null)]
    public ?int $projectId = null;

    #[Url(as: 'siklus', except: null)]
    public ?int $cycleId = null;

    #[Url(as: 'indikator', except: null)]
    public ?int $selectedAssessmentId = null;

    public ?int $rubricId = null;

    public string $justification = '';

    public string $gapAnalysis = '';

    public string $recommendation = '';

    public string $decisionNote = '';

    public ?string $draftFingerprint = null;

    public string $notice = '';

    public function mount(LedAssessmentAuthorizationService $authorization, AccreditationProjectContextService $context): void
    {
        abort_unless(Auth::user()->can('project.view'), 403);
        $projects = $context->ordered($authorization->accessibleProjects(Auth::user())->get());
        $project = $context->resolve($projects, $this->projectId);
        abort_unless($project, 403);
        $this->projectId = $project->id;
        $this->selectInitialCycleAndAssessment();
    }

    public function selectProject(int $projectId, LedAssessmentAuthorizationService $authorization, AccreditationProjectContextService $context): void
    {
        $project = $authorization->accessibleProjects(Auth::user())->whereKey($projectId)->first();
        abort_unless($project, 403);
        $context->select($authorization->accessibleProjects(Auth::user())->get(), $projectId);
        $this->projectId = $project->id;
        $this->cycleId = null;
        $this->selectedAssessmentId = null;
        $this->resetEditor();
        $this->selectInitialCycleAndAssessment();
    }

    public function selectCycle(int $cycleId): void
    {
        $cycle = $this->authorizedCycle($cycleId);
        $this->cycleId = $cycle->id;
        $this->selectedAssessmentId = $this->firstAssessmentId($cycle);
        $this->loadEditor();
    }

    public function selectAssessment(int $assessmentId): void
    {
        $assessment = $this->assessmentQuery()->find($assessmentId);
        abort_unless($assessment, 403);
        Gate::authorize('view', $assessment);
        $this->selectedAssessmentId = $assessment->id;
        $this->loadEditor();
    }

    public function saveDraft(LedAssessmentWorkflowService $workflow): void
    {
        $assessment = $this->selectedAssessment();
        $revision = $workflow->saveDraft($assessment, Auth::user(), [
            'rubric_id' => $this->rubricId,
            'justification' => $this->justification,
            'gap_analysis' => $this->gapAnalysis,
            'recommendation' => $this->recommendation,
            'draft_fingerprint' => $this->draftFingerprint,
        ]);
        $this->notice = __('led_assessment.notifications.draft_saved', ['revision' => $revision->revision_number]);
        $this->loadEditor();
    }

    public function submit(LedAssessmentWorkflowService $workflow): void
    {
        $revision = $this->workingRevision();
        $workflow->submit($revision, Auth::user());
        $this->notice = __('led_assessment.notifications.submitted');
        $this->loadEditor();
    }

    public function startReview(LedAssessmentWorkflowService $workflow): void
    {
        $workflow->startReview($this->workingRevision(), Auth::user());
        $this->notice = __('led_assessment.notifications.review_started');
        $this->loadEditor();
    }

    public function requestRevision(LedAssessmentWorkflowService $workflow): void
    {
        $workflow->requestRevision($this->workingRevision(), Auth::user(), $this->decisionNote);
        $this->notice = __('led_assessment.notifications.revision_requested');
        $this->decisionNote = '';
        $this->loadEditor();
    }

    public function approve(LedAssessmentWorkflowService $workflow): void
    {
        $workflow->approve($this->workingRevision(), Auth::user(), $this->decisionNote);
        $this->notice = __('led_assessment.notifications.approved');
        $this->decisionNote = '';
        $this->loadEditor();
    }

    public function linkEvidence(int $documentId, int $submissionId, LedAssessmentEvidenceService $evidence): void
    {
        $assessment = $this->selectedAssessment();
        Gate::authorize('update', $assessment);
        $document = $this->eligibleEvidenceQuery($assessment)->whereKey($documentId)->first();
        abort_unless($document, 403);
        $submission = $document->reviewSubmissions()
            ->where('status', 'verified')
            ->whereNotNull('decided_at')
            ->whereNotNull('decided_by')
            ->whereKey($submissionId)
            ->first();
        abort_unless($submission, 422);
        $evidence->linkVerifiedDocument($this->workingRevision(), $submission, $document, Auth::user());
        $this->notice = __('led_assessment.notifications.evidence_linked');
    }

    public function render(
        LedAssessmentAuthorizationService $authorization,
        AccreditationProjectContextService $context,
    ) {
        $projects = $context->ordered($authorization->accessibleProjects(Auth::user())->get());
        $project = $projects->firstWhere('id', $this->projectId);
        abort_unless($project, 403);
        $cycles = AccreditationLedAssessmentCycle::query()
            ->where('project_id', $project->id)
            ->orderByDesc('cycle_number')
            ->get()
            ->filter(fn (AccreditationLedAssessmentCycle $cycle): bool => $authorization->canViewCycle(Auth::user(), $cycle));
        $cycle = $cycles->firstWhere('id', $this->cycleId);
        $assessments = $cycle
            ? $this->assessmentQuery()->with([
                'indicator',
                'currentWorkingRevision.rubric',
                'currentWorkingRevision.evidence.document.requirement.item.criterion',
                'currentWorkingRevision.evidence.verificationSubmission.decider',
                'currentApprovedRevision.rubric',
            ])->get()
            : collect();
        $selected = $assessments->firstWhere('id', $this->selectedAssessmentId);
        $repositoryDocuments = collect();
        $workingRevision = $selected?->currentWorkingRevision;
        $reviewerFeedback = collect();
        $canEdit = $selected && Gate::allows('update', $selected);

        if ($selected) {
            $reviewerFeedback = $selected->revisions()
                ->with(['events' => fn ($events) => $events
                    ->where('action', 'revision_requested')
                    ->whereNotNull('decision_note')
                    ->with('actor')])
                ->get()
                ->flatMap(fn (AccreditationLedAssessmentRevision $revision) => $revision->events
                    ->filter(fn ($event): bool => trim((string) $event->decision_note) !== '')
                    ->map(fn ($event): array => ['event' => $event, 'revision_number' => $revision->revision_number]))
                ->sortByDesc(fn (array $feedback): string => sprintf(
                    '%020d-%020d',
                    $feedback['event']->occurred_at?->getTimestamp() ?? 0,
                    $feedback['event']->id,
                ))
                ->values();
        }

        if ($selected && $canEdit && $workingRevision?->status === 'draft') {
            $linkedDocumentIds = $workingRevision->evidence->pluck('document_id');
            $repositoryDocuments = $this->eligibleEvidenceQuery($selected)
                ->whereNotIn('id', $linkedDocumentIds)
                ->with([
                    'requirement.item.criterion',
                    'reviewSubmissions' => fn ($query) => $query
                        ->where('status', 'verified')
                        ->whereNotNull('decided_at')
                        ->whereNotNull('decided_by')
                        ->orderByDesc('revision_number')
                        ->orderByDesc('accreditation_review_submissions.id'),
                ])
                ->orderByDesc('uploaded_at')
                ->limit(50)
                ->get();
        }

        $criterionNames = $project->criteria()->pluck('name', 'code');
        $assessmentGroups = $assessments
            ->groupBy(fn (AccreditationLedAssessment $assessment) => $assessment->indicator->criterion_code)
            ->map(fn ($group, string $code): array => [
                'code' => $code,
                'name' => $criterionNames->get($code) ?? __('led_assessment.index.criterion_fallback', ['code' => $code]),
                'assessments' => $group,
            ])->values();

        return view('livewire.led-assessment.index', [
            'projects' => $projects,
            'project' => $project,
            'cycles' => $cycles,
            'cycle' => $cycle,
            'assessments' => $assessments,
            'assessmentGroups' => $assessmentGroups,
            'selectedAssessment' => $selected,
            'workingRevision' => $workingRevision,
            'reviewerFeedback' => $reviewerFeedback,
            'rubrics' => $selected?->indicator->rubrics()->orderBy('position')->get() ?? collect(),
            'repositoryDocuments' => $repositoryDocuments,
            'canEdit' => (bool) $canEdit,
        ])->title(__('led_assessment.meta.index'));
    }

    private function selectInitialCycleAndAssessment(): void
    {
        $authorization = app(LedAssessmentAuthorizationService::class);
        $cycles = AccreditationLedAssessmentCycle::query()
            ->with('project')
            ->where('project_id', $this->projectId)
            ->when($this->cycleId, fn ($query) => $query->whereKey($this->cycleId))
            ->orderByDesc('cycle_number')
            ->get();
        $cycle = $cycles->first(fn (AccreditationLedAssessmentCycle $candidate): bool => $authorization->canViewCycle(Auth::user(), $candidate));
        abort_if($this->cycleId !== null && ! $cycle, 403);
        $this->cycleId = $cycle?->id;
        $this->selectedAssessmentId ??= $cycle ? $this->firstAssessmentId($cycle) : null;
        $this->loadEditor();
    }

    private function loadEditor(): void
    {
        $revision = $this->selectedAssessmentId ? $this->selectedAssessment()?->currentWorkingRevision : null;
        $this->rubricId = $revision?->rubric_id;
        $this->justification = (string) ($revision?->justification ?? '');
        $this->gapAnalysis = (string) ($revision?->gap_analysis ?? '');
        $this->recommendation = (string) ($revision?->recommendation ?? '');
        $this->draftFingerprint = $revision?->draftFingerprint();
    }

    private function resetEditor(): void
    {
        $this->reset(['rubricId', 'justification', 'gapAnalysis', 'recommendation', 'decisionNote', 'draftFingerprint', 'notice']);
    }

    private function selectedAssessment(): AccreditationLedAssessment
    {
        $assessment = $this->assessmentQuery()->find($this->selectedAssessmentId);
        abort_unless($assessment, 403);

        return $assessment;
    }

    private function workingRevision(): AccreditationLedAssessmentRevision
    {
        $revision = $this->selectedAssessment()->currentWorkingRevision;
        abort_unless($revision, 422);

        return $revision;
    }

    private function assessmentQuery()
    {
        $cycle = $this->authorizedCycle();

        return $this->assessmentQueryForCycle($cycle);
    }

    private function assessmentQueryForCycle(AccreditationLedAssessmentCycle $cycle)
    {
        $query = AccreditationLedAssessment::query()
            ->where('cycle_id', $cycle->id)
            ->orderBy(AccreditationLedIndicator::query()
                ->select('position')
                ->whereColumn('accreditation_led_indicators.id', 'accreditation_led_assessments.indicator_id'));

        if (! app(LedAssessmentAuthorizationService::class)->hasCycleOversight(Auth::user(), $cycle)) {
            $query->whereIn('indicator_id', $cycle->assignments()
                ->where('user_id', Auth::id())
                ->whereNull('revoked_at')
                ->select('indicator_id'));
        }

        return $query;
    }

    private function authorizedCycle(?int $cycleId = null): AccreditationLedAssessmentCycle
    {
        $cycle = AccreditationLedAssessmentCycle::query()->with('project')->where('project_id', $this->projectId)->find($cycleId ?? $this->cycleId);
        abort_unless($cycle, 403);
        Gate::authorize('view', $cycle);

        return $cycle;
    }

    private function firstAssessmentId(AccreditationLedAssessmentCycle $cycle): ?int
    {
        return $this->assessmentQueryForCycle($cycle)->value('id');
    }

    private function eligibleEvidenceQuery(AccreditationLedAssessment $assessment)
    {
        $mappedItemIds = AccreditationLedIndicatorItemMapping::query()
            ->where('cycle_id', $assessment->cycle_id)
            ->where('project_id', $assessment->project_id)
            ->where('instrument_version_id', $assessment->instrument_version_id)
            ->where('indicator_id', $assessment->indicator_id)
            ->whereNull('revoked_at')
            ->select('item_id');

        return AccreditationDocument::query()
            ->whereHas('requirement.item', fn ($query) => $query
                ->whereIn('id', $mappedItemIds)
                ->whereHas('criterion', fn ($criterion) => $criterion->where('project_id', $assessment->project_id)))
            ->whereHas('reviewSubmissions', fn ($query) => $query
                ->where('status', 'verified')
                ->whereNotNull('decided_at')
                ->whereNotNull('decided_by'));
    }
}
