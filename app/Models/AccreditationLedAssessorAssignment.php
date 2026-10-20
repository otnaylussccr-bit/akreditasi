<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccreditationLedAssessorAssignment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'cycle_id', 'instrument_version_id', 'indicator_id', 'user_id',
        'assignment_role', 'assigned_by', 'assigned_at', 'revoked_by',
        'revoked_at', 'revocation_reason',
    ];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AccreditationLedAssessmentCycle::class, 'cycle_id');
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(AccreditationLedIndicator::class, 'indicator_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
