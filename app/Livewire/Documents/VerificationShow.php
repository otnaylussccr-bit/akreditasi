<?php

namespace App\Livewire\Documents;

use App\Livewire\Component;
use App\Models\AccreditationReviewSubmission;
use App\Services\AccreditationProjectContextService;
use App\Services\AccreditationReviewWorkflowService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
class VerificationShow extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $submissionId;

    public string $decision = 'verified';

    public string $reviewNote = '';

    public array $correctionAttachments = [];

    public bool $confirmingDecision = false;

    public ?string $successMessage = null;

    public function mount(
        AccreditationReviewSubmission $submission,
        AccreditationProjectContextService $context,
    ): void {
        $submission->load(['requirement.item.criterion.project', 'documents']);
        Gate::authorize('view', $submission);
        $context->synchronize($submission->requirement->item->criterion->project);
        $this->submissionId = $submission->id;
        if (! Gate::allows('verify', $submission) && Gate::allows('requestRevision', $submission)) {
            $this->decision = 'need_revision';
        }
    }

    public function updatedReviewNote(): void
    {
        $this->authorizedSubmission();
        $this->confirmingDecision = false;
        $this->resetValidation('reviewNote');
    }

    public function updatedDecision(): void
    {
        $this->authorizedSubmission();
        $this->confirmingDecision = false;
        $this->resetValidation();

        if ($this->decision === 'verified') {
            foreach ($this->correctionAttachments as $attachment) {
                if ($attachment instanceof TemporaryUploadedFile) {
                    $attachment->delete();
                }
            }

            $this->correctionAttachments = [];
        }
    }

    public function updatedCorrectionAttachments(AccreditationReviewWorkflowService $workflow): void
    {
        $this->authorizedSubmission();
        $this->successMessage = null;
        $this->confirmingDecision = false;
        $this->resetValidation('correctionAttachments');
        $workflow->validateCorrectionAttachments($this->correctionAttachments);
    }

    public function removeCorrectionAttachment(int $index, AccreditationReviewWorkflowService $workflow): void
    {
        $this->authorizedSubmission();
        if (array_key_exists($index, $this->correctionAttachments)) {
            if ($this->correctionAttachments[$index] instanceof TemporaryUploadedFile) {
                $this->correctionAttachments[$index]->delete();
            }

            unset($this->correctionAttachments[$index]);
            $this->correctionAttachments = array_values($this->correctionAttachments);
            $this->confirmingDecision = false;
            $this->resetValidation('correctionAttachments');
            $workflow->validateCorrectionAttachments($this->correctionAttachments);
        }
    }

    public function confirmDecision(AccreditationReviewWorkflowService $workflow): void
    {
        $submission = $this->authorizedSubmission();
        $this->successMessage = null;
        $this->resetValidation();
        $workflow->validateDecisionInput($this->decision, $this->reviewNote, $this->correctionAttachments);
        Gate::authorize(
            $this->decision === 'verified' ? 'verify' : 'requestRevision',
            $submission,
        );
        $this->confirmingDecision = true;
    }

    public function cancelDecisionConfirmation(): void
    {
        $this->authorizedSubmission();
        $this->confirmingDecision = false;
    }

    public function submitDecision(AccreditationReviewWorkflowService $workflow): void
    {
        $submission = $this->authorizedSubmission();
        if (! $this->confirmingDecision) {
            throw ValidationException::withMessages([
                'decision' => __('verification.errors.confirm_decision'),
            ]);
        }

        $this->successMessage = null;
        $this->resetValidation();
        $decided = $workflow->decide(
            $submission,
            Auth::user(),
            $this->decision,
            $this->reviewNote,
            $this->correctionAttachments,
        );
        foreach ($this->correctionAttachments as $attachment) {
            if ($attachment instanceof TemporaryUploadedFile) {
                try {
                    $attachment->delete();
                } catch (\Throwable) {
                    // Livewire's scheduled temporary-file cleanup remains the fallback.
                }
            }
        }
        $this->reviewNote = '';
        $this->correctionAttachments = [];
        $this->confirmingDecision = false;
        $this->successMessage = $decided->status === 'verified'
            ? __('verification.notifications.verified')
            : __('verification.notifications.revision_requested');
    }

    public function render()
    {
        $submission = $this->authorizedSubmission();
        $submission->load([
            'submitter',
            'decider',
            'documents.uploader',
            'events.actor',
            'events.attachments',
            'requirement.item.criterion.project.studyProgram',
        ]);
        $history = AccreditationReviewSubmission::query()
            ->with(['submitter', 'decider', 'events.actor', 'events.attachments'])
            ->withCount('documents')
            ->where('requirement_id', $submission->requirement_id)
            ->orderByDesc('revision_number')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (AccreditationReviewSubmission $historySubmission): bool => Gate::forUser(Auth::user())
                ->allows('view', $historySubmission))
            ->values();

        return view('livewire.documents.verification-show', [
            'submission' => $submission,
            'history' => $history,
            'canDecide' => $submission->status === 'pending_review'
                && (Gate::allows('verify', $submission) || Gate::allows('requestRevision', $submission)),
            'canVerify' => Gate::allows('verify', $submission),
            'canRequestRevision' => Gate::allows('requestRevision', $submission),
            'statusLabels' => [
                'pending_review' => __('verification.statuses.pending_review'),
                'verified' => __('verification.statuses.verified'),
                'need_revision' => __('verification.statuses.need_revision'),
            ],
            'projectStatusLabel' => $this->projectStatusLabel(
                $submission->requirement->item->criterion->project->status,
            ),
        ])->title(__('verification.meta.show'));
    }

    private function authorizedSubmission(): AccreditationReviewSubmission
    {
        $submission = AccreditationReviewSubmission::query()
            ->with(['requirement.item.criterion.project', 'documents'])
            ->findOrFail($this->submissionId);
        Gate::authorize('view', $submission);

        return $submission;
    }

    private function projectStatusLabel(string $status): string
    {
        $key = 'verification.project_statuses.'.$status;

        return app('translator')->has($key)
            ? __($key)
            : __('verification.project_statuses.unknown');
    }
}
