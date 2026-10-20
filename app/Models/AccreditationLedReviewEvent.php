<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AccreditationLedReviewEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'revision_id', 'actor_id', 'action', 'from_state', 'to_state',
        'decision_note', 'payload_json', 'correlation_id', 'ip_address',
        'user_agent', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['payload_json' => 'array', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('LED review events are append-only.'));
        static::deleting(fn (): never => throw new LogicException('LED review events are append-only.'));
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(AccreditationLedAssessmentRevision::class, 'revision_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
