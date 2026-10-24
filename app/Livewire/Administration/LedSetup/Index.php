<?php

namespace App\Livewire\Administration\LedSetup;

use App\Livewire\Component;
use App\Models\AccreditationItem;
use App\Models\AccreditationLedAssessmentCycle;
use App\Models\AccreditationLedAssessorAssignment;
use App\Models\AccreditationLedIndicator;
use App\Models\AccreditationLedIndicatorItemMapping;
use App\Models\AccreditationLedInstrumentVersion;
use App\Models\AccreditationProject;
use App\Models\User;
use App\Services\AccreditationAuthorizationService;
use App\Services\AccreditationProjectContextService;
use App\Services\LedAssessmentCycleService;
use App\Services\LedAssessorAssignmentService;
use App\Services\LedIndicatorItemMappingService;
use App\Services\LedInstrumentImportService;
use App\Services\LedInstrumentVersionService;
use App\Services\LedOperationalSetupBulkService;
use App\Support\LocalizedLabel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithFileUploads;

    #[Url(as: 'proyek', except: null)]
    public ?int $projectId = null;

    #[Url(as: 'siklus', except: null)]
    public ?int $cycleId = null;

    public $sourcePdf;

    public $manifestFile;

    public ?array $importPreview = null;

    #[Locked]
    public ?string $importPreviewFingerprint = null;

    public ?int $versionId = null;

    public string $cycleName = '';

    #[Url(as: 'indikator', except: null)]
    public ?int $indicatorId = null;

    public ?int $assignmentUserId = null;

    public string $assignmentRole = 'assessor_primary';

    public ?int $mappingItemId = null;

    public string $mappingRole = 'primary';

    public string $mappingRevocationReason = '';

    public array $bulkAssignmentIndicatorIds = [];

    public ?int $bulkPrimaryUserId = null;

    public ?int $bulkSupportUserId = null;

    public ?int $bulkReviewerUserId = null;

    public string $bulkAssignmentReason = '';

    public ?array $bulkAssignmentPreview = null;

    #[Locked]
    public ?string $bulkAssignmentFingerprint = null;

    public ?string $bulkAssignmentMessage = null;

    public array $bulkMappingIndicatorIds = [];

    public array $bulkMappingItemIds = [];

    public string $bulkMappingRole = 'primary';

    public ?array $bulkMappingPreview = null;

    #[Locked]
    public ?string $bulkMappingFingerprint = null;

    public ?string $bulkMappingMessage = null;

    public ?string $assignmentMessage = null;

    public ?string $mappingMessage = null;

    public ?string $operationalFeedbackContext = null;

    #[Locked]
    public ?string $confirmationAction = null;

    #[Locked]
    public ?int $confirmationTargetId = null;

    #[Locked]
    public ?int $confirmationIndicatorId = null;

    #[Locked]
    public ?int $confirmationUserId = null;

    #[Locked]
    public ?string $confirmationRole = null;

    #[Locked]
    public ?int $confirmationPrimaryId = null;

    #[Locked]
    public ?string $confirmationFocusReturnId = null;

    public string $confirmationReason = '';

    public ?string $successMessage = null;

    public function mount(AccreditationProjectContextService $context): void
    {
        abort_unless(Auth::user()->can('project.manage'), 403, __('led_setup.messages.authorization.page'));
        $projects = $this->manageableProjects();
        $project = $context->resolve($projects, $this->projectId);
        abort_unless($project, 403, __('led_setup.messages.authorization.project'));
        $this->projectId = $project->id;
        $this->selectInitialCycle();
    }

    public function updatedSourcePdf(): void
    {
        $this->importPreview = null;
        $this->importPreviewFingerprint = null;
    }

    public function updatedManifestFile(): void
    {
        $this->importPreview = null;
        $this->importPreviewFingerprint = null;
    }

    public function updatedBulkAssignmentIndicatorIds(): void
    {
        $this->resetBulkAssignmentPreview();
    }

    public function updatedBulkPrimaryUserId(): void
    {
        $this->resetBulkAssignmentPreview();
    }

    public function updatedBulkSupportUserId(): void
    {
        $this->resetBulkAssignmentPreview();
    }

    public function updatedBulkReviewerUserId(): void
    {
        $this->resetBulkAssignmentPreview();
    }

    public function updatedBulkAssignmentReason(): void
    {
        $this->resetBulkAssignmentPreview();
    }

    public function updatedBulkMappingIndicatorIds(): void
    {
        $this->resetBulkMappingPreview();
    }

    public function updatedBulkMappingItemIds(): void
    {
        $this->resetBulkMappingPreview();
    }

    public function updatedBulkMappingRole(): void
    {
        $this->resetBulkMappingPreview();
    }

    public function selectProject(int $projectId, AccreditationProjectContextService $context): void
    {
        $projects = $this->manageableProjects();
        $project = $projects->firstWhere('id', $projectId);
        abort_unless($project, 403, __('led_setup.messages.authorization.project'));
        $context->select($projects, $projectId);
        $this->projectId = $project->id;
        $this->resetSetupSelection();
        $this->selectInitialCycle();
    }

    public function previewImport(LedInstrumentImportService $importer): void
    {
        $project = $this->authorizedProject();
        abort_unless(Auth::user()->hasRole('Super Admin'), 403, __('led_setup.messages.authorization.import'));
        $this->resetValidation();
        $this->validate([
            'sourcePdf' => ['required', 'file', 'max:51200'],
            'manifestFile' => ['required', 'file', 'max:2048'],
        ], [], __('led_setup.validation.attributes'));
        $this->importPreview = $importer->preview($project->instrument, $this->sourcePdf, $this->manifestFile, Auth::user());
        $this->importPreviewFingerprint = $this->previewFingerprint($this->importPreview);
        $this->successMessage = __('led_setup.notices.import_preview_valid');
    }

    public function saveImport(LedInstrumentImportService $importer): void
    {
        $project = $this->authorizedProject();
        abort_unless($this->importPreview !== null && $this->importPreviewFingerprint !== null && Auth::user()->hasRole('Super Admin'), 403, __('led_setup.messages.authorization.import_preview'));
        $this->validate([
            'sourcePdf' => ['required', 'file', 'max:51200'],
            'manifestFile' => ['required', 'file', 'max:2048'],
        ], [], __('led_setup.validation.attributes'));
        $validatedPreview = $importer->preview($project->instrument, $this->sourcePdf, $this->manifestFile, Auth::user());
        abort_unless(hash_equals($this->importPreviewFingerprint, $this->previewFingerprint($validatedPreview)), 409, __('led_setup.messages.authorization.import_preview_changed'));
        $version = $importer->import($project->instrument, $this->sourcePdf, $this->manifestFile, Auth::user());
        $this->successMessage = __('led_setup.notices.import_saved', ['version' => $version->version_code]);
        $this->reset(['sourcePdf', 'manifestFile', 'importPreview', 'importPreviewFingerprint']);
        $this->resetValidation();
    }

    public function publishVersion(int $versionId, LedInstrumentVersionService $versions): void
    {
        $version = $this->authorizedVersion($versionId);
        if (Gate::denies('publish', $version)) {
            throw new AuthorizationException(__('led_setup.messages.authorization.publish'));
        }
        $published = $versions->publish($version, Auth::user());
        $this->successMessage = __('led_setup.notices.version_published', ['version' => $published->version_code]);
    }

    public function createCycle(LedAssessmentCycleService $cycles): void
    {
        $project = $this->authorizedProject();
        $this->validate([
            'versionId' => [
                'required',
                'integer',
                Rule::exists('accreditation_led_instrument_versions', 'id')
                    ->where(fn ($query) => $query
                        ->where('instrument_id', $project->instrument_id)
                        ->where('status', 'published')),
            ],
            'cycleName' => ['required', 'string', 'max:255'],
        ], [], __('led_setup.validation.attributes'));
        $version = $this->authorizedVersion((int) $this->versionId, true);
        $cycle = $cycles->create($project, $version, $this->cycleName, Auth::user());
        $this->cycleId = $cycle->id;
        $this->indicatorId = $this->firstIndicatorId($cycle);
        $this->cycleName = '';
        $this->successMessage = __('led_setup.notices.cycle_created', ['cycle' => $cycle->cycle_name, 'version' => $version->version_code]);
        $this->resetValidation();
    }

    public function selectCycle(int $cycleId): void
    {
        $cycle = $this->authorizedCycle($cycleId);
        $this->cycleId = $cycle->id;
        $this->indicatorId = $this->firstIndicatorId($cycle);
        $this->resetOperationalInputs();
        $this->resetValidation();
    }

    public function selectIndicator(int $indicatorId): void
    {
        $indicator = $this->authorizedIndicator($this->authorizedCycle(), $indicatorId);
        $this->indicatorId = $indicator->id;
        $this->cancelConfirmation();
        $this->reset(['assignmentUserId', 'mappingItemId', 'mappingRevocationReason', 'assignmentMessage', 'mappingMessage', 'operationalFeedbackContext']);
        $this->resetValidation();
    }

    public function previewBulkAssignments(LedOperationalSetupBulkService $bulk): void
    {
        $this->operationalFeedbackContext = 'bulk_assignment';
        $this->resetValidation();
        $this->successMessage = null;
        $this->bulkAssignmentMessage = null;
        $plan = $bulk->previewAssignments(
            $this->authorizedCycle(),
            $this->bulkAssignmentIndicatorIds,
            [
                'assessor_primary' => $this->bulkPrimaryUserId,
                'assessor_support' => $this->bulkSupportUserId,
                'reviewer' => $this->bulkReviewerUserId,
            ],
            $this->bulkAssignmentReason,
            Auth::user(),
        );
        $this->bulkAssignmentPreview = $plan;
        $this->bulkAssignmentFingerprint = $plan['fingerprint'];
        $this->bulkAssignmentMessage = __('led_setup.notices.bulk_assignment_preview_ready');
    }

    public function applyBulkAssignments(LedOperationalSetupBulkService $bulk): void
    {
        abort_unless($this->bulkAssignmentPreview && $this->bulkAssignmentFingerprint, 409, __('led_setup.messages.authorization.bulk_assignment_preview'));
        $this->operationalFeedbackContext = 'bulk_assignment';
        $this->resetValidation();
        $this->successMessage = null;
        $this->bulkAssignmentMessage = null;
        $result = $bulk->applyAssignments(
            $this->authorizedCycle(),
            $this->bulkAssignmentIndicatorIds,
            [
                'assessor_primary' => $this->bulkPrimaryUserId,
                'assessor_support' => $this->bulkSupportUserId,
                'reviewer' => $this->bulkReviewerUserId,
            ],
            $this->bulkAssignmentReason,
            $this->bulkAssignmentFingerprint,
            Auth::user(),
        );
        $this->reset(['bulkAssignmentIndicatorIds', 'bulkPrimaryUserId', 'bulkSupportUserId', 'bulkReviewerUserId', 'bulkAssignmentReason']);
        $this->resetBulkAssignmentPreview();
        $this->bulkAssignmentMessage = __('led_setup.notices.bulk_assignment_applied', [
            'created' => $result['created_count'],
            'skipped' => $result['skipped_count'],
        ]);
        $this->completeConfirmation();
    }

    public function previewBulkMappings(LedOperationalSetupBulkService $bulk): void
    {
        $this->operationalFeedbackContext = 'bulk_mapping';
        $this->resetValidation();
        $this->successMessage = null;
        $this->bulkMappingMessage = null;
        $plan = $bulk->previewMappings(
            $this->authorizedCycle(),
            $this->bulkMappingIndicatorIds,
            $this->bulkMappingItemIds,
            $this->bulkMappingRole,
            Auth::user(),
        );
        $this->bulkMappingPreview = $plan;
        $this->bulkMappingFingerprint = $plan['fingerprint'];
        $this->bulkMappingMessage = __('led_setup.notices.bulk_mapping_preview_ready');
    }

    public function applyBulkMappings(LedOperationalSetupBulkService $bulk): void
    {
        abort_unless($this->bulkMappingPreview && $this->bulkMappingFingerprint, 409, __('led_setup.messages.authorization.bulk_mapping_preview'));
        $this->operationalFeedbackContext = 'bulk_mapping';
        $this->resetValidation();
        $this->successMessage = null;
        $this->bulkMappingMessage = null;
        $result = $bulk->applyMappings(
            $this->authorizedCycle(),
            $this->bulkMappingIndicatorIds,
            $this->bulkMappingItemIds,
            $this->bulkMappingRole,
            $this->bulkMappingFingerprint,
            Auth::user(),
        );
        $this->reset(['bulkMappingIndicatorIds', 'bulkMappingItemIds']);
        $this->resetBulkMappingPreview();
        $this->bulkMappingMessage = __('led_setup.notices.bulk_mapping_applied', [
            'created' => $result['created_count'],
            'skipped' => $result['skipped_count'],
        ]);
        $this->completeConfirmation();
    }

    public function openConfirmation(string $action, ?int $targetId = null): void
    {
        if ($action === 'bulk_assignment') {
            abort_unless($this->bulkAssignmentPreview && $this->bulkAssignmentFingerprint && $this->bulkAssignmentPreview['can_apply'], 409, __('led_setup.messages.authorization.bulk_assignment_preview'));
        } elseif ($action === 'bulk_mapping') {
            abort_unless($this->bulkMappingPreview && $this->bulkMappingFingerprint && $this->bulkMappingPreview['can_apply'], 409, __('led_setup.messages.authorization.bulk_mapping_preview'));
        } elseif ($action === 'revoke_assignment') {
            $assignment = AccreditationLedAssessorAssignment::query()->findOrFail($targetId);
            abort_unless($assignment->cycle_id === $this->authorizedCycle()->id, 403, __('led_setup.messages.authorization.assignment_scope'));
            abort_unless(Gate::allows('revoke', $assignment), 403, __('led_setup.messages.authorization.assignment_revoke'));
        } elseif ($action === 'revoke_mapping') {
            $mapping = AccreditationLedIndicatorItemMapping::query()->findOrFail($targetId);
            abort_unless($mapping->cycle_id === $this->authorizedCycle()->id, 403, __('led_setup.messages.authorization.mapping_scope'));
        } else {
            abort(404);
        }

        $this->resetConfirmationState();
        $this->confirmationAction = $action;
        $this->confirmationTargetId = $targetId;
        $this->confirmationFocusReturnId = $action === 'revoke_assignment'
            ? 'assignment-revoke-'.$targetId
            : null;
    }

    public function cancelConfirmation(): void
    {
        $this->resetConfirmationState();
    }

    public function confirmOperation(
        LedOperationalSetupBulkService $bulk,
        LedAssessorAssignmentService $assignments,
        LedIndicatorItemMappingService $mappings,
    ): void {
        if (in_array($this->confirmationAction, ['bulk_assignment', 'bulk_mapping'], true)) {
            $action = $this->confirmationAction;
            $this->cancelConfirmation();

            match ($action) {
                'bulk_assignment' => $this->applyBulkAssignments($bulk),
                'bulk_mapping' => $this->applyBulkMappings($bulk),
            };

            return;
        }

        match ($this->confirmationAction) {
            'add_assignment', 'replace_assignment' => $this->confirmAssignment($assignments),
            'revoke_assignment' => $this->confirmAssignmentRevocation($assignments),
            'revoke_mapping' => $this->revokeMapping((int) $this->confirmationTargetId, $mappings),
            default => abort(404),
        };
    }

    public function openAssignmentConfirmation(): void
    {
        $this->operationalFeedbackContext = 'assignment';
        $this->resetValidation();
        $this->successMessage = null;
        $this->assignmentMessage = null;
        $cycle = $this->authorizedCycle();
        $indicator = $this->authorizedIndicator($cycle, (int) $this->indicatorId);
        $this->validate([
            'assignmentUserId' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'assignmentRole' => ['required', Rule::in(['assessor_primary', 'assessor_support', 'reviewer'])],
        ], [], __('led_setup.validation.attributes'));
        $user = User::query()->with('roles')->findOrFail($this->assignmentUserId);
        $currentPrimary = $this->assignmentRole === 'assessor_primary'
            ? $cycle->assignments()
                ->where('indicator_id', $indicator->id)
                ->where('assignment_role', 'assessor_primary')
                ->whereNull('revoked_at')
                ->first()
            : null;

        $this->resetConfirmationState();
        $this->confirmationAction = $currentPrimary ? 'replace_assignment' : 'add_assignment';
        $this->confirmationIndicatorId = $indicator->id;
        $this->confirmationUserId = $user->id;
        $this->confirmationRole = $this->assignmentRole;
        $this->confirmationPrimaryId = $currentPrimary?->id;
        $this->confirmationFocusReturnId = 'assignment-add';
    }

    private function confirmAssignment(LedAssessorAssignmentService $assignments): void
    {
        $this->operationalFeedbackContext = 'assignment';
        $this->resetValidation();
        $this->successMessage = null;
        $this->assignmentMessage = null;
        $reason = $this->validatedConfirmationReason();
        $cycle = $this->authorizedCycle();
        abort_unless($this->confirmationIndicatorId === $this->indicatorId, 409, __('led_setup.messages.authorization.indicator'));
        $indicator = $this->authorizedIndicator($cycle, (int) $this->confirmationIndicatorId);
        $user = User::query()->with('roles')->findOrFail($this->confirmationUserId);

        if ($this->confirmationAction === 'replace_assignment') {
            $currentPrimary = $cycle->assignments()
                ->where('indicator_id', $indicator->id)
                ->where('assignment_role', 'assessor_primary')
                ->whereNull('revoked_at')
                ->first();
            if (! $currentPrimary || $currentPrimary->id !== $this->confirmationPrimaryId) {
                throw \Illuminate\Validation\ValidationException::withMessages(['replacement' => __('led_setup.messages.assignment.replacement_conflict')]);
            }
            $assignment = $assignments->replace($currentPrimary, $user, Auth::user(), $reason);
            $this->assignmentMessage = __('led_setup.notices.primary_replaced', ['user' => $assignment->user->name]);
        } else {
            $assignment = $assignments->assign($cycle, $indicator, $user, (string) $this->confirmationRole, Auth::user(), $reason);
            $this->assignmentMessage = __('led_setup.notices.user_assigned', [
                'user' => $assignment->user->name,
                'role' => LocalizedLabel::assignmentRole((string) $this->confirmationRole),
            ]);
        }

        $this->assignmentUserId = null;
        $this->completeConfirmation();
    }

    private function confirmAssignmentRevocation(LedAssessorAssignmentService $assignments): void
    {
        $this->operationalFeedbackContext = 'assignment';
        $this->resetValidation();
        $this->successMessage = null;
        $this->assignmentMessage = null;
        $reason = $this->validatedConfirmationReason();
        $assignment = AccreditationLedAssessorAssignment::query()->with('cycle.project')->findOrFail($this->confirmationTargetId);
        abort_unless($assignment->cycle_id === $this->authorizedCycle()->id, 403, __('led_setup.messages.authorization.assignment_scope'));
        if (Gate::denies('revoke', $assignment)) {
            throw new AuthorizationException(__('led_setup.messages.authorization.assignment_revoke'));
        }
        $revoked = $assignments->revoke($assignment, Auth::user(), $reason);
        $this->assignmentMessage = __('led_setup.notices.assignment_revoked', ['user' => $revoked->user->name]);
        $this->completeConfirmation();
    }

    public function createMapping(LedIndicatorItemMappingService $mappings): void
    {
        $this->operationalFeedbackContext = 'mapping';
        $this->resetValidation();
        $this->successMessage = null;
        $this->mappingMessage = null;
        $cycle = $this->authorizedCycle();
        $indicator = $this->authorizedIndicator($cycle, (int) $this->indicatorId);
        $this->validate([
            'mappingItemId' => ['required', 'integer', Rule::exists('accreditation_items', 'id')],
            'mappingRole' => ['required', Rule::in(['primary', 'supporting'])],
        ], [], __('led_setup.validation.attributes'));
        $item = AccreditationItem::query()->with('criterion')->findOrFail($this->mappingItemId);
        $mapping = $mappings->map($cycle, $indicator, $item, $this->mappingRole, Auth::user());
        $this->mappingMessage = __('led_setup.notices.item_mapped', [
            'item' => $mapping->item->number,
            'role' => LocalizedLabel::mappingRole($mapping->mapping_role),
        ]);
        $this->mappingItemId = null;
        $this->resetValidation();
    }

    public function revokeMapping(int $mappingId, LedIndicatorItemMappingService $mappings): void
    {
        $this->operationalFeedbackContext = 'mapping';
        $this->resetValidation();
        $this->successMessage = null;
        $this->mappingMessage = null;
        $mapping = AccreditationLedIndicatorItemMapping::query()->with('cycle.project')->findOrFail($mappingId);
        abort_unless($mapping->cycle_id === $this->authorizedCycle()->id, 403, __('led_setup.messages.authorization.mapping_scope'));
        if (Gate::denies('revoke', $mapping)) {
            throw new AuthorizationException(__('led_setup.messages.authorization.mapping_revoke'));
        }
        $revoked = $mappings->revoke($mapping, Auth::user(), $this->mappingRevocationReason);
        $this->mappingMessage = __('led_setup.notices.mapping_revoked', ['mapping' => $revoked->id]);
        $this->mappingRevocationReason = '';
        $this->resetValidation();
        $this->completeConfirmation();
    }

    public function activateCycle(LedAssessmentCycleService $cycles): void
    {
        $cycle = $this->authorizedCycle();
        $activated = $cycles->activate($cycle, Auth::user());
        $this->successMessage = __('led_setup.notices.cycle_activated', ['cycle' => $activated->cycle_name]);
    }

    public function render(LedAssessmentCycleService $cycles)
    {
        $projects = $this->manageableProjects();
        $project = $projects->firstWhere('id', $this->projectId);
        abort_unless($project, 403, __('led_setup.messages.authorization.project'));
        $versions = AccreditationLedInstrumentVersion::query()
            ->where('instrument_id', $project->instrument_id)
            ->withCount('indicators')
            ->with(['indicators.rubrics'])
            ->orderByDesc('created_at')
            ->get();
        $assessmentCycles = AccreditationLedAssessmentCycle::query()
            ->where('project_id', $project->id)
            ->with('instrumentVersion:id,version_code,status')
            ->orderByDesc('cycle_number')
            ->get();
        $cycle = $this->cycleId
            ? AccreditationLedAssessmentCycle::query()
                ->where('project_id', $project->id)
                ->with([
                    'instrumentVersion',
                    'assessments.indicator',
                    'assignments.user.roles',
                    'itemMappings.item.criterion',
                    'itemMappings.creator',
                    'itemMappings.revoker',
                ])
                ->find($this->cycleId)
            : null;
        abort_if($this->cycleId !== null && ! $cycle, 403, __('led_setup.messages.authorization.cycle'));
        $selectedIndicator = $cycle?->assessments
            ->firstWhere('indicator_id', $this->indicatorId)
            ?->indicator;
        $readiness = $cycle ? $cycles->readiness($cycle) : null;
        $criteria = $project->criteria()->orderBy('position')->get()->keyBy('code');
        $indicatorGroups = $cycle?->assessments
            ->sortBy(fn ($assessment) => $assessment->indicator->position)
            ->groupBy(fn ($assessment) => $assessment->indicator->criterion_code)
            ->map(fn (Collection $group, string $code): array => [
                'code' => $code,
                'name' => $criteria->get($code)?->name ?? __('led_setup.labels.criterion_with_code', ['code' => $code]),
                'assessments' => $group->values(),
            ])->values() ?? collect();
        $candidateUsers = User::query()
            ->where('is_active', true)
            ->with('roles:id,name')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->can('project.view'))
            ->values();

        return view('livewire.administration.led-setup.index', [
            'projects' => $projects,
            'project' => $project,
            'versions' => $versions,
            'publishedVersions' => $versions->where('status', 'published'),
            'cycles' => $assessmentCycles,
            'cycle' => $cycle,
            'selectedIndicator' => $selectedIndicator,
            'readiness' => $readiness,
            'indicatorGroups' => $indicatorGroups,
            'candidateUsers' => $candidateUsers,
            'projectItems' => AccreditationItem::query()
                ->whereHas('criterion', fn ($criteria) => $criteria->where('project_id', $project->id))
                ->whereHas('requirements')
                ->with('criterion:id,project_id,code,name')
                ->orderBy('criterion_id')
                ->orderBy('position')
                ->get(),
        ])->title(__('led_setup.meta_title'));
    }

    private function manageableProjects(): Collection
    {
        $user = Auth::user();
        $authorization = app(AccreditationAuthorizationService::class);

        return $authorization->manageableProjects($user)
            ->with(['studyProgram', 'instrument'])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('final_deadline')
            ->get();
    }

    private function authorizedProject(): AccreditationProject
    {
        $project = $this->manageableProjects()->firstWhere('id', $this->projectId);
        abort_unless($project, 403, __('led_setup.messages.authorization.project'));

        return $project;
    }

    private function authorizedVersion(int $versionId, bool $published = false): AccreditationLedInstrumentVersion
    {
        $project = $this->authorizedProject();
        $version = AccreditationLedInstrumentVersion::query()
            ->where('instrument_id', $project->instrument_id)
            ->when($published, fn ($query) => $query->where('status', 'published'))
            ->findOrFail($versionId);

        return $version;
    }

    private function authorizedCycle(?int $cycleId = null): AccreditationLedAssessmentCycle
    {
        $project = $this->authorizedProject();
        $cycle = AccreditationLedAssessmentCycle::query()
            ->with(['project', 'assessments.indicator'])
            ->where('project_id', $project->id)
            ->findOrFail($cycleId ?? $this->cycleId);
        abort_unless(Gate::allows('manage', $cycle), 403, __('led_setup.messages.authorization.cycle_manage'));

        return $cycle;
    }

    private function authorizedIndicator(AccreditationLedAssessmentCycle $cycle, int $indicatorId): AccreditationLedIndicator
    {
        $indicator = $cycle->assessments()
            ->where('indicator_id', $indicatorId)
            ->firstOrFail()
            ->indicator;
        abort_unless($indicator->instrument_version_id === $cycle->instrument_version_id, 403, __('led_setup.messages.authorization.indicator'));

        return $indicator;
    }

    private function selectInitialCycle(): void
    {
        $requestedCycleId = $this->cycleId;
        $requestedIndicatorId = $this->indicatorId;
        $cycle = AccreditationLedAssessmentCycle::query()
            ->where('project_id', $this->projectId)
            ->when($requestedCycleId, fn ($query) => $query->whereKey($requestedCycleId))
            ->orderByDesc('cycle_number')
            ->first();
        abort_if($requestedCycleId !== null && ! $cycle, 403, __('led_setup.messages.authorization.cycle'));
        $this->cycleId = $cycle?->id;
        if (! $cycle) {
            $this->indicatorId = null;

            return;
        }

        if ($requestedIndicatorId !== null) {
            abort_unless($cycle->assessments()->where('indicator_id', $requestedIndicatorId)->exists(), 403, __('led_setup.messages.authorization.indicator'));
            $this->indicatorId = $requestedIndicatorId;
        } else {
            $this->indicatorId = $this->firstIndicatorId($cycle);
        }
    }

    private function resetSetupSelection(): void
    {
        $this->reset([
            'cycleId',
            'versionId',
            'cycleName',
            'indicatorId',
            'assignmentUserId',
            'mappingItemId',
            'mappingRevocationReason',
            'bulkAssignmentIndicatorIds',
            'bulkPrimaryUserId',
            'bulkSupportUserId',
            'bulkReviewerUserId',
            'bulkAssignmentReason',
            'bulkAssignmentPreview',
            'bulkAssignmentFingerprint',
            'bulkAssignmentMessage',
            'bulkMappingIndicatorIds',
            'bulkMappingItemIds',
            'bulkMappingPreview',
            'bulkMappingFingerprint',
            'bulkMappingMessage',
            'assignmentMessage',
            'mappingMessage',
            'operationalFeedbackContext',
            'confirmationAction',
            'confirmationTargetId',
            'confirmationIndicatorId',
            'confirmationUserId',
            'confirmationRole',
            'confirmationPrimaryId',
            'confirmationFocusReturnId',
            'confirmationReason',
            'sourcePdf',
            'manifestFile',
            'importPreview',
            'importPreviewFingerprint',
            'successMessage',
        ]);
        $this->resetValidation();
    }

    private function previewFingerprint(array $preview): string
    {
        return hash('sha256', implode('|', [
            $preview['metadata']['version_code'],
            $preview['source_pdf_sha256'],
            $preview['rubric_manifest_sha256'],
        ]));
    }

    private function firstIndicatorId(AccreditationLedAssessmentCycle $cycle): ?int
    {
        return $cycle->assessments()->with('indicator')->get()
            ->sortBy(fn ($assessment) => $assessment->indicator->position)
            ->first()?->indicator_id;
    }

    private function resetOperationalInputs(): void
    {
        $this->reset([
            'assignmentUserId',
            'mappingItemId',
            'mappingRevocationReason',
            'bulkAssignmentIndicatorIds',
            'bulkPrimaryUserId',
            'bulkSupportUserId',
            'bulkReviewerUserId',
            'bulkAssignmentReason',
            'bulkAssignmentMessage',
            'bulkMappingIndicatorIds',
            'bulkMappingItemIds',
            'bulkMappingMessage',
            'assignmentMessage',
            'mappingMessage',
            'operationalFeedbackContext',
            'confirmationAction',
            'confirmationTargetId',
            'confirmationIndicatorId',
            'confirmationUserId',
            'confirmationRole',
            'confirmationPrimaryId',
            'confirmationFocusReturnId',
            'confirmationReason',
        ]);
        $this->resetBulkAssignmentPreview();
        $this->resetBulkMappingPreview();
    }

    private function resetBulkAssignmentPreview(): void
    {
        $this->bulkAssignmentPreview = null;
        $this->bulkAssignmentFingerprint = null;
        $this->bulkAssignmentMessage = null;
    }

    private function resetBulkMappingPreview(): void
    {
        $this->bulkMappingPreview = null;
        $this->bulkMappingFingerprint = null;
        $this->bulkMappingMessage = null;
    }

    private function validatedConfirmationReason(): string
    {
        $this->validate(['confirmationReason' => ['required', 'string', 'max:1000']], [], __('led_setup.validation.attributes'));
        if (trim($this->confirmationReason) === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'confirmationReason' => __('led_setup.messages.assignment.confirmation_reason_required'),
            ]);
        }

        return $this->confirmationReason;
    }

    private function completeConfirmation(): void
    {
        $this->resetConfirmationState();
        $this->resetValidation();
    }

    private function resetConfirmationState(): void
    {
        $this->reset([
            'confirmationAction',
            'confirmationTargetId',
            'confirmationIndicatorId',
            'confirmationUserId',
            'confirmationRole',
            'confirmationPrimaryId',
            'confirmationFocusReturnId',
            'confirmationReason',
        ]);
    }
}
