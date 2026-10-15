<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccreditationCriterionAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(AccreditationCriterion::class, 'criterion_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
