<?php

namespace App\Services;

use App\Models\AccreditationActivityLog;
use App\Models\AccreditationItem;
use App\Models\AccreditationLedAssessmentCycle;
use App\Models\AccreditationLedCycleEvent;
use App\Models\AccreditationLedIndicator;
use App\Models\AccreditationLedIndicatorItemMapping;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LedIndicatorItemMappingService
{
    private const ROLES = ['primary', 'supporting'];

    public function map(
        AccreditationLedAssessmentCycle $cycle,
        AccreditationLedIndicator $indicator,
        AccreditationItem $item,
        string $role,
        User $actor,
    ): AccreditationLedIndicatorItemMapping {
        $this->authorize($cycle, $actor);

        if (! in_array($role, self::ROLES, true)) {
            throw ValidationException::withMessages(['mapping' => __('led_setup.messages.mapping.role_invalid')]);
        }

        return DB::transaction(function () use ($cycle, $indicator, $item, $role, $actor): AccreditationLedIndicatorItemMapping {
            $target = AccreditationLedAssessmentCycle::query()->with('project')->lockForUpdate()->findOrFail($cycle->id);
            $source = AccreditationItem::query()->with('criterion')->findOrFail($item->id);

            if (! app(LedAssessmentAuthorizationService::class)->canManageCycle($actor, $target)
                || ! in_array($target->status, ['draft', 'active'], true)
                || $indicator->instrument_version_id !== $target->instrument_version_id
                || ! $indicator->is_assessable
                || ! $target->assessments()->where('indicator_id', $indicator->id)->exists()
                || $source->criterion->project_id !== $target->project_id
                || $source->criterion->code !== $indicator->criterion_code
                || ! $source->requirements()->exists()) {
                throw new AuthorizationException(__('led_setup.messages.mapping.scope_invalid'));
            }

            if (AccreditationLedIndicatorItemMapping::query()
                ->where('cycle_id', $target->id)
                ->where('indicator_id', $indicator->id)
                ->where('item_id', $source->id)
                ->whereNull('revoked_at')
                ->exists()) {
                throw ValidationException::withMessages(['mapping' => __('led_setup.messages.mapping.duplicate')]);
            }

            $mapping = AccreditationLedIndicatorItemMapping::create([
                'cycle_id' => $target->id,
                'project_id' => $target->project_id,
                'instrument_version_id' => $target->instrument_version_id,
                'indicator_id' => $indicator->id,
                'criterion_id' => $source->criterion_id,
                'item_id' => $source->id,
                'mapping_role' => $role,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);
            $this->event($target, $actor, 'mapping_created', [
                'mapping_id' => $mapping->id,
                'indicator_id' => $indicator->id,
                'item_id' => $source->id,
                'role' => $role,
            ], 'Mapping indikator ke item Phase 1 dibuat.');
            $this->activity($target, $actor, 'assessment_mapping.created', [], [
                'mapping_id' => $mapping->id,
                'indicator_id' => $indicator->id,
                'item_id' => $source->id,
                'role' => $role,
            ]);

            return $mapping->fresh(['indicator', 'item.criterion']);
        });
    }

    public function revoke(AccreditationLedIndicatorItemMapping $mapping, User $actor, string $reason): AccreditationLedIndicatorItemMapping
    {
        if (Auth::id() !== $actor->id || trim($reason) === '') {
            throw ValidationException::withMessages(['mappingRevocation' => __('led_setup.messages.mapping.revocation_actor_reason_required')]);
        }

        return DB::transaction(function () use ($mapping, $actor, $reason): AccreditationLedIndicatorItemMapping {
            $existing = AccreditationLedIndicatorItemMapping::query()->findOrFail($mapping->id);
            $cycle = AccreditationLedAssessmentCycle::query()->with('project')->lockForUpdate()->findOrFail($existing->cycle_id);
            $target = AccreditationLedIndicatorItemMapping::query()->lockForUpdate()->findOrFail($existing->id);
            $target->setRelation('cycle', $cycle);

            if (! app(LedAssessmentAuthorizationService::class)->canManageCycle($actor, $target->cycle)
                || ! in_array($target->cycle->status, ['draft', 'active'], true)
                || $target->revoked_at !== null) {
                throw new AuthorizationException(__('led_setup.messages.mapping.cannot_revoke'));
            }

            $target->update([
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revoke_reason' => trim($reason),
            ]);
            $this->event($target->cycle, $actor, 'mapping_revoked', [
                'mapping_id' => $target->id,
                'reason' => trim($reason),
            ], 'Mapping indikator ke item Phase 1 direvokasi.');
            $this->activity($target->cycle, $actor, 'assessment_mapping.revoked', [
                'mapping_id' => $target->id,
            ], [
                'revoked' => true,
                'reason' => trim($reason),
            ]);

            return $target->fresh();
        });
    }

    private function authorize(AccreditationLedAssessmentCycle $cycle, User $actor): void
    {
        if (Auth::id() !== $actor->id || ! app(LedAssessmentAuthorizationService::class)->canManageCycle($actor, $cycle)) {
            throw new AuthorizationException(__('led_setup.messages.mapping.manage_unauthorized'));
        }
    }

    private function event(AccreditationLedAssessmentCycle $cycle, User $actor, string $action, array $payload, string $reason): void
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

    private function activity(AccreditationLedAssessmentCycle $cycle, User $actor, string $event, array $old, array $new): void
    {
        AccreditationActivityLog::create([
            'project_id' => $cycle->project_id,
            'actor_id' => $actor->id,
            'event' => $event,
            'subject_type' => AccreditationLedIndicatorItemMapping::class,
            'subject_id' => $new['mapping_id'] ?? $old['mapping_id'] ?? null,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }
}
