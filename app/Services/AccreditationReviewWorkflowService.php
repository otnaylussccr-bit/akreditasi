<?php

namespace App\Services;

use App\Models\AccreditationActivityLog;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationCriterionAssignment;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationReviewAttachment;
use App\Models\AccreditationReviewEvent;
use App\Models\AccreditationReviewSubmission;
use App\Models\User;
use App\Rules\SecureAccreditationDocument;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AccreditationReviewWorkflowService
{
    public function __construct(
        private readonly AccreditationProjectLifecycleService $lifecycle,
    ) {}

    public function hasActiveReviewer(AccreditationCriterion $criterion, array $excludedUserIds = []): bool
    {
        return AccreditationCriterionAssignment::query()
            ->with('user')
            ->where('criterion_id', $criterion->id)
            ->where('assignment_role', 'reviewer')
            ->where('is_active', true)
            ->when($excludedUserIds !== [], fn ($query) => $query->whereNotIn('user_id', $excludedUserIds))
            ->get()
            ->contains(fn (AccreditationCriterionAssignment $assignment): bool => $assignment->user->hasRole('Reviewer')
                && ! $assignment->user->hasRole('Super Admin')
                && $assignment->user->can('document.review'));
    }

    public function submit(AccreditationDocumentRequirement $requirement, User $user): AccreditationReviewSubmission
    {
        if (Auth::id() !== $user->id) {
            throw new AuthorizationException(__('verification.errors.unauthorized_submit'));
        }

        $requirement->loadMissing('item.criterion.project');
        Gate::forUser($user)->authorize('submit', $requirement->item->criterion);

        return DB::transaction(function () use ($requirement, $user): AccreditationReviewSubmission {
            $this->lockAndAssertWorkflowMutable(
                $requirement->item->criterion->project_id,
            );
            $target = AccreditationDocumentRequirement::query()
                ->with('item.criterion.project')
                ->lockForUpdate()
                ->findOrFail($requirement->id);

            Gate::forUser($user)->authorize('submit', $target->item->criterion);

            if (! in_array($target->workflow_status, ['draft', 'need_revision'], true)) {
                throw ValidationException::withMessages([
                    'submission' => __('verification.errors.invalid_submit_status'),
                ]);
            }

            if ($target->reviewSubmissions()->where('status', 'pending_review')->exists()) {
                throw ValidationException::withMessages([
                    'submission' => __('verification.errors.already_pending'),
                ]);
            }

            $documents = $target->documents()
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($documents->isEmpty()) {
                throw ValidationException::withMessages([
                    'submission' => __('verification.errors.active_file_required'),
                ]);
            }

            $oldStatus = $target->workflow_status;
            $revision = $oldStatus === 'need_revision'
                ? $target->current_review_revision + 1
                : max(1, $target->current_review_revision);

            if ($oldStatus === 'need_revision'
                && ! $documents->contains('revision_number', $revision)) {
                throw ValidationException::withMessages([
                    'submission' => __('verification.errors.new_revision_file_required'),
                ]);
            }

            if (! $this->hasActiveReviewer(
                $target->item->criterion,
                $documents->pluck('uploaded_by')->push($user->id)->unique()->values()->all(),
            )) {
                throw ValidationException::withMessages([
                    'submission' => __('verification.errors.independent_reviewer_required'),
                ]);
            }

            if ($documents->contains(fn ($document): bool => ! Storage::disk($document->disk)->exists($document->storage_path))) {
                throw ValidationException::withMessages([
                    'submission' => __('verification.errors.active_file_missing'),
                ]);
            }

            $submittedAt = now();
            $submission = AccreditationReviewSubmission::create([
                'requirement_id' => $target->id,
                'submitted_by' => $user->id,
                'revision_number' => $revision,
                'status' => 'pending_review',
                'submitted_at' => $submittedAt,
                'decided_at' => null,
                'decided_by' => null,
                'review_note' => null,
            ]);

            $submission->documents()->attach($documents->modelKeys());
            $target->update([
                'workflow_status' => 'pending_review',
                'current_review_revision' => $revision,
            ]);

            AccreditationReviewEvent::create([
                'submission_id' => $submission->id,
                'actor_id' => $user->id,
                'action' => 'submit',
                'note' => null,
                'created_at' => $submittedAt,
            ]);
            $this->logActivity(
                $target,
                $user,
                $submission,
                'document.submitted',
                ['workflow_status' => $oldStatus],
                [
                    'workflow_status' => 'pending_review',
                    'revision_number' => $revision,
                    'document_ids' => $documents->modelKeys(),
                ],
                $submittedAt,
            );

            return $submission->load('documents');
        });
    }

    public function decide(
        AccreditationReviewSubmission $submission,
        User $reviewer,
        string $decision,
        ?string $note,
        array $correctionAttachments = [],
    ): AccreditationReviewSubmission {
        if (Auth::id() !== $reviewer->id) {
            throw new AuthorizationException(__('verification.errors.unauthorized_decision'));
        }

        $candidate = AccreditationReviewSubmission::query()
            ->with('requirement.item.criterion.project')
            ->findOrFail($submission->id);
        Gate::forUser($reviewer)->authorize(
            $decision === 'verified' ? 'verify' : 'requestRevision',
            $candidate,
        );
        [$decision, $note] = $this->validateDecisionInput($decision, $note, $correctionAttachments);
        $requirementId = $candidate->requirement_id;
        $projectId = $candidate->requirement->item->criterion->project_id;
        $storedPaths = [];

        try {
            return DB::transaction(function () use (
                $submission,
                $reviewer,
                $decision,
                $note,
                $correctionAttachments,
                $requirementId,
                $projectId,
                &$storedPaths,
            ): AccreditationReviewSubmission {
                $this->lockAndAssertWorkflowMutable($projectId);
                $requirement = AccreditationDocumentRequirement::query()
                    ->with('item.criterion.project.studyProgram')
                    ->lockForUpdate()
                    ->findOrFail($requirementId);
                $target = AccreditationReviewSubmission::query()
                    ->with(['requirement.item.criterion.project.studyProgram', 'documents'])
                    ->lockForUpdate()
                    ->findOrFail($submission->id);

                Gate::forUser($reviewer)->authorize(
                    $decision === 'verified' ? 'verify' : 'requestRevision',
                    $target,
                );

                if ($target->requirement_id !== $requirement->id
                    || $target->status !== 'pending_review'
                    || $requirement->workflow_status !== 'pending_review'
                    || $target->revision_number !== $requirement->current_review_revision) {
                    throw ValidationException::withMessages([
                        'decision' => __('verification.errors.submission_already_processed'),
                    ]);
                }

                $decidedAt = now();
                $target->update([
                    'status' => $decision,
                    'decided_at' => $decidedAt,
                    'decided_by' => $reviewer->id,
                    'review_note' => $note !== '' ? $note : null,
                ]);
                $requirement->update(['workflow_status' => $decision]);

                $event = AccreditationReviewEvent::create([
                    'submission_id' => $target->id,
                    'actor_id' => $reviewer->id,
                    'action' => $decision,
                    'note' => $note !== '' ? $note : null,
                    'created_at' => $decidedAt,
                ]);
                $attachments = collect();

                foreach ($correctionAttachments as $file) {
                    $uuid = (string) Str::uuid();
                    $extension = strtolower($file->getClientOriginalExtension());
                    $path = implode('/', [
                        'accreditation-review-attachments',
                        'projects',
                        $requirement->item->criterion->project_id,
                        'requirements',
                        $requirement->id,
                        'revisions',
                        'v'.$target->revision_number,
                        'events',
                        $event->id,
                        $uuid.'.'.$extension,
                    ]);
                    $stream = fopen($file->getRealPath(), 'rb');

                    if ($stream === false) {
                        throw new RuntimeException(__('verification.errors.temporary_attachment_unreadable'));
                    }

                    $storedPaths[] = $path;
                    try {
                        $written = Storage::disk('local')->put($path, $stream, ['visibility' => 'private']);
                    } finally {
                        fclose($stream);
                    }

                    if (! $written) {
                        throw new RuntimeException(__('verification.errors.attachment_store_failed'));
                    }

                    if (! Storage::disk('local')->setVisibility($path, 'private')) {
                        throw new RuntimeException(__('verification.errors.attachment_privacy_failed'));
                    }

                    $attachments->push(AccreditationReviewAttachment::create([
                        'review_event_id' => $event->id,
                        'storage_uuid' => $uuid,
                        'storage_path' => $path,
                        'original_filename' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'extension' => $extension,
                        'file_size' => Storage::disk('local')->size($path),
                        'sha256' => $this->storedFileHash(Storage::disk('local'), $path),
                        'uploaded_at' => $decidedAt,
                    ]));
                }

                $this->logActivity(
                    $requirement,
                    $reviewer,
                    $target,
                    $decision === 'verified' ? 'document.verified' : 'document.revision_requested',
                    ['workflow_status' => 'pending_review', 'submission_status' => 'pending_review'],
                    [
                        'workflow_status' => $decision,
                        'submission_status' => $decision,
                        'review_event_id' => $event->id,
                        'reviewer_id' => $reviewer->id,
                        'revision_number' => $target->revision_number,
                        'review_note' => $note ?: null,
                        'correction_attachments' => $attachments->map(fn (AccreditationReviewAttachment $attachment): array => [
                            'id' => $attachment->id,
                            'original_filename' => $attachment->original_filename,
                            'extension' => $attachment->extension,
                            'mime_type' => $attachment->mime_type,
                            'file_size' => $attachment->file_size,
                            'sha256' => $attachment->sha256,
                        ])->all(),
                    ],
                    $decidedAt,
                );

                return $target->fresh(['documents', 'decider', 'events.attachments']);
            });
        } catch (Throwable $exception) {
            $cleanupFailed = false;
            foreach ($storedPaths as $path) {
                try {
                    if (Storage::disk('local')->exists($path)
                        && ! Storage::disk('local')->delete($path)) {
                        $cleanupFailed = true;
                    }
                } catch (Throwable) {
                    $cleanupFailed = true;
                }
            }

            if ($cleanupFailed) {
                throw new RuntimeException(
                    __('verification.errors.decision_cleanup_failed'),
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }

    /** @return array{0: string, 1: string} */
    public function validateDecisionInput(
        string $decision,
        ?string $note,
        array $correctionAttachments = [],
    ): array {
        $decision = strtolower(trim($decision));
        $note = trim((string) $note);
        Validator::make(
            [
                'decision' => $decision,
                'reviewNote' => $note,
            ],
            [
                'decision' => ['required', 'in:verified,need_revision'],
                'reviewNote' => [$decision === 'need_revision' ? 'required' : 'nullable', 'string', 'max:10000'],
            ],
            [
                'decision.required' => __('verification.errors.decision_required'),
                'decision.in' => __('verification.errors.invalid_decision'),
                'reviewNote.required' => __('verification.errors.revision_note_required'),
                'reviewNote.string' => __('verification.errors.revision_note_string'),
                'reviewNote.max' => __('verification.errors.revision_note_max'),
            ],
        )->validate();

        if ($decision === 'verified' && $correctionAttachments !== []) {
            throw ValidationException::withMessages([
                'correctionAttachments' => __('verification.errors.attachments_revision_only'),
            ]);
        }

        $this->validateCorrectionAttachments($correctionAttachments);

        return [$decision, $note];
    }

    public function validateCorrectionAttachments(array $correctionAttachments): void
    {
        Validator::make(
            ['correctionAttachments' => $correctionAttachments],
            [
                'correctionAttachments' => ['array', 'max:10'],
                'correctionAttachments.*' => [
                    'bail',
                    'required',
                    'file',
                    'max:51200',
                    static function (string $attribute, mixed $value, \Closure $fail): void {
                        (new SecureAccreditationDocument)->validate(
                            $attribute,
                            $value,
                            static function (string $message) use ($fail): void {
                                $fail(match ($message) {
                                    'File tidak dapat dibaca dengan benar. Silakan pilih ulang file.' => __('verification.errors.secure_file_unreadable'),
                                    'Format file harus PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, BMP, atau TIFF.' => __('verification.errors.secure_file_format'),
                                    'Nama file tidak boleh lebih dari 512 karakter.' => __('verification.errors.secure_file_name_max'),
                                    'Konten file tidak dapat diperiksa oleh server.' => __('verification.errors.secure_file_content_uninspectable'),
                                    'Ekstensi file tidak sesuai dengan tipe konten yang terdeteksi.' => __('verification.errors.secure_file_content_mismatch'),
                                    default => $message,
                                });
                            },
                        );
                    },
                    static function (string $attribute, mixed $value, \Closure $fail): void {
                        if (mb_strlen($value->getClientOriginalName()) > 255) {
                            $fail(__('verification.errors.attachment_name_max'));
                        }
                    },
                ],
            ],
            [
                'correctionAttachments.array' => __('verification.errors.attachments_array'),
                'correctionAttachments.max' => __('verification.errors.attachments_max'),
                'correctionAttachments.*.required' => __('verification.errors.attachment_required'),
                'correctionAttachments.*.file' => __('verification.errors.attachment_file'),
                'correctionAttachments.*.max' => __('verification.errors.attachment_size_max'),
            ],
        )->validate();
    }

    private function storedFileHash(FilesystemAdapter $disk, string $path): string
    {
        $stream = $disk->readStream($path);
        if ($stream === false) {
            throw new RuntimeException(__('verification.errors.stored_attachment_unverifiable'));
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private function lockAndAssertWorkflowMutable(int $projectId): void
    {
        try {
            $this->lifecycle->lockAndAssertWorkflowMutable($projectId);
        } catch (AuthorizationException) {
            throw new AuthorizationException(__('verification.errors.project_not_active'));
        }
    }

    private function logActivity(
        AccreditationDocumentRequirement $requirement,
        User $actor,
        AccreditationReviewSubmission $submission,
        string $event,
        array $oldValues,
        array $newValues,
        DateTimeInterface $createdAt,
    ): void {
        AccreditationActivityLog::create([
            'project_id' => $requirement->item->criterion->project_id,
            'actor_id' => $actor->id,
            'event' => $event,
            'subject_type' => AccreditationReviewSubmission::class,
            'subject_id' => $submission->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => $createdAt,
        ]);
    }
}
