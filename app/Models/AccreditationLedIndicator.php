<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccreditationLedIndicator extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'instrument_version_id', 'indicator_number', 'criterion_code', 'title',
        'description', 'position', 'page_start', 'page_end', 'is_assessable',
    ];

    protected function casts(): array
    {
        return ['is_assessable' => 'boolean'];
    }

    public function instrumentVersion(): BelongsTo
    {
        return $this->belongsTo(AccreditationLedInstrumentVersion::class, 'instrument_version_id');
    }

    public function rubrics(): HasMany
    {
        return $this->hasMany(AccreditationLedRubric::class, 'indicator_id')->orderBy('position');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(AccreditationLedAssessment::class, 'indicator_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AccreditationLedAssessorAssignment::class, 'indicator_id');
    }

    public function itemMappings(): HasMany
    {
        return $this->hasMany(AccreditationLedIndicatorItemMapping::class, 'indicator_id');
    }
}
