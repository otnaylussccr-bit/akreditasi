<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class AccreditationProject extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'final_deadline' => 'date',
            'structure_locked_at' => 'datetime',
        ];
    }

    public function studyProgram(): BelongsTo
    {
        return $this->belongsTo(StudyProgram::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(AccreditationInstrument::class, 'instrument_id');
    }

    public function assessmentCycles(): HasMany
    {
        return $this->hasMany(AccreditationLedAssessmentCycle::class, 'project_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(AccreditationCriterion::class, 'project_id')->orderBy('position');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(AccreditationProjectMember::class, 'project_id');
    }

    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(
            AccreditationItem::class,
            AccreditationCriterion::class,
            'project_id',
            'criterion_id',
        );
    }
}
