<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccreditationLedAssessment extends Model
{
    protected $fillable = ['cycle_id', 'project_id', 'instrument_version_id', 'indicator_id'];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AccreditationLedAssessmentCycle::class, 'cycle_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AccreditationProject::class, 'project_id');
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(AccreditationLedIndicator::class, 'indicator_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AccreditationLedAssessmentRevision::class, 'assessment_id')->orderByDesc('revision_number');
    }

    public function currentWorkingRevision(): BelongsTo
    {
        return $this->belongsTo(AccreditationLedAssessmentRevision::class, 'current_working_revision_id');
    }

    public function currentApprovedRevision(): BelongsTo
    {
        return $this->belongsTo(AccreditationLedAssessmentRevision::class, 'current_approved_revision_id');
    }
}
