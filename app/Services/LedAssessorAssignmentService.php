<?php

namespace App\Services;

use App\Models\AccreditationLedAssessmentCycle;
use App\Models\AccreditationLedAssessorAssignment;
use App\Models\AccreditationLedCycleEvent;
use App\Models\AccreditationLedIndicator;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LedAssessorAssignmentService
{
    private const ROLES = ['assessor_primary', 'assessor_support', 'reviewer'];

    public function assign(
        AccreditationLedAssessmentCycle $cycle,
        AccreditationLedIndicator $indicator,
        User $user,
        string $role,
        User $actor,
        string $reason,
    ): AccreditationLedAssessorAssignment {
        if (Auth::id() !== $actor->id || ! app(LedAssessmentAuthorizationService::class)->canManageAssignments($actor, $cycle)) {
            throw new AuthorizationException(__('led_setup.messages.assignment.manage_unauthorized'));
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['assignment' => __('led_setup.messages.assignment.reason_required')]);
        }

        if (! in_array($role, self::ROLES, true)) {
            throw ValidationException::withMessages(['assignment' => __('led_setup.messages.assignment.role_or_user_invalid')]);
        }

        return DB::transaction(function () use ($cycle, $indicator, $user, $role, $actor, $reason): AccreditationLedAssessorAssignment {
            $target = AccreditationLedAssessmentCycle::query()->with('project')->lockForUpdate()->findOrFail($cycle->id);
            $users = User::query()
                ->with('roles.permissions', 'permissions')
                ->whereKey(array_values(array_unique([$actor->id, $user->id])))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $targetActor = $users->get($actor->id);
            $targetUser = $users->get($user->id);
            $targetIndicator = AccreditationLedIndicator::query()->findOrFail($indicator->id);

            if (! $targetActor
                || Auth::id() !== $targetActor->id
                || ! app(LedAssessmentAuthorizationService::class)->canManageAssignments($targetActor, $target)
                || ! in_array($target->status, ['draft', 'active'], true)
                || $targetIndicator->instrument_version_id !== $target->instrument_version_id
                || ! $targetIndicator->is_assessable
                || ! $target->assessments()->where('indicator_id', $targetIndicator->id)->exists()) {
                throw new AuthorizationException(__('led_setup.messages.assignment.cycle_not_mutable'));
            }

            if (! $targetUser || ! $targetUser->is_active || ! $targetUser->can('project.view')) {
                throw ValidationException::withMessages(['assignment' => __('led_setup.messages.assignment.role_or_user_invalid')]);
            }

            $activeAssignments = AccreditationLedAssessorAssignment::query()
                ->where('cycle_id', $target->id)->where('indicator_id', $targetIndicator->id)
                ->whereNull('revoked_at');
            $duplicate = (clone $activeAssignments)
                ->where('user_id', $targetUser->id)->where('assignment_role', $role)->whereNull('revoked_at')->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['assignment' => __('led_setup.messages.assignment.duplicate')]);
            }

            if ($role === 'assessor_primary' && (clone $activeAssignments)->where('assignment_role', 'assessor_primary')->exists()) {
                throw ValidationException::withMessages(['assignment' => __('led_setup.messages.assignment.primary_exists')]);
            }

            $conflictingRoles = $role === 'reviewer'
                ? ['assessor_primary', 'assessor_support']
                : ['reviewer'];

            if ((clone $activeAssignments)->where('user_id', $targetUser->id)->whereIn('assignment_role', $conflictingRoles)->exists()) {
                throw ValidationException::withMessages(['assignment' => __('led_setup.messages.assignment.reviewer_must_be_independent')]);
            }

            $assignment = AccreditationLedAssessorAssignment::create([
                'cycle_id' => $target->id, 'instrument_version_id' => $target->instrument_version_id,
                'indicator_id' => $targetIndicator->id, 'user_id' => $targetUser->id, 'assignment_role' => $role,
                'assigned_by' => $targetActor->id, 'assigned_at' => now(),
            ]);
            $this->event($target, $targetActor, 'assignment_created', [
                'assignment_id' => $assignment->id,
                'role' => $role,
                'user_id' => $targetUser->id,
                'reason' => trim($reason),
            ], trim($reason));

            return $assignment;
        });
    }

    public function revoke(AccreditationLedAssessorAssignment $assignment, User $actor, string $reason): AccreditationLedAssessorAssignment
    {
        if (Auth::id() !== $actor->id || trim($reason) === '') {
            throw ValidationException::withMessages(['revocation' => __('led_setup.messages.assignment.revocation_actor_reason_required')]);
        }

        return DB::transaction(function () use ($assignment, $actor, $reason): AccreditationLedAssessorAssignment {
            $existing = AccreditationLedAssessorAssignment::query()->findOrFail($assignment->id);
            $cycle = AccreditationLedAssessmentCycle::query()->with('project')->lockForUpdate()->findOrFail($existing->cycle_id);
            $target = AccreditationLedAssessorAssignment::query()->lockForUpdate()->findOrFail($existing->id);
            $target->setRelation('cycle', $cycle);

            if (! app(LedAssessmentAuthorizationService::class)->canManageAssignments($actor, $target->cycle)
                || ! in_array($target->cycle->status, ['draft', 'active'], true)
                || $target->revoked_at !== null) {
                throw new AuthorizationException(__('led_setup.messages.assignment.cannot_revoke'));
            }

            $target->update(['revoked_by' => $actor->id, 'revoked_at' => now(), 'revocation_reason' => trim($reason)]);
            $this->event($target->cycle, $actor, 'assignment_revoked', ['assignment_id' => $target->id, 'reason' => trim($reason)], 'Penugasan LED direvokasi.');

            return $target->fresh();
        });
    }

    public function replace(
        AccreditationLedAssessorAssignment $assignment,
        User $replacement,
        User $actor,
        string $reason,
    ): AccreditationLedAssessorAssignment {
        if (Auth::id() !== $actor->id || trim($reason) === '') {
            throw ValidationException::withMessages(['replacement' => __('led_setup.messages.assignment.replacement_actor_reason_required')]);
        }

        return DB::transaction(function () use ($assignment, $replacement, $actor, $reason): AccreditationLedAssessorAssignment {
            $existing = AccreditationLedAssessorAssignment::query()->findOrFail($assignment->id);
            $cycle = AccreditationLedAssessmentCycle::query()->with('project')->lockForUpdate()->findOrFail($existing->cycle_id);
            $target = AccreditationLedAssessorAssignment::query()->lockForUpdate()->findOrFail($existing->id);
            $replacementUser = User::query()->with('roles.permissions', 'permissions')->lockForUpdate()->findOrFail($replacement->id);
            $target->setRelation('cycle', $cycle);

            if (! app(LedAssessmentAuthorizationService::class)->canManageAssignments($actor, $cycle)
                || ! in_array($cycle->status, ['draft', 'active'], true)
                || $target->revoked_at !== null) {
                throw new AuthorizationException(__('led_setup.messages.assignment.cannot_replace'));
            }

            if (! $replacementUser->is_active || ! $replacementUser->can('project.view') || $replacementUser->id === $target->user_id) {
                throw ValidationException::withMessages(['replacement' => __('led_setup.messages.assignment.replacement_invalid')]);
            }

            $activeAssignments = AccreditationLedAssessorAssignment::query()
                ->where('cycle_id', $target->cycle_id)
                ->where('indicator_id', $target->indicator_id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get();
            $duplicate = $activeAssignments->contains(fn (AccreditationLedAssessorAssignment $active): bool => $active->user_id === $replacementUser->id
                && $active->assignment_role === $target->assignment_role);
            $conflictingRoles = $target->assignment_role === 'reviewer'
                ? ['assessor_primary', 'assessor_support']
                : ['reviewer'];
            $conflict = $activeAssignments->contains(fn (AccreditationLedAssessorAssignment $active): bool => $active->user_id === $replacementUser->id
                && in_array($active->assignment_role, $conflictingRoles, true));

            if ($duplicate || $conflict) {
                throw ValidationException::withMessages(['replacement' => __('led_setup.messages.assignment.replacement_conflict')]);
            }

            $trimmedReason = trim($reason);
            $target->update([
                'revoked_by' => $actor->id,
                'revoked_at' => now(),
                'revocation_reason' => $trimmedReason,
            ]);
            $newAssignment = AccreditationLedAssessorAssignment::create([
                'cycle_id' => $target->cycle_id,
                'instrument_version_id' => $target->instrument_version_id,
                'indicator_id' => $target->indicator_id,
                'user_id' => $replacementUser->id,
                'assignment_role' => $target->assignment_role,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);
            $this->event($cycle, $actor, 'assignment_replaced', [
                'replaced_assignment_id' => $target->id,
                'replacement_assignment_id' => $newAssignment->id,
                'old_user_id' => $target->user_id,
                'new_user_id' => $replacementUser->id,
                'role' => $target->assignment_role,
                'reason' => $trimmedReason,
            ], $trimmedReason);

            return $newAssignment->fresh(['user', 'cycle']);
        });
    }

    private function event(AccreditationLedAssessmentCycle $cycle, User $actor, string $action, array $payload, string $reason): void
    {
        AccreditationLedCycleEvent::create([
            'cycle_id' => $cycle->id, 'actor_id' => $actor->id, 'action' => $action,
            'event_payload_json' => $payload, 'reason' => $reason,
            'correlation_id' => (string) Str::uuid(), 'occurred_at' => now(),
        ]);
    }
}
