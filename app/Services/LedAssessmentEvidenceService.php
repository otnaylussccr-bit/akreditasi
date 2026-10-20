<?php

namespace App\Services;

use App\Models\AccreditationActivityLog;
use App\Models\AccreditationDocument;
use App\Models\AccreditationLedAssessmentEvidence;
use App\Models\AccreditationLedAssessmentRevision;
use App\Models\AccreditationLedIndicatorItemMapping;
use App\Models\AccreditationLedReviewEvent;
use App\Models\AccreditationReviewSubmission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LedAssessmentEvidenceService
{
    public function linkVerifiedDocument(
        AccreditationLedAssessmentRevision $revision,
        AccreditationReviewSubmission $submission,
        AccreditationDocument $document,
        User $actor,
        string $role = 'supporting',
        ?string $note = null,
    ): AccreditationLedAssessmentEvidence {
        if (Auth::id() !== $actor->id || ! in_array($role, ['primary', 'supporting'], true)) {
            throw new AuthorizationException(__('led_assessment.errors.evidence_link_forbidden'));
        }

        $revision->loadMissing('assessment.project', 'assessment.cycle');
        Gate::forUser($actor)->authorize('update', $revision->assessment);

        return DB::transaction(function () use ($revision, $submission, $document, $actor, $role, $note): AccreditationLedAssessmentEvidence {
            $target = AccreditationLedAssessmentRevision::query()
                ->with(['assessment.project', 'assessment.cycle'])
                ->lockForUpdate()
                ->findOrFail($revision->id);
            Gate::forUser($actor)->authorize('update', $target->assessment);

            if ($target->status !== 'draft') {
                throw ValidationException::withMessages(['evidence' => __('led_assessment.validation.evidence_draft_only')]);
            }

            $verified = AccreditationReviewSubmission::query()->with('requirement.item.criterion')->lockForUpdate()->findOrFail($submission->id);
            $source = AccreditationDocument::query()->with('requirement.item.criterion')->lockForUpdate()->findOrFail($document->id);

            if ($verified->status !== 'verified' || ! $verified->decided_at || ! $verified->decided_by
                || $verified->requirement_id !== $source->requirement_id
                || ! $verified->documents()->whereKey($source->id)->exists()) {
                throw ValidationException::withMessages(['evidence' => __('led_assessment.validation.invalid_verified_submission')]);
            }

            $projectId = $source->requirement->item->criterion->project_id;
            if ($projectId !== $target->project_id) {
                throw ValidationException::withMessages(['evidence' => __('led_assessment.validation.document_outside_project')]);
            }

            $mappingExists = AccreditationLedIndicatorItemMapping::query()
                ->where('cycle_id', $target->assessment->cycle_id)
                ->where('project_id', $target->project_id)
                ->where('instrument_version_id', $target->assessment->instrument_version_id)
                ->where('indicator_id', $target->indicator_id)
                ->where('criterion_id', $source->requirement->item->criterion_id)
                ->where('item_id', $source->requirement->item_id)
                ->whereNull('revoked_at')
                ->exists();

            if (! $mappingExists) {
                throw ValidationException::withMessages(['evidence' => __('led_assessment.validation.document_not_mapped')]);
            }

            if (! preg_match('/^[a-f0-9]{64}$/i', $source->sha256)
                || ! Storage::disk($source->disk)->exists($source->storage_path)
                || ! hash_equals(strtolower($source->sha256), $this->storedFileHash($source))) {
                throw ValidationException::withMessages(['evidence' => __('led_assessment.validation.invalid_document_hash')]);
            }

            if (AccreditationLedAssessmentEvidence::where('revision_id', $target->id)->where('document_id', $source->id)->exists()) {
                throw ValidationException::withMessages(['evidence' => __('led_assessment.validation.document_already_linked')]);
            }

            $at = now();
            $evidence = AccreditationLedAssessmentEvidence::create([
                'revision_id' => $target->id,
                'document_id' => $source->id,
                'verification_submission_id' => $verified->id,
                'document_revision_number' => $source->revision_number,
                'document_sha256' => strtolower($source->sha256),
                'evidence_role' => $role,
                'note' => trim((string) $note) ?: null,
                'linked_by' => $actor->id,
                'linked_at' => $at,
            ]);
            AccreditationLedReviewEvent::create([
                'revision_id' => $target->id, 'actor_id' => $actor->id, 'action' => 'evidence_linked',
                'from_state' => 'draft', 'to_state' => 'draft',
                'payload_json' => ['evidence_id' => $evidence->id, 'document_id' => $source->id],
                'correlation_id' => (string) Str::uuid(), 'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(), 'occurred_at' => $at,
            ]);
            AccreditationActivityLog::create([
                'project_id' => $target->project_id, 'actor_id' => $actor->id,
                'event' => 'assessment.evidence_linked', 'subject_type' => $target::class,
                'subject_id' => $target->id, 'new_values' => ['evidence_id' => $evidence->id, 'document_id' => $source->id],
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(), 'created_at' => $at,
            ]);

            return $evidence->load(['document', 'verificationSubmission']);
        });
    }

    private function storedFileHash(AccreditationDocument $document): string
    {
        $stream = Storage::disk($document->disk)->readStream($document->storage_path);
        if ($stream === false) {
            throw new RuntimeException(__('led_assessment.errors.evidence_unreadable'));
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
