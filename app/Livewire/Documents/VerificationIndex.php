<?php

namespace App\Livewire\Documents;

use App\Models\AccreditationCriterion;
use App\Models\AccreditationReviewSubmission;
use App\Models\User;
use App\Services\AccreditationAuthorizationService;
use App\Services\AccreditationProjectContextService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Verifikasi Dokumen | APU Accreditation')]
class VerificationIndex extends Component
{
    #[Url(as: 'proyek', except: null)]
    public ?int $projectId = null;

    #[Url(as: 'kriteria', except: null)]
    public ?int $criterionId = null;

    #[Url(as: 'status', except: 'pending_review')]
    public string $status = 'pending_review';

    public function mount(
        AccreditationAuthorizationService $authorization,
        AccreditationProjectContextService $context,
    ): void {
        Gate::authorize('viewAny', AccreditationReviewSubmission::class);
        $projects = $this->authorizedProjects($authorization, $context);
        $project = $context->resolve($projects, $this->projectId);
        abort_unless($project, 403);
        $this->projectId = $project->id;

        $this->validateCriterionFilter(Auth::user());
        $this->validateStatusFilter();
    }

    public function updatedProjectId(
        AccreditationAuthorizationService $authorization,
        AccreditationProjectContextService $context,
    ): void {
        $projects = $this->authorizedProjects($authorization, $context);
        $context->select($projects, (int) $this->projectId);
        $this->criterionId = null;
    }

    public function updatedCriterionId(): void
    {
        $this->validateCriterionFilter(Auth::user());
    }

    public function updatedStatus(): void
    {
        $this->validateStatusFilter();
    }

    public function render(
        AccreditationAuthorizationService $authorization,
        AccreditationProjectContextService $context,
    ) {
        Gate::authorize('viewAny', AccreditationReviewSubmission::class);
        $user = Auth::user();
        $projects = $this->authorizedProjects($authorization, $context);
        abort_unless($projects->contains('id', $this->projectId), 403);
        $criteria = $this->authorizedCriteriaQuery($user)
            ->where('project_id', $this->projectId)
            ->orderBy('position')
            ->get();
        $this->validateCriterionFilter($user, $criteria);
        $this->validateStatusFilter();

        $submissions = AccreditationReviewSubmission::query()
            ->with([
                'submitter',
                'requirement.item.criterion.project',
            ])
            ->withCount('documents')
            ->whereHas('requirement.item.criterion', function (Builder $query) use ($user): void {
                $query->where('project_id', $this->projectId);

                if (! $user->hasRole('Super Admin')) {
                    $query->whereHas('assignments', fn (Builder $assignments) => $assignments
                        ->where('user_id', $user->id)
                        ->where('assignment_role', 'reviewer')
                        ->where('is_active', true));
                }

                if ($this->criterionId !== null) {
                    $query->whereKey($this->criterionId);
                }
            })
            ->when(! $user->hasRole('Super Admin'), fn (Builder $query) => $query
                ->where('submitted_by', '!=', $user->id)
                ->whereDoesntHave('documents', fn (Builder $documents) => $documents
                    ->where('uploaded_by', $user->id)))
            ->when($this->status !== 'all', fn (Builder $query) => $query->where('status', $this->status))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        return view('livewire.documents.verification-index', [
            'projects' => $projects,
            'project' => $projects->firstWhere('id', $this->projectId),
            'criteria' => $criteria,
            'submissions' => $submissions,
            'statusLabels' => [
                'pending_review' => 'Menunggu Verifikasi',
                'verified' => 'Terverifikasi',
                'need_revision' => 'Perlu Revisi',
            ],
        ]);
    }

    private function authorizedProjects(
        AccreditationAuthorizationService $authorization,
        AccreditationProjectContextService $context,
    ): Collection {
        return $context->ordered($authorization->reviewableProjects(Auth::user())
            ->with('studyProgram')
            ->get());
    }

    private function authorizedCriteriaQuery(User $user): Builder
    {
        return AccreditationCriterion::query()
            ->when(! $user->hasRole('Super Admin'), fn (Builder $query) => $query
                ->whereHas('assignments', fn (Builder $assignments) => $assignments
                    ->where('user_id', $user->id)
                    ->where('assignment_role', 'reviewer')
                    ->where('is_active', true)));
    }

    private function validateCriterionFilter(User $user, ?Collection $criteria = null): void
    {
        if ($this->criterionId === null) {
            return;
        }

        $criteria ??= $this->authorizedCriteriaQuery($user)
            ->where('project_id', $this->projectId)
            ->get();
        abort_unless($criteria->contains('id', $this->criterionId), 403);
    }

    private function validateStatusFilter(): void
    {
        abort_unless(in_array($this->status, ['all', 'pending_review', 'verified', 'need_revision'], true), 404);
    }
}
