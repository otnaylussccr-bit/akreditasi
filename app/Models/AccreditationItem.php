<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccreditationItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_deadline' => 'date',
            'target_deadline' => 'date',
        ];
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(AccreditationCriterion::class, 'criterion_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(AccreditationDocumentRequirement::class, 'item_id')->orderBy('number');
    }
}
