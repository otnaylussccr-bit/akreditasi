<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AccreditationLedAssessmentRevision extends Model
{
    protected $fillable = [
        'assessment_id', 'project_id', 'indicator_id', 'revision_number', 'rubric_id',
        'justification', 'gap_analysis', 'recommendation', 'status', 'created_by',
        'submitted_by', 'submitted_at', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Assessment revisions are immutable history and cannot be deleted.'));
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(AccreditationLedAssessment::class, 'assessment_id');
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(AccreditationLedRubric::class, 'rubric_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AccreditationLedReviewEvent::class, 'revision_id')->orderBy('occurred_at');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(AccreditationLedAssessmentEvidence::class, 'revision_id');
    }
}
