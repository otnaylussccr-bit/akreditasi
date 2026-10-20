<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccreditationLedIndicatorItemMapping extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'cycle_id', 'project_id', 'instrument_version_id', 'indicator_id',
        'criterion_id', 'item_id', 'mapping_role', 'created_by', 'created_at',
        'revoked_at', 'revoked_by', 'revoke_reason',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(AccreditationLedIndicator::class, 'indicator_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AccreditationLedAssessmentCycle::class, 'cycle_id');
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(AccreditationCriterion::class, 'criterion_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AccreditationItem::class, 'item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
