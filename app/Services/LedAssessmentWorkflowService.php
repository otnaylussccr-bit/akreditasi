<?php

namespace App\Services;

use App\Models\AccreditationActivityLog;
use App\Models\AccreditationLedAssessment;
use App\Models\AccreditationLedAssessmentRevision;
use App\Models\AccreditationLedAssessorAssignment;
use App\Models\AccreditationLedReviewEvent;
use App\Models\AccreditationLedRubric;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LedAssessmentWorkflowService
{
    public function __construct(private readonly AccreditationProjectLifecycleService $lifecycle) {}

    public function saveDraft(AccreditationLedAssessment $assessment, User $actor, array $data): AccreditationLedAssessmentRevision
    {
        $this->assertActor($actor);
        $assessment->loadMissing(['project', 'cycle', 'indicator']);
        Gate::forUser($actor)->authorize('update', $assessment);

        return DB::transaction(function () use ($assessment, $actor, $data): AccreditationLedAssessmentRevision {
            $this->lifecycle->lockAndAssertWorkflowMutable($assessment->project_id);
            $target = AccreditationLedAssessment::query()->with(['project', 'cycle', 'indicator'])->lockForUpdate()->findOrFail($assessment->id);
            Gate::forUser($actor)->authorize('update', $target);
            $rubric = AccreditationLedRubric::query()
                ->where('indicator_id', $target->indicator_id)
                ->findOrFail((int) ($data['rubric_id'] ?? 0));
            $working = $target->current_working_revision_id
                ? AccreditationLedAssessmentRevision::query()->lockForUpdate()->findOrFail($target->current_working_revision_id)
                : null;

            if ($working && in_array($working->status, ['submitted', 'in_review'], true)) {
                throw ValidationException::withMessages(['assessment' => __('led_assessment.validation.revision_under_review')]);
            }

            if ($working && $working->status === 'approved') {
                throw ValidationException::withMessages(['assessment' => __('led_assessment.validation.approved_cannot_reopen')]);
            }

            if (! $working || $working->status === 'revision_requested') {
                $working = AccreditationLedAssessmentRevision::create([
                    'assessment_id' => $target->id,
                    'project_id' => $target->project_id,
                    'indicator_id' => $target->indicator_id,
                    'revision_number' => ((int) $target->revisions()->max('revision_number')) + 1,
                    'status' => 'draft',
                    'created_by' => $actor->id,
                ]);
                $target->forceFill(['current_working_revision_id' => $working->id])->save();
            }

            if ($working->status !== 'draft') {
                throw new AuthorizationException(__('led_assessment.errors.only_active_assessor'));
            }

            $working->update([
                'rubric_id' => $rubric->id,
                'justification' => $this->nullableText($data['justification'] ?? null),
                'gap_analysis' => $this->nullableText($data['gap_analysis'] ?? null),
                'recommendation' => $this->nullableText($data['recommendation'] ?? null),
            ]);
            $this->recordTransition($working, $actor, 'draft_saved', 'draft', 'draft');

            return $working->fresh(['rubric', 'evidence', 'events']);
        });
    }

    public function submit(AccreditationLedAssessmentRevision $revision, User $actor): AccreditationLedAssessmentRevision
    {
        $this->assertActor($actor);
        $revision->loadMissing('assessment.project', 'assessment.cycle');
        Gate::forUser($actor)->authorize('submit', $revision);

        return DB::transaction(function () use ($revision, $actor): AccreditationLedAssessmentRevision {
            $this->lifecycle->lockAndAssertWorkflowMutable($revision->project_id);
            $target = $this->lockedRevision($revision->id);
            Gate::forUser($actor)->authorize('submit', $target);

            if ($target->status !== 'draft' || ! $target->rubric_id || trim((string) $target->justification) === '') {
                throw ValidationException::withMessages(['assessment' => __('led_assessment.validation.draft_requirements')]);
            }

            $hasIndependentReviewer = AccreditationLedAssessorAssignment::query()
                ->with(['user.roles.permissions', 'user.permissions'])
                ->where('cycle_id', $target->assessment->cycle_id)
                ->where('indicator_id', $target->indicator_id)
                ->where('assignment_role', 'reviewer')
                ->whereNull('revoked_at')
                ->get()
                ->contains(fn (AccreditationLedAssessorAssignment $assignment): bool => $assignment->user !== null
                    && $assignment->user->is_active
                    && $assignment->user->can('project.view')
                    && $assignment->user_id !== $actor->id
                    && $assignment->user_id !== $target->created_by);

            if (! $hasIndependentReviewer) {
                throw ValidationException::withMessages(['assessment' => __('led_assessment.validation.independent_reviewer_required')]);
            }

            $target->update(['status' => 'submitted', 'submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->recordTransition($target, $actor, 'submitted', 'draft', 'submitted');

            return $target->fresh(['rubric', 'events']);
        });
    }

    public function startReview(AccreditationLedAssessmentRevision $revision, User $reviewer): AccreditationLedAssessmentRevision
    {
        return $this->reviewTransition($revision, $reviewer, 'submitted', 'in_review', 'review_started', null);
    }

    public function requestRevision(AccreditationLedAssessmentRevision $revision, User $reviewer, string $note): AccreditationLedAssessmentRevision
    {
        if (trim($note) === '') {
            throw ValidationException::withMessages(['decision_note' => __('led_assessment.validation.revision_note_required')]);
        }

        return $this->reviewTransition($revision, $reviewer, 'in_review', 'revision_requested', 'revision_requested', trim($note));
    }

    public function approve(AccreditationLedAssessmentRevision $revision, User $reviewer, ?string $note = null): AccreditationLedAssessmentRevision
    {
        $this->assertActor($reviewer);
        $revision->loadMissing('assessment.project', 'assessment.cycle');
        Gate::forUser($reviewer)->authorize('approve', $revision);

        return DB::transaction(function () use ($revision, $reviewer, $note): AccreditationLedAssessmentRevision {
            $this->lifecycle->lockAndAssertWorkflowMutable($revision->project_id);
            $assessment = AccreditationLedAssessment::query()->with(['project', 'cycle'])->lockForUpdate()->findOrFail($revision->assessment_id);
            $target = AccreditationLedAssessmentRevision::query()->with('assessment.project', 'assessment.cycle')->lockForUpdate()->findOrFail($revision->id);
            Gate::forUser($reviewer)->authorize('approve', $target);

            if ($target->status === 'approved' && $assessment->current_approved_revision_id === $target->id) {
                return $target->fresh(['rubric', 'events']);
            }

            if ($target->status !== 'in_review' || ! $target->rubric_id || $assessment->current_working_revision_id !== $target->id) {
                throw ValidationException::withMessages(['approval' => __('led_assessment.validation.only_active_review_approvable')]);
            }

            $target->update(['status' => 'approved', 'decided_by' => $reviewer->id, 'decided_at' => now()]);
            $assessment->forceFill([
                'current_approved_revision_id' => $target->id,
                'current_working_revision_id' => $target->id,
            ])->save();
            $this->recordTransition($target, $reviewer, 'approved', 'in_review', 'approved', $this->nullableText($note));

            return $target->fresh(['rubric', 'events']);
        });
    }

    private function reviewTransition(
        AccreditationLedAssessmentRevision $revision,
        User $reviewer,
        string $from,
        string $to,
        string $action,
        ?string $note,
    ): AccreditationLedAssessmentRevision {
        $this->assertActor($reviewer);
        $revision->loadMissing('assessment.project', 'assessment.cycle');
        Gate::forUser($reviewer)->authorize('review', $revision);

        return DB::transaction(function () use ($revision, $reviewer, $from, $to, $action, $note): AccreditationLedAssessmentRevision {
            $this->lifecycle->lockAndAssertWorkflowMutable($revision->project_id);
            $target = $this->lockedRevision($revision->id);
            Gate::forUser($reviewer)->authorize('review', $target);

            if ($target->status !== $from || $target->assessment->current_working_revision_id !== $target->id) {
                throw ValidationException::withMessages(['review' => __('led_assessment.validation.revision_status_changed')]);
            }

            $values = ['status' => $to];
            if ($to === 'revision_requested') {
                $values += ['decided_by' => $reviewer->id, 'decided_at' => now()];
            }
            $target->update($values);
            $this->recordTransition($target, $reviewer, $action, $from, $to, $note);

            return $target->fresh(['rubric', 'events']);
        });
    }

    private function lockedRevision(int $id): AccreditationLedAssessmentRevision
    {
        return AccreditationLedAssessmentRevision::query()
            ->with(['assessment.project', 'assessment.cycle'])
            ->lockForUpdate()
            ->findOrFail($id);
    }

    private function recordTransition(AccreditationLedAssessmentRevision $revision, User $actor, string $action, string $from, string $to, ?string $note = null): void
    {
        $at = now();
        AccreditationLedReviewEvent::create([
            'revision_id' => $revision->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'from_state' => $from,
            'to_state' => $to,
            'decision_note' => $note,
            'payload_json' => ['revision_number' => $revision->revision_number],
            'correlation_id' => (string) Str::uuid(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'occurred_at' => $at,
        ]);
        AccreditationActivityLog::create([
            'project_id' => $revision->project_id,
            'actor_id' => $actor->id,
            'event' => 'assessment.'.$action,
            'subject_type' => $revision::class,
            'subject_id' => $revision->id,
            'old_values' => ['status' => $from],
            'new_values' => ['status' => $to, 'revision_number' => $revision->revision_number],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => $at,
        ]);
    }

    private function assertActor(User $actor): void
    {
        if (Auth::id() !== $actor->id || ! $actor->is_active) {
            throw new AuthorizationException(__('led_assessment.errors.invalid_actor'));
        }
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
