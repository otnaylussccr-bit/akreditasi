<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccreditationLedAssessmentCycle extends Model
{
    protected $fillable = [
        'project_id', 'instrument_id', 'instrument_version_id', 'cycle_number',
        'cycle_name', 'status', 'organization_snapshot_json', 'organization_snapshot_sha256',
        'activated_by', 'activated_at', 'closed_by', 'closed_at', 'finalized_by',
        'finalized_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'organization_snapshot_json' => 'array',
            'activated_at' => 'datetime',
            'closed_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AccreditationProject::class, 'project_id');
    }

    public function instrumentVersion(): BelongsTo
    {
        return $this->belongsTo(AccreditationLedInstrumentVersion::class, 'instrument_version_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(AccreditationLedAssessment::class, 'cycle_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AccreditationLedAssessorAssignment::class, 'cycle_id');
    }

    public function itemMappings(): HasMany
    {
        return $this->hasMany(AccreditationLedIndicatorItemMapping::class, 'cycle_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AccreditationLedCycleEvent::class, 'cycle_id')->orderBy('occurred_at');
    }
}
