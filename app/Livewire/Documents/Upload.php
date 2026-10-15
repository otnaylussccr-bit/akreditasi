<?php

namespace App\Livewire\Documents;

use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationReviewAttachment;
use App\Models\User;
use App\Services\AccreditationDocumentUploadService;
use App\Services\AccreditationProjectContextService;
use App\Services\AccreditationProjectLifecycleService;
use App\Services\AccreditationReviewWorkflowService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
#[Title('Upload Dokumen | APU Accreditation')]
class Upload extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $requirementId;

    public array $files = [];

    public ?string $successMessage = null;

    public function mount(
        AccreditationDocumentRequirement $requirement,
        AccreditationProjectContextService $context,
    ): void {
        $requirement->load('item.criterion.project');
        Gate::authorize('accessUpload', $requirement);
        $context->synchronize($requirement->item->criterion->project);
        $this->requirementId = $requirement->id;
    }

    public function removeFile(int $index): void
    {
        $requirement = $this->authorizedRequirement();
        Gate::authorize('accessUpload', $requirement);

        if (array_key_exists($index, $this->files)) {
            if ($this->files[$index] instanceof TemporaryUploadedFile) {
                $this->files[$index]->delete();
            }

            unset($this->files[$index]);
            $this->files = array_values($this->files);
            $this->resetValidation();

            if ($this->files !== []) {
                app(AccreditationDocumentUploadService::class)->validateFiles($this->files);
            }
        }
    }

    public function updatedFiles(): void
    {
        $requirement = $this->authorizedRequirement();
        Gate::authorize('accessUpload', $requirement);
        $this->successMessage = null;
        $this->resetValidation();
        app(AccreditationDocumentUploadService::class)->validateFiles($this->files);
    }

    public function saveDocuments(AccreditationDocumentUploadService $uploadService): void
    {
        $requirement = $this->authorizedRequirement();
        Gate::authorize('upload', $requirement);
        $this->successMessage = null;
        $documents = $uploadService->uploadBatch($requirement, Auth::user(), $this->files);

        $this->reset('files');
        $this->resetValidation();
        $this->successMessage = $documents->count().' file berhasil disimpan secara privat.';
    }

    public function submitForReview(AccreditationReviewWorkflowService $workflow): void
    {
        $requirement = $this->authorizedRequirement();
        Gate::authorize('submit', $requirement->item->criterion);

        if ($this->files !== []) {
            throw ValidationException::withMessages([
                'submission' => 'Simpan atau hapus file dalam antrean sebelum mengirim ke verifikator.',
            ]);
        }

        $this->successMessage = null;
        $this->resetValidation('submission');
        $submission = $workflow->submit($requirement, Auth::user());
        $this->successMessage = 'Revisi '.$submission->revision_number.' berhasil dikirim ke verifikator.';
    }

    public function render(
        AccreditationReviewWorkflowService $workflow,
        AccreditationProjectLifecycleService $lifecycle,
    ) {
        $requirement = $this->authorizedRequirement();
        Gate::authorize('accessUpload', $requirement);
        $documents = $requirement->documents()
            ->with('uploader')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->get();
        $submissionNotes = $requirement->reviewSubmissions()
            ->whereNotNull('review_note')
            ->where('review_note', '!=', '')
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->get();
        $reviewerNames = User::query()
            ->whereIn('id', $submissionNotes->pluck('decided_by')->filter()->unique())
            ->pluck('name', 'id');
        $reviewEvents = DB::table('accreditation_review_events as events')
            ->join('accreditation_review_submissions as submissions', 'submissions.id', '=', 'events.submission_id')
            ->leftJoin('users as actors', 'actors.id', '=', 'events.actor_id')
            ->where('submissions.requirement_id', $requirement->id)
            ->whereNotNull('events.note')
            ->where('events.note', '!=', '')
            ->select([
                'events.id',
                'events.submission_id',
                'events.action',
                'events.note',
                'events.created_at',
                'actors.name as actor_name',
            ])
            ->get();
        $attachmentsByEvent = AccreditationReviewAttachment::query()
            ->whereIn('review_event_id', $reviewEvents->pluck('id'))
            ->orderBy('id')
            ->get()
            ->groupBy('review_event_id');
        $reviewNotes = $reviewEvents
            ->map(fn (object $event): array => [
                'key' => 'event-'.$event->id,
                'submission_id' => $event->submission_id,
                'actor_name' => $event->actor_name ?? 'Verifikator',
                'status' => $event->action,
                'note' => $event->note,
                'created_at' => Carbon::parse($event->created_at),
                'attachments' => $attachmentsByEvent->get($event->id, collect()),
            ])
            ->concat($submissionNotes->map(fn ($submission): array => [
                'key' => 'submission-'.$submission->id,
                'submission_id' => $submission->id,
                'actor_name' => $reviewerNames[$submission->decided_by] ?? 'Verifikator',
                'status' => $submission->status,
                'note' => $submission->review_note,
                'created_at' => $submission->decided_at ?? $submission->updated_at,
                'attachments' => collect(),
            ]))
            ->unique(fn (array $note): string => implode('|', [
                $note['submission_id'],
                $note['actor_name'],
                $note['status'],
                $note['note'],
            ]))
            ->sortByDesc(fn (array $note) => $note['created_at']->getTimestamp())
            ->values();
        $hasPendingSubmission = $requirement->reviewSubmissions()
            ->where('status', 'pending_review')
            ->exists();
        $activeDocumentCount = $documents->where('is_active', true)->count();
        $requiresRevisionUpload = $requirement->workflow_status === 'need_revision'
            && ! $documents->where('is_active', true)
                ->contains('revision_number', $requirement->current_review_revision + 1);
        $canSubmitForReview = Gate::allows('submit', $requirement->item->criterion)
            && in_array($requirement->workflow_status, ['draft', 'need_revision'], true)
            && $activeDocumentCount > 0
            && ! $requiresRevisionUpload
            && ! $hasPendingSubmission;

        return view('livewire.documents.upload', [
            'requirement' => $requirement,
            'documents' => $documents,
            'documentStats' => [
                'total' => $documents->count(),
                'active' => $documents->where('is_active', true)->count(),
                'historical' => $documents->where('is_active', false)->count(),
            ],
            'reviewNotes' => $reviewNotes,
            'pic' => Auth::user(),
            'canSubmitForReview' => $canSubmitForReview,
            'requiresRevisionUpload' => $requiresRevisionUpload,
            'hasActiveReviewer' => $workflow->hasActiveReviewer(
                $requirement->item->criterion,
                $documents->where('is_active', true)->pluck('uploaded_by')->push(Auth::id())->unique()->values()->all(),
            ),
            'workflowMutable' => $lifecycle->allowsWorkflowMutation(
                $requirement->item->criterion->project,
            ),
        ]);
    }

    private function authorizedRequirement(): AccreditationDocumentRequirement
    {
        return AccreditationDocumentRequirement::query()
            ->with('item.criterion.project.studyProgram')
            ->findOrFail($this->requirementId);
    }
}
