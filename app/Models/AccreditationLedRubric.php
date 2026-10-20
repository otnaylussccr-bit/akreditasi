<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccreditationLedRubric extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'indicator_id', 'category_code', 'category_label', 'internal_score',
        'description', 'position', 'page_start', 'page_end',
    ];

    protected function casts(): array
    {
        return ['internal_score' => 'decimal:2'];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(AccreditationLedIndicator::class, 'indicator_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AccreditationLedAssessmentRevision::class, 'rubric_id');
    }
}
