<?php

namespace App\Services;

use App\Models\AccreditationItem;
use App\Models\AccreditationLedAssessmentCycle;
use App\Models\AccreditationLedCycleEvent;
use App\Models\AccreditationLedIndicator;
use App\Models\User;
use App\Support\LocalizedLabel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LedOperationalSetupBulkService
{
    private const ASSIGNMENT_ROLES = ['assessor_primary', 'assessor_support', 'reviewer'];

    public function __construct(
        private readonly LedAssessmentAuthorizationService $ledAuthorization,
        private readonly LedAssessorAssignmentService $assignments,
        private readonly LedIndicatorItemMappingService $mappings,
    ) {}

    public function previewAssignments(AccreditationLedAssessmentCycle $cycle, array $indicatorIds, array $userIds, string $reason, User $actor): array
    {
        return $this->assignmentPlan($cycle, $indicatorIds, $userIds, $reason, $actor);
    }

    public function applyAssignments(
        AccreditationLedAssessmentCycle $cycle,
        array $indicatorIds,
        array $userIds,
        string $reason,
        string $expectedFingerprint,
        User $actor,
    ): array {
        return DB::transaction(function () use ($cycle, $indicatorIds, $userIds, $reason, $expectedFingerprint, $actor): array {
            $locked = AccreditationLedAssessmentCycle::query()->with('project')->lockForUpdate()->findOrFail($cycle->id);
            $plan = $this->assignmentPlan($locked, $indicatorIds, $userIds, $reason, $actor);
            $this->assertApplicablePlan($plan, $expectedFingerprint, 'assignment');

            $created = [];
            foreach ($plan['rows'] as $row) {
                if ($row['action'] !== 'create') {
                    continue;
                }

                $assignment = $this->assignments->assign(
                    $locked,
                    AccreditationLedIndicator::findOrFail($row['indicator_id']),
                    User::query()->with('roles')->findOrFail($row['user_id']),
                    $row['role'],
                    $actor,
                    $reason,
                );
                $created[] = $assignment->id;
            }

            if ($created !== []) {
                $this->recordBatchEvent($locked, $actor, 'bulk_assignments_applied', [
                    'fingerprint' => $plan['fingerprint'],
                    'assignment_ids' => $created,
                    'created_count' => count($created),
                    'skipped_count' => $plan['skipped_count'],
                    'indicator_ids' => $plan['indicator_ids'],
                ], trim($reason));
            }

            return ['created_count' => count($created), 'skipped_count' => $plan['skipped_count']];
        });
    }

    public function previewMappings(
        AccreditationLedAssessmentCycle $cycle,
        array $indicatorIds,
        array $itemIds,
        string $role,
        User $actor,
    ): array {
        return $this->mappingPlan($cycle, $indicatorIds, $itemIds, $role, $actor);
    }

    public function applyMappings(
        AccreditationLedAssessmentCycle $cycle,
        array $indicatorIds,
        array $itemIds,
        string $role,
        string $expectedFingerprint,
        User $actor,
    ): array {
        return DB::transaction(function () use ($cycle, $indicatorIds, $itemIds, $role, $expectedFingerprint, $actor): array {
            $locked = AccreditationLedAssessmentCycle::query()->with('project')->lockForUpdate()->findOrFail($cycle->id);
            $plan = $this->mappingPlan($locked, $indicatorIds, $itemIds, $role, $actor);
            $this->assertApplicablePlan($plan, $expectedFingerprint, 'mapping');

            $created = [];
            foreach ($plan['rows'] as $row) {
                if ($row['action'] !== 'create') {
                    continue;
                }

                $mapping = $this->mappings->map(
                    $locked,
                    AccreditationLedIndicator::findOrFail($row['indicator_id']),
                    AccreditationItem::query()->with('criterion')->findOrFail($row['item_id']),
                    $row['role'],
                    $actor,
                );
                $created[] = $mapping->id;
            }

            if ($created !== []) {
                $this->recordBatchEvent($locked, $actor, 'bulk_mappings_applied', [
                    'fingerprint' => $plan['fingerprint'],
                    'mapping_ids' => $created,
                    'created_count' => count($created),
                    'skipped_count' => $plan['skipped_count'],
                    'indicator_ids' => $plan['indicator_ids'],
                    'item_ids' => $plan['item_ids'],
                ], 'Bulk mapping indikator ke item Phase 1 diterapkan secara atomik.');
            }

            return ['created_count' => count($created), 'skipped_count' => $plan['skipped_count']];
        });
    }

    private function assignmentPlan(AccreditationLedAssessmentCycle $cycle, array $indicatorIds, array $userIds, string $reason, User $actor): array
    {
        $this->authorize($cycle, $actor, true);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['bulk_assignment_reason' => __('led_setup.messages.bulk.assignment_reason_required')]);
        }
        $indicatorIds = $this->normalizedIds($indicatorIds, 'bulk_assignment_indicators');
        $selectedUsers = collect(self::ASSIGNMENT_ROLES)
            ->mapWithKeys(fn (string $role): array => [$role => (int) ($userIds[$role] ?? 0)])
            ->filter()
            ->all();

        if ($selectedUsers === []) {
            throw ValidationException::withMessages(['bulk_assignment_users' => __('led_setup.messages.bulk.assignment_user_required')]);
        }

        $target = $this->loadedCycle($cycle);
        $indicators = $this->selectedIndicators($target, $indicatorIds);
        $users = User::query()->with('roles')->whereKey(array_values($selectedUsers))->get()->keyBy('id');
        if ($users->count() !== count(array_unique($selectedUsers))) {
            throw ValidationException::withMessages(['bulk_assignment_users' => __('led_setup.messages.bulk.assignment_user_not_found')]);
        }

        $rows = collect();
        $activeAssignments = $target->assignments->whereNull('revoked_at');

        foreach ($indicators as $indicator) {
            foreach ($selectedUsers as $role => $userId) {
                $user = $users->get($userId);
                $this->validateEligibleUser($user);
                $indicatorAssignments = $activeAssignments->where('indicator_id', $indicator->id);
                $exact = $indicatorAssignments->first(fn ($assignment): bool => $assignment->user_id === $user->id && $assignment->assignment_role === $role);
                $conflict = null;

                if (! $exact && $role === 'assessor_primary' && $indicatorAssignments->where('assignment_role', 'assessor_primary')->isNotEmpty()) {
                    $conflict = __('led_setup.messages.bulk.primary_exists');
                }

                $oppositeRoles = $role === 'reviewer' ? ['assessor_primary', 'assessor_support'] : ['reviewer'];
                if (! $exact && $indicatorAssignments->where('user_id', $user->id)->whereIn('assignment_role', $oppositeRoles)->isNotEmpty()) {
                    $conflict = __('led_setup.messages.bulk.reviewer_must_be_independent');
                }

                $proposedOpposite = collect($selectedUsers)
                    ->filter(fn (int $candidateId, string $candidateRole): bool => in_array($candidateRole, $oppositeRoles, true) && $candidateId === $user->id)
                    ->isNotEmpty();
                if (! $exact && $proposedOpposite) {
                    $conflict = __('led_setup.messages.bulk.same_user_conflict');
                }

                $rows->push([
                    'indicator_id' => $indicator->id,
                    'indicator_number' => $indicator->indicator_number,
                    'criterion_code' => $indicator->criterion_code,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'role' => $role,
                    'role_label' => LocalizedLabel::assignmentRole($role),
                    'action' => $exact ? 'skip' : ($conflict ? 'conflict' : 'create'),
                    'message' => $exact ? __('led_setup.messages.bulk.assignment_exists') : $conflict,
                ]);
            }
        }

        $request = ['type' => 'assignment', 'indicator_ids' => $indicatorIds, 'users' => $selectedUsers, 'reason' => trim($reason)];

        return $this->finalizePlan($target, $rows, $request, [
            'indicator_ids' => $indicatorIds,
            'active_assignments' => $this->assignmentState($target->assignments),
            'user_eligibility' => $this->userEligibilityState($users),
        ]);
    }

    private function mappingPlan(
        AccreditationLedAssessmentCycle $cycle,
        array $indicatorIds,
        array $itemIds,
        string $role,
        User $actor,
    ): array {
        $this->authorize($cycle, $actor);
        if (! in_array($role, ['primary', 'supporting'], true)) {
            throw ValidationException::withMessages(['bulk_mapping_role' => __('led_setup.messages.bulk.mapping_role_invalid')]);
        }

        $indicatorIds = $this->normalizedIds($indicatorIds, 'bulk_mapping_indicators');
        $itemIds = $this->normalizedIds($itemIds, 'bulk_mapping_items');
        if (count($indicatorIds) * count($itemIds) > 200) {
            throw ValidationException::withMessages(['bulk_mapping_items' => __('led_setup.messages.bulk.mapping_limit')]);
        }

        $target = $this->loadedCycle($cycle);
        $indicators = $this->selectedIndicators($target, $indicatorIds);
        $items = AccreditationItem::query()
            ->with('criterion')
            ->whereKey($itemIds)
            ->whereHas('criterion', fn ($query) => $query->where('project_id', $target->project_id))
            ->whereHas('requirements')
            ->get()
            ->keyBy('id');
        if ($items->count() !== count($itemIds)) {
            throw ValidationException::withMessages(['bulk_mapping_items' => __('led_setup.messages.bulk.mapping_item_invalid')]);
        }

        $rows = collect();
        $activeMappings = $target->itemMappings->whereNull('revoked_at');
        foreach ($indicators as $indicator) {
            foreach ($itemIds as $itemId) {
                $item = $items->get($itemId);
                if ($item->criterion->code !== $indicator->criterion_code) {
                    throw ValidationException::withMessages([
                        'bulk_mapping_items' => __('led_setup.messages.bulk.criterion_mismatch', [
                            'item' => $item->criterion->code.'.'.$item->number,
                            'criterion' => $indicator->criterion_code,
                            'indicator' => $indicator->indicator_number,
                        ]),
                    ]);
                }

                $existing = $activeMappings
                    ->where('indicator_id', $indicator->id)
                    ->firstWhere('item_id', $item->id);
                $sameRole = $existing?->mapping_role === $role;
                $rows->push([
                    'indicator_id' => $indicator->id,
                    'indicator_number' => $indicator->indicator_number,
                    'criterion_code' => $indicator->criterion_code,
                    'item_id' => $item->id,
                    'item_reference' => $item->criterion->code.'.'.$item->number,
                    'item_title' => $item->title,
                    'role' => $role,
                    'role_label' => LocalizedLabel::mappingRole($role),
                    'action' => $sameRole ? 'skip' : ($existing ? 'conflict' : 'create'),
                    'message' => $sameRole
                        ? __('led_setup.messages.bulk.mapping_exists')
                        : ($existing ? __('led_setup.messages.bulk.mapping_role_conflict') : null),
                ]);
            }
        }

        $request = ['type' => 'mapping', 'indicator_ids' => $indicatorIds, 'item_ids' => $itemIds, 'role' => $role];

        return $this->finalizePlan($target, $rows, $request, [
            'indicator_ids' => $indicatorIds,
            'item_ids' => $itemIds,
            'active_mappings' => $this->mappingState($target->itemMappings),
        ]);
    }

    private function loadedCycle(AccreditationLedAssessmentCycle $cycle): AccreditationLedAssessmentCycle
    {
        return AccreditationLedAssessmentCycle::query()
            ->with(['project', 'assessments.indicator', 'assignments.user.roles', 'itemMappings'])
            ->findOrFail($cycle->id);
    }

    private function selectedIndicators(AccreditationLedAssessmentCycle $cycle, array $indicatorIds): Collection
    {
        $indicators = $cycle->assessments
            ->whereIn('indicator_id', $indicatorIds)
            ->map->indicator
            ->filter(fn (AccreditationLedIndicator $indicator): bool => $indicator->is_assessable && $indicator->instrument_version_id === $cycle->instrument_version_id)
            ->sortBy('position')
            ->values();

        if ($indicators->count() !== count($indicatorIds)) {
            throw new AuthorizationException(__('led_setup.messages.bulk.indicator_scope_invalid'));
        }

        return $indicators;
    }

    private function validateEligibleUser(User $user): void
    {
        if (! $user->is_active || ! $user->can('project.view')) {
            throw ValidationException::withMessages([
                'bulk_assignment_users' => __('led_setup.messages.bulk.user_ineligible', ['user' => $user->name]),
            ]);
        }
    }

    private function normalizedIds(array $ids, string $key): array
    {
        $raw = array_map('intval', $ids);
        $normalized = array_values(array_unique(array_filter($raw)));
        sort($normalized);
        if ($normalized === []) {
            throw ValidationException::withMessages([$key => __('led_setup.messages.bulk.selection_required')]);
        }
        if (count($raw) !== count($normalized)) {
            throw ValidationException::withMessages([$key => __('led_setup.messages.bulk.selection_invalid')]);
        }

        return $normalized;
    }

    private function finalizePlan(AccreditationLedAssessmentCycle $cycle, Collection $rows, array $request, array $state): array
    {
        $fingerprint = hash_hmac('sha256', json_encode([
            'cycle' => [$cycle->id, $cycle->project_id, $cycle->instrument_version_id, $cycle->status],
            'request' => $request,
            'state' => $state,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return array_merge($request, [
            'rows' => $rows->all(),
            'create_count' => $rows->where('action', 'create')->count(),
            'skipped_count' => $rows->where('action', 'skip')->count(),
            'conflict_count' => $rows->where('action', 'conflict')->count(),
            'can_apply' => $rows->isNotEmpty() && ! $rows->contains('action', 'conflict'),
            'fingerprint' => $fingerprint,
        ]);
    }

    private function assignmentState(Collection $assignments): array
    {
        return $assignments->sortBy('id')->map(fn ($assignment): array => [
            $assignment->id,
            $assignment->indicator_id,
            $assignment->user_id,
            $assignment->assignment_role,
            $assignment->revoked_at?->toISOString(),
            (bool) $assignment->user?->is_active,
            (bool) $assignment->user?->can('project.view'),
        ])->values()->all();
    }

    private function mappingState(Collection $mappings): array
    {
        return $mappings->sortBy('id')->map(fn ($mapping): array => [
            $mapping->id,
            $mapping->indicator_id,
            $mapping->item_id,
            $mapping->mapping_role,
            $mapping->revoked_at?->toISOString(),
        ])->values()->all();
    }

    private function userEligibilityState(Collection $users): array
    {
        return $users->sortKeys()->map(fn (User $user): array => [
            'id' => $user->id,
            'active' => (bool) $user->is_active,
            'project_view' => $user->can('project.view'),
        ])->values()->all();
    }

    private function assertApplicablePlan(array $plan, string $expectedFingerprint, string $key): void
    {
        if (! hash_equals($plan['fingerprint'], $expectedFingerprint)) {
            throw ValidationException::withMessages([$key => __('led_setup.messages.bulk.preview_stale')]);
        }
        if (! $plan['can_apply']) {
            throw ValidationException::withMessages([$key => __('led_setup.messages.bulk.preview_has_conflicts')]);
        }
    }

    private function authorize(AccreditationLedAssessmentCycle $cycle, User $actor, bool $assignment = false): void
    {
        $canManage = $assignment
            ? $this->ledAuthorization->canManageAssignments($actor, $cycle)
            : $this->ledAuthorization->canManageCycle($actor, $cycle);

        if (Auth::id() !== $actor->id || ! $canManage
            || ! in_array($cycle->status, ['draft', 'active'], true)) {
            throw new AuthorizationException(__('led_setup.messages.bulk.unauthorized'));
        }
    }

    private function recordBatchEvent(AccreditationLedAssessmentCycle $cycle, User $actor, string $action, array $payload, string $reason): void
    {
        AccreditationLedCycleEvent::create([
            'cycle_id' => $cycle->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'event_payload_json' => $payload,
            'reason' => $reason,
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ]);
    }
}
