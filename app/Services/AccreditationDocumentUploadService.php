<?php

namespace App\Services;

use App\Models\AccreditationActivityLog;
use App\Models\AccreditationDocument;
use App\Models\AccreditationDocumentRequirement;
use App\Models\User;
use App\Rules\SecureAccreditationDocument;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AccreditationDocumentUploadService
{
    public function __construct(
        private AccreditationAuthorizationService $authorization,
        private AccreditationDocumentPathService $paths,
        private AccreditationProjectLifecycleService $lifecycle,
    ) {}

    public function uploadBatch(AccreditationDocumentRequirement $requirement, User $user, array $files): Collection
    {
        $requirement->loadMissing('item.criterion.project.studyProgram');

        if (Auth::id() !== $user->id || ! $this->authorization->canUploadRequirement($user, $requirement)) {
            throw new AuthorizationException(__('documents.errors.upload_unauthorized'));
        }

        $this->validateFiles($files);
        $actorId = Auth::id();
        $isEmergencyUpload = $user->hasRole('Super Admin');
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($requirement, $user, $files, $actorId, $isEmergencyUpload, &$storedPaths): Collection {
                $this->lifecycle->lockAndAssertWorkflowMutable(
                    $requirement->item->criterion->project_id,
                );
                $target = AccreditationDocumentRequirement::query()
                    ->with('item.criterion.project.studyProgram')
                    ->lockForUpdate()
                    ->findOrFail($requirement->id);

                if (! $this->authorization->canUploadRequirement($user, $target)) {
                    throw new AuthorizationException(__('documents.errors.upload_unauthorized'));
                }

                $revision = $target->workflow_status === 'need_revision'
                    ? max(1, $target->current_review_revision + 1)
                    : max(1, $target->current_review_revision);
                $documents = collect();

                foreach ($files as $file) {
                    $uploadedAt = now();
                    $uuid = (string) Str::uuid();
                    $extension = strtolower($file->getClientOriginalExtension());
                    $path = $this->paths->build($target, $revision, $uuid, $extension);
                    $stream = fopen($file->getRealPath(), 'rb');

                    if ($stream === false) {
                        throw new RuntimeException(__('documents.errors.temporary_file_unreadable'));
                    }

                    try {
                        $written = Storage::disk('local')->put($path, $stream, ['visibility' => 'private']);
                    } finally {
                        fclose($stream);
                    }

                    if (! $written) {
                        throw new RuntimeException(__('documents.errors.private_storage_write_failed'));
                    }

                    $storedPaths[] = $path;

                    if (! Storage::disk('local')->setVisibility($path, 'private')) {
                        throw new RuntimeException(__('documents.errors.private_visibility_failed'));
                    }

                    $document = AccreditationDocument::create([
                        'requirement_id' => $target->id,
                        'uploaded_by' => $actorId,
                        'original_name' => $file->getClientOriginalName(),
                        'disk' => 'local',
                        'storage_path' => $path,
                        'storage_uuid' => $uuid,
                        'mime_type' => $file->getMimeType(),
                        'extension' => $extension,
                        'size_bytes' => Storage::disk('local')->size($path),
                        'sha256' => $this->storedFileHash($path),
                        'revision_number' => $revision,
                        'is_active' => true,
                        'uploaded_at' => $uploadedAt,
                        'superseded_at' => null,
                    ]);

                    AccreditationActivityLog::create([
                        'project_id' => $target->item->criterion->project_id,
                        'actor_id' => $actorId,
                        'event' => $isEmergencyUpload ? 'document.emergency_uploaded' : 'document.uploaded',
                        'subject_type' => AccreditationDocument::class,
                        'subject_id' => $document->id,
                        'old_values' => null,
                        'new_values' => [
                            'requirement_id' => $target->id,
                            'original_name' => $document->original_name,
                            'size_bytes' => $document->size_bytes,
                            'sha256' => $document->sha256,
                            'revision_number' => $revision,
                            'upload_mode' => $isEmergencyUpload ? 'super_admin_emergency' : 'pic',
                        ],
                        'ip_address' => request()->ip(),
                        'user_agent' => request()->userAgent(),
                        'created_at' => $uploadedAt,
                    ]);

                    $documents->push($document);
                }

                if ($target->workflow_status === 'not_started') {
                    $target->update(['workflow_status' => 'draft']);
                }

                return $documents;
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                try {
                    if (! Storage::disk('local')->delete($path)
                        && Storage::disk('local')->exists($path)) {
                        throw new RuntimeException(__('documents.errors.rollback_delete_failed'));
                    }
                } catch (Throwable $cleanupException) {
                    throw new RuntimeException(
                        __('documents.errors.upload_cleanup_failed'),
                        previous: $exception,
                    );
                }
            }

            throw $exception;
        }
    }

    public function validateFiles(array $files): void
    {
        Validator::make(
            ['files' => $files],
            [
                'files' => ['required', 'array', 'min:1', 'max:10'],
                'files.*' => ['bail', 'required', 'file', 'max:51200', new SecureAccreditationDocument],
            ],
            [
                'files.required' => __('documents.validation.files_required'),
                'files.max' => __('documents.validation.files_max'),
                'files.*.file' => __('documents.validation.file_invalid'),
                'files.*.max' => __('documents.validation.file_too_large'),
            ],
        )->validate();
    }

    private function storedFileHash(string $path): string
    {
        $stream = Storage::disk('local')->readStream($path);

        if ($stream === false) {
            throw new RuntimeException(__('documents.errors.stored_file_unverifiable'));
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
