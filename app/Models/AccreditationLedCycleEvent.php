<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AccreditationLedCycleEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'cycle_id', 'actor_id', 'action', 'old_state', 'new_state',
        'event_payload_json', 'reason', 'correlation_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['event_payload_json' => 'array', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('LED cycle events are append-only.'));
        static::deleting(fn (): never => throw new LogicException('LED cycle events are append-only.'));
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AccreditationLedAssessmentCycle::class, 'cycle_id');
    }
}
