<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccreditationCriterion extends Model
{
    protected $table = 'accreditation_criteria';

    protected $guarded = [];

    public function project(): BelongsTo
    {
        return $this->belongsTo(AccreditationProject::class, 'project_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AccreditationItem::class, 'criterion_id')->orderBy('position');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AccreditationCriterionAssignment::class, 'criterion_id');
    }
}
