<?php

namespace App\Livewire\Repository;

use App\Livewire\Component;
use App\Models\AccreditationCriterionAssignment;
use App\Models\AccreditationProject;
use App\Models\User;
use App\Services\AccreditationAuthorizationService;
use App\Services\AccreditationProgressService;
use App\Services\AccreditationProjectContextService;
use App\Services\AccreditationProjectLifecycleService;
use App\Support\LocalizedLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    private const PER_PAGE = 10;

    private const REQUIREMENT_STATUSES = [
        'not_started',
        'draft',
        'pending_review',
        'need_revision',
        'verified',
    ];

    private const SORTS = [
        'newest',
        'oldest',
        'name_asc',
        'name_desc',
        'size_desc',
        'version_desc',
    ];

    private const PREVIEWABLE_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    #[Url(as: 'proyek', except: null)]
    public ?int $projectId = null;

    #[Url(as: 'cari', except: '')]
    public string $search = '';

    #[Url(as: 'kriteria', except: null)]
    public ?int $criterionId = null;

    #[Url(as: 'status', except: '')]
    public string $workflowStatus = '';

    #[Url(as: 'jenis', except: '')]
    public string $extension = '';

    #[Url(as: 'pengunggah', except: null)]
    public ?int $uploaderId = null;

    #[Url(as: 'urut', except: 'newest')]
    public string $sort = 'newest';

    #[Url(as: 'folder', except: 'all')]
    public string $folderType = 'all';

    #[Url(as: 'node', except: null)]
    public ?int $folderNodeId = null;

    #[Url(as: 'dokumen', except: null)]
    public ?int $selectedDocumentId = null;

    public array $expandedCriteria = [];

    public array $expandedItems = [];

    public function mount(
        AccreditationAuthorizationService $authorization,
        AccreditationProjectContextService $context,
    ): void {
        abort_unless(Auth::user()->can('document.view'), 403);
        $projects = $context->ordered($this->authorizedProjects($authorization));
        $project = $context->resolve($projects, $this->projectId);
        abort_unless($project, 403);
        $this->projectId = $project->id;
        $firstCriterionId = $authorization->accessibleCriteria(Auth::user(), $project)
            ->orderBy('position')
            ->value('id');

        if ($firstCriterionId) {
            $this->expandedCriteria = [$firstCriterionId];
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, [
            'search',
            'criterionId',
            'workflowStatus',
            'extension',
            'uploaderId',
            'sort',
        ], true)) {
            $this->selectedDocumentId = null;
            $this->resetPage();
        }
    }

    public function updatedPaginators(): void
    {
        $this->selectedDocumentId = null;
    }

    public function selectProject(
        int $projectId,
        AccreditationAuthorizationService $authorization,
        AccreditationProjectContextService $context,
    ): void {
        abort_unless(Auth::user()->can('document.view'), 403);
        $project = $authorization->accessibleProjects(Auth::user())
            ->whereKey($projectId)
            ->first();

        abort_unless($project, 403);
        Gate::authorize('view', $project);

        $context->select($this->authorizedProjects($authorization), $projectId);
        $this->projectId = $project->id;
        $this->resetRepositoryState();
        $firstCriterionId = $authorization->accessibleCriteria(Auth::user(), $project)
            ->orderBy('position')
            ->value('id');
        $this->expandedCriteria = $firstCriterionId ? [$firstCriterionId] : [];
    }

    public function selectFolder(
        string $type,
        ?int $nodeId,
        AccreditationAuthorizationService $authorization,
    ): void {
        $project = $this->authorizedProject($authorization);
        $criteria = $this->criteriaTree($authorization, $project);
        $this->validateFolder($type, $nodeId, $criteria);

        $this->folderType = $type;
        $this->folderNodeId = $nodeId;
        $this->selectedDocumentId = null;
        $this->resetPage();
    }

    public function toggleCriterion(int $criterionId, AccreditationAuthorizationService $authorization): void
    {
        $project = $this->authorizedProject($authorization);
        abort_unless(
            $authorization->accessibleCriteria(Auth::user(), $project)->whereKey($criterionId)->exists(),
            403,
        );

        $this->expandedCriteria = $this->toggleId($this->expandedCriteria, $criterionId);
    }

    public function toggleItem(int $itemId, AccreditationAuthorizationService $authorization): void
    {
        $project = $this->authorizedProject($authorization);
        $criteria = $this->criteriaTree($authorization, $project);
        abort_unless($criteria->flatMap->items->contains('id', $itemId), 403);

        $this->expandedItems = $this->toggleId($this->expandedItems, $itemId);
    }

    public function selectDocument(int $documentId, AccreditationAuthorizationService $authorization): void
    {
        $project = $this->authorizedProject($authorization);
        $document = $authorization->accessibleDocuments(Auth::user(), $project)
            ->whereKey($documentId)
            ->first();

        abort_unless($document, 403);
        Gate::authorize('view', $document);
        $this->selectedDocumentId = $document->id;
    }

    public function resetFilters(): void
    {
        $this->reset([
            'search',
            'criterionId',
            'workflowStatus',
            'extension',
            'uploaderId',
            'selectedDocumentId',
        ]);
        $this->sort = 'newest';
        $this->folderType = 'all';
        $this->folderNodeId = null;
        $this->resetPage();
    }

    public function render(
        AccreditationAuthorizationService $authorization,
        AccreditationProgressService $progress,
        AccreditationProjectContextService $context,
        AccreditationProjectLifecycleService $lifecycle,
    ) {
        $user = Auth::user();
        abort_unless($user->can('document.view'), 403);
        $projects = $context->ordered($this->authorizedProjects($authorization));
        $project = $projects->firstWhere('id', $this->projectId);
        abort_unless($project, 403);
        Gate::authorize('view', $project);

        $criteria = $this->criteriaTree($authorization, $project);
        $documentScope = $authorization->accessibleDocuments($user, $project);
        $fileTypes = (clone $documentScope)
            ->select('extension')
            ->distinct()
            ->orderBy('extension')
            ->pluck('extension')
            ->map(fn (string $extension): string => strtolower($extension));
        $uploaderOptions = User::query()
            ->select(['id', 'name'])
            ->whereIn('id', (clone $documentScope)->select('uploaded_by'))
            ->orderBy('name')
            ->get();

        $this->validateSelections($criteria, $fileTypes, $uploaderOptions);
        $folderCount = $this->decorateFolderCounts($documentScope, $criteria);
        $documentsQuery = $this->documentQuery($documentScope);
        $this->applyFilters($documentsQuery);
        $this->applySort($documentsQuery);
        $documents = $documentsQuery->paginate(self::PER_PAGE);
        $selectedId = $this->selectedDocumentId ?? $documents->getCollection()->first()?->id;
        [$selectedDocument, $versions] = $this->selectedDocumentData(
            $documentScope,
            $selectedId,
        );
        $statusLabels = collect($progress->statusLabels())
            ->map(fn (string $label, string $status): string => LocalizedLabel::status($status))
            ->all();

        return view('livewire.repository.index', [
            'projects' => $projects,
            'project' => $project,
            'criteria' => $criteria,
            'documents' => $documents,
            'folderCount' => $folderCount,
            'fileTypes' => $fileTypes,
            'uploaderOptions' => $uploaderOptions,
            'selectedDocument' => $selectedDocument,
            'versions' => $versions,
            'statusLabels' => $statusLabels,
            'sortOptions' => [
                'newest' => __('repository.sorts.newest'),
                'oldest' => __('repository.sorts.oldest'),
                'name_asc' => __('repository.sorts.name_asc'),
                'name_desc' => __('repository.sorts.name_desc'),
                'size_desc' => __('repository.sorts.size_desc'),
                'version_desc' => __('repository.sorts.version_desc'),
            ],
            'previewableExtensions' => self::PREVIEWABLE_EXTENSIONS,
            'canUpload' => $lifecycle->allowsWorkflowMutation($project)
                && $this->canUpload($user, $criteria),
        ])->title(__('repository.meta_title'));
    }

    private function authorizedProjects(AccreditationAuthorizationService $authorization): Collection
    {
        return $authorization->accessibleProjects(Auth::user())
            ->with('studyProgram')
            ->get();
    }

    private function authorizedProject(AccreditationAuthorizationService $authorization): AccreditationProject
    {
        abort_unless(Auth::user()->can('document.view'), 403);
        $project = $authorization->accessibleProjects(Auth::user())
            ->with('studyProgram')
            ->whereKey($this->projectId)
            ->first();

        abort_unless($project, 403);
        Gate::authorize('view', $project);

        return $project;
    }

    private function criteriaTree(
        AccreditationAuthorizationService $authorization,
        AccreditationProject $project,
    ): Collection {
        return $authorization->accessibleCriteria(Auth::user(), $project)
            ->select(['id', 'project_id', 'code', 'name', 'position'])
            ->with([
                'items' => fn ($items) => $items
                    ->select(['id', 'criterion_id', 'number', 'position', 'title'])
                    ->orderBy('position'),
                'items.requirements' => fn ($requirements) => $requirements
                    ->select(['id', 'item_id', 'number', 'title', 'workflow_status'])
                    ->orderBy('number'),
            ])
            ->orderBy('position')
            ->get();
    }

    private function documentQuery(
        Builder $documentScope,
        bool $withRelations = true,
    ): Builder {
        $query = (clone $documentScope)
            ->join(
                'accreditation_document_requirements as repository_requirements',
                'repository_requirements.id',
                '=',
                'accreditation_documents.requirement_id',
            )
            ->join(
                'accreditation_items as repository_items',
                'repository_items.id',
                '=',
                'repository_requirements.item_id',
            )
            ->join(
                'accreditation_criteria as repository_criteria',
                'repository_criteria.id',
                '=',
                'repository_items.criterion_id',
            )
            ->select('accreditation_documents.*');

        if (! $withRelations) {
            return $query;
        }

        return $query->with([
            'uploader:id,name',
            'requirement:id,item_id,number,title,workflow_status,current_review_revision',
            'requirement.item:id,criterion_id,number,position,title',
            'requirement.item.criterion:id,project_id,code,name,position',
        ]);
    }

    private function applyFilters(Builder $query): void
    {
        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('accreditation_documents.original_name', 'like', "%{$search}%")
                    ->orWhere('repository_requirements.title', 'like', "%{$search}%")
                    ->orWhere('repository_items.title', 'like', "%{$search}%");
            });
        }

        $query
            ->when($this->criterionId !== null, fn (Builder $documents) => $documents
                ->where('repository_criteria.id', $this->criterionId))
            ->when($this->workflowStatus !== '', fn (Builder $documents) => $documents
                ->where('repository_requirements.workflow_status', $this->workflowStatus))
            ->when($this->extension !== '', fn (Builder $documents) => $documents
                ->where('accreditation_documents.extension', $this->extension))
            ->when($this->uploaderId !== null, fn (Builder $documents) => $documents
                ->where('accreditation_documents.uploaded_by', $this->uploaderId));

        match ($this->folderType) {
            'criterion' => $query->where('repository_criteria.id', $this->folderNodeId),
            'item' => $query->where('repository_items.id', $this->folderNodeId),
            'requirement' => $query->where('repository_requirements.id', $this->folderNodeId),
            default => null,
        };
    }

    private function applySort(Builder $query): void
    {
        match ($this->sort) {
            'oldest' => $query->orderBy('accreditation_documents.uploaded_at')
                ->orderBy('accreditation_documents.id'),
            'name_asc' => $query->orderBy('accreditation_documents.original_name')
                ->orderBy('accreditation_documents.id'),
            'name_desc' => $query->orderByDesc('accreditation_documents.original_name')
                ->orderByDesc('accreditation_documents.id'),
            'size_desc' => $query->orderByDesc('accreditation_documents.size_bytes')
                ->orderByDesc('accreditation_documents.id'),
            'version_desc' => $query->orderByDesc('accreditation_documents.revision_number')
                ->orderByDesc('accreditation_documents.uploaded_at')
                ->orderByDesc('accreditation_documents.id'),
            default => $query->orderByDesc('accreditation_documents.uploaded_at')
                ->orderByDesc('accreditation_documents.id'),
        };
    }

    private function decorateFolderCounts(
        Builder $documentScope,
        Collection $criteria,
    ): int {
        $counts = $this->documentQuery($documentScope, false)
            ->select([
                'repository_criteria.id as criterion_id',
                'repository_items.id as item_id',
                'repository_requirements.id as requirement_id',
            ])
            ->selectRaw('COUNT(accreditation_documents.id) as document_count')
            ->groupBy([
                'repository_criteria.id',
                'repository_items.id',
                'repository_requirements.id',
            ])
            ->get()
            ->keyBy('requirement_id');

        foreach ($criteria as $criterion) {
            $criterionCount = 0;

            foreach ($criterion->items as $item) {
                $itemCount = 0;

                foreach ($item->requirements as $requirement) {
                    $requirementCount = (int) ($counts->get($requirement->id)?->document_count ?? 0);
                    $requirement->setAttribute('repository_documents_count', $requirementCount);
                    $itemCount += $requirementCount;
                }

                $item->setAttribute('repository_documents_count', $itemCount);
                $criterionCount += $itemCount;
            }

            $criterion->setAttribute('repository_documents_count', $criterionCount);
        }

        return $criteria->sum('repository_documents_count');
    }

    private function selectedDocumentData(
        Builder $documentScope,
        ?int $documentId,
    ): array {
        if (! $documentId) {
            return [null, collect()];
        }

        $document = (clone $documentScope)
            ->with([
                'uploader:id,name',
                'requirement.item.criterion.project.studyProgram',
            ])
            ->whereKey($documentId)
            ->first();

        abort_unless($document, 403);
        Gate::authorize('view', $document);

        $versions = (clone $documentScope)
            ->with([
                'uploader:id,name',
                'reviewSubmissions' => fn ($submissions) => $submissions
                    ->select([
                        'accreditation_review_submissions.id',
                        'accreditation_review_submissions.requirement_id',
                        'accreditation_review_submissions.revision_number',
                        'accreditation_review_submissions.status',
                        'accreditation_review_submissions.submitted_at',
                        'accreditation_review_submissions.decided_at',
                    ])
                    ->orderByDesc('accreditation_review_submissions.revision_number')
                    ->orderByDesc('accreditation_review_submissions.id'),
            ])
            ->where('requirement_id', $document->requirement_id)
            ->orderByDesc('revision_number')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->get();

        return [$document, $versions];
    }

    private function canUpload(User $user, Collection $criteria): bool
    {
        if (! $user->can('document.upload') || $criteria->isEmpty()) {
            return false;
        }

        if ($user->hasRole('Super Admin')) {
            return true;
        }

        if (! $user->hasRole('PIC Kriteria')) {
            return false;
        }

        return AccreditationCriterionAssignment::query()
            ->whereIn('criterion_id', $criteria->pluck('id'))
            ->where('user_id', $user->id)
            ->where('assignment_role', 'pic')
            ->where('is_active', true)
            ->exists();
    }

    private function validateSelections(
        Collection $criteria,
        Collection $fileTypes,
        Collection $uploaderOptions,
    ): void {
        abort_unless(in_array($this->sort, self::SORTS, true), 403);
        abort_unless(
            $this->workflowStatus === ''
            || in_array($this->workflowStatus, self::REQUIREMENT_STATUSES, true),
            403,
        );
        abort_unless(
            $this->criterionId === null || $criteria->contains('id', $this->criterionId),
            403,
        );
        abort_unless(
            $this->extension === '' || $fileTypes->contains($this->extension),
            403,
        );
        abort_unless(
            $this->uploaderId === null || $uploaderOptions->contains('id', $this->uploaderId),
            403,
        );
        $this->validateFolder($this->folderType, $this->folderNodeId, $criteria);
    }

    private function validateFolder(string $type, ?int $nodeId, Collection $criteria): void
    {
        $valid = match ($type) {
            'all' => $nodeId === null,
            'criterion' => $nodeId !== null && $criteria->contains('id', $nodeId),
            'item' => $nodeId !== null && $criteria->flatMap->items->contains('id', $nodeId),
            'requirement' => $nodeId !== null
                && $criteria->flatMap->items->flatMap->requirements->contains('id', $nodeId),
            default => false,
        };

        abort_unless($valid, 403);
    }

    private function toggleId(array $ids, int $id): array
    {
        return in_array($id, $ids, true)
            ? array_values(array_diff($ids, [$id]))
            : [...$ids, $id];
    }

    private function resetRepositoryState(): void
    {
        $this->reset([
            'search',
            'criterionId',
            'workflowStatus',
            'extension',
            'uploaderId',
            'selectedDocumentId',
            'expandedItems',
        ]);
        $this->sort = 'newest';
        $this->folderType = 'all';
        $this->folderNodeId = null;
        $this->resetPage();
    }
}
