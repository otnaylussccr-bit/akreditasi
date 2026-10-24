<?php

namespace App\Services;

use App\Models\AccreditationActivityLog;
use App\Models\AccreditationLedAssessment;
use App\Models\AccreditationLedAssessmentCycle;
use App\Models\AccreditationLedCycleEvent;
use App\Models\AccreditationLedInstrumentVersion;
use App\Models\AccreditationProject;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LedAssessmentCycleService
{
    public function __construct(
        private readonly AccreditationAuthorizationService $authorization,
        private readonly AccreditationProjectLifecycleService $lifecycle,
        private readonly LedOrganizationSnapshotService $snapshots,
    ) {}

    public function create(
        AccreditationProject $project,
        AccreditationLedInstrumentVersion $version,
        string $name,
        User $actor,
    ): AccreditationLedAssessmentCycle {
        if (Auth::id() !== $actor->id || ! $actor->can('project.manage') || ! $this->authorization->canManageProject($actor, $project)) {
            throw new AuthorizationException(__('led_setup.messages.cycle.create_unauthorized'));
        }

        if (trim($name) === '') {
            throw ValidationException::withMessages(['cycle' => __('led_setup.messages.cycle.name_required')]);
        }

        return DB::transaction(function () use ($project, $version, $name, $actor): AccreditationLedAssessmentCycle {
            $lockedProject = $this->lifecycle->lockAndAssertWorkflowMutable($project->id);
            $lockedVersion = AccreditationLedInstrumentVersion::query()->with('indicators')->lockForUpdate()->findOrFail($version->id);

            if ($lockedVersion->status !== 'published' || $lockedProject->instrument_id !== $lockedVersion->instrument_id) {
                throw ValidationException::withMessages(['cycle' => __('led_setup.messages.cycle.published_version_required')]);
            }

            $indicators = $lockedVersion->indicators->where('is_assessable', true);

            if ($indicators->isEmpty()) {
                throw ValidationException::withMessages(['cycle' => __('led_setup.messages.cycle.no_assessable_indicators')]);
            }

            $cycle = AccreditationLedAssessmentCycle::create([
                'project_id' => $lockedProject->id,
                'instrument_id' => $lockedVersion->instrument_id,
                'instrument_version_id' => $lockedVersion->id,
                'cycle_number' => ((int) AccreditationLedAssessmentCycle::where('project_id', $lockedProject->id)->max('cycle_number')) + 1,
                'cycle_name' => trim($name),
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);

            foreach ($indicators as $indicator) {
                AccreditationLedAssessment::create([
                    'cycle_id' => $cycle->id,
                    'project_id' => $lockedProject->id,
                    'instrument_version_id' => $lockedVersion->id,
                    'indicator_id' => $indicator->id,
                ]);
            }

            $this->event($cycle, $actor, 'created', null, 'draft', 'Siklus penilaian LED dibuat.');
            $this->activity($cycle, $actor, 'assessment_cycle.created', [], ['status' => 'draft', 'indicator_count' => $indicators->count()]);

            return $cycle->fresh(['assessments.indicator', 'instrumentVersion']);
        });
    }

    public function readiness(AccreditationLedAssessmentCycle $cycle): array
    {
        $target = AccreditationLedAssessmentCycle::query()
            ->with(['project.criteria', 'assessments.indicator', 'assignments.user.roles', 'itemMappings'])
            ->findOrFail($cycle->id);

        return $this->readinessFor($target);
    }

    public function activate(AccreditationLedAssessmentCycle $cycle, User $actor): AccreditationLedAssessmentCycle
    {
        if (Auth::id() !== $actor->id) {
            throw new AuthorizationException(__('led_setup.messages.cycle.activate_unauthorized'));
        }

        return DB::transaction(function () use ($cycle, $actor): AccreditationLedAssessmentCycle {
            $target = AccreditationLedAssessmentCycle::query()
                ->with(['project.studyProgram', 'project.instrument', 'project.criteria', 'instrumentVersion', 'assessments.indicator', 'assignments.user.roles', 'itemMappings'])
                ->lockForUpdate()
                ->findOrFail($cycle->id);

            if (! app(LedAssessmentAuthorizationService::class)->canManageCycle($actor, $target) || $target->status !== 'draft') {
                throw new AuthorizationException(__('led_setup.messages.cycle.cannot_activate'));
            }

            if ($target->instrumentVersion->status !== 'published') {
                throw ValidationException::withMessages(['cycle' => __('led_setup.messages.cycle.version_must_remain_published')]);
            }

            $readiness = $this->readinessFor($target);

            if (! $readiness['ready']) {
                throw ValidationException::withMessages(['cycle' => __('led_setup.messages.cycle.not_ready')]);
            }

            $organizationSnapshot = $this->snapshots->build($target->project);
            $json = $this->snapshots->json($organizationSnapshot);
            $target->update([
                'status' => 'active',
                'organization_snapshot_json' => $organizationSnapshot,
                'organization_snapshot_sha256' => hash('sha256', $json),
                'activated_by' => $actor->id,
                'activated_at' => now(),
            ]);
            $this->event($target, $actor, 'activated', 'draft', 'active', 'Siklus penilaian LED diaktifkan.');
            $this->activity($target, $actor, 'assessment_cycle.activated', ['status' => 'draft'], ['status' => 'active']);

            return $target->fresh();
        });
    }

    private function readinessFor(AccreditationLedAssessmentCycle $cycle): array
    {
        $indicators = $cycle->assessments
            ->sortBy(fn ($assessment) => $assessment->indicator->position)
            ->map(function ($assessment) use ($cycle): array {
                $assignments = $cycle->assignments
                    ->where('indicator_id', $assessment->indicator_id)
                    ->whereNull('revoked_at')
                    ->filter(fn ($assignment): bool => (bool) $assignment->user?->is_active
                        && $assignment->user->can('project.view'));
                $assessorIds = $assignments
                    ->whereIn('assignment_role', ['assessor_primary', 'assessor_support'])
                    ->pluck('user_id');
                $primaryIds = $assignments
                    ->where('assignment_role', 'assessor_primary')
                    ->pluck('user_id');
                $reviewerIds = $assignments
                    ->where('assignment_role', 'reviewer')
                    ->filter(fn ($assignment): bool => ! $assessorIds->contains($assignment->user_id))
                    ->pluck('user_id');
                $primaryAssignees = $assignments
                    ->where('assignment_role', 'assessor_primary')
                    ->sortBy('user.name')
                    ->map(fn ($assignment): ?string => $assignment->user?->name)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                $independentReviewerAssignees = $assignments
                    ->where('assignment_role', 'reviewer')
                    ->filter(fn ($assignment): bool => ! $assessorIds->contains($assignment->user_id))
                    ->sortBy('user.name')
                    ->map(fn ($assignment): ?string => $assignment->user?->name)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                $mappingCount = $cycle->itemMappings
                    ->where('indicator_id', $assessment->indicator_id)
                    ->whereNull('revoked_at')
                    ->whereIn('mapping_role', ['primary', 'supporting'])
                    ->count();

                return [
                    'indicator_id' => $assessment->indicator_id,
                    'indicator_number' => $assessment->indicator->indicator_number,
                    'criterion_code' => $assessment->indicator->criterion_code,
                    'title' => $assessment->indicator->title,
                    'has_primary' => $primaryIds->isNotEmpty(),
                    'has_independent_reviewer' => $reviewerIds->isNotEmpty(),
                    'primary_assignees' => $primaryAssignees,
                    'independent_reviewer_assignees' => $independentReviewerAssignees,
                    'mapping_count' => $mappingCount,
                    // Mappings govern evidence links, not whether internal assessment can begin.
                    'ready' => $primaryIds->isNotEmpty() && $reviewerIds->isNotEmpty(),
                ];
            })->values();
        $criterionNames = $cycle->project->criteria->keyBy('code');
        $criteria = $indicators->groupBy('criterion_code')->map(function (Collection $rows, string $code) use ($criterionNames): array {
            return [
                'criterion_code' => $code,
                'criterion_name' => $criterionNames->get($code)?->name ?? __('led_setup.labels.criterion_with_code', ['code' => $code]),
                'total' => $rows->count(),
                'configured' => $rows->where('ready', true)->count(),
                'missing_primary' => $rows->where('has_primary', false)->count(),
                'missing_reviewer' => $rows->where('has_independent_reviewer', false)->count(),
                'missing_mapping' => $rows->where('mapping_count', 0)->count(),
                'ready' => $rows->every(fn (array $row): bool => $row['ready']),
                'indicators' => $rows->values()->all(),
            ];
        })->values();

        return [
            'ready' => $indicators->isNotEmpty() && $indicators->every(fn (array $indicator): bool => $indicator['ready']),
            'total' => $indicators->count(),
            'configured' => $indicators->where('ready', true)->count(),
            'missing_primary' => $indicators->where('has_primary', false)->count(),
            'missing_reviewer' => $indicators->where('has_independent_reviewer', false)->count(),
            'missing_mapping' => $indicators->where('mapping_count', 0)->count(),
            'indicators' => $indicators->all(),
            'criteria' => $criteria->all(),
        ];
    }

    private function event(AccreditationLedAssessmentCycle $cycle, User $actor, string $action, ?string $old, string $new, string $reason): void
    {
        AccreditationLedCycleEvent::create([
            'cycle_id' => $cycle->id, 'actor_id' => $actor->id, 'action' => $action,
            'old_state' => $old, 'new_state' => $new, 'reason' => $reason,
            'correlation_id' => (string) Str::uuid(), 'occurred_at' => now(),
        ]);
    }

    private function activity(AccreditationLedAssessmentCycle $cycle, User $actor, string $event, array $old, array $new): void
    {
        AccreditationActivityLog::create([
            'project_id' => $cycle->project_id, 'actor_id' => $actor->id, 'event' => $event,
            'subject_type' => $cycle::class, 'subject_id' => $cycle->id,
            'old_values' => $old ?: null, 'new_values' => $new ?: null,
            'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(), 'created_at' => now(),
        ]);
    }
}
