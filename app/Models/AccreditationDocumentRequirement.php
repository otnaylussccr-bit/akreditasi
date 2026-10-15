<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccreditationDocumentRequirement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_required' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AccreditationItem::class, 'item_id');
    }

    public function reviewSubmissions(): HasMany
    {
        return $this->hasMany(AccreditationReviewSubmission::class, 'requirement_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AccreditationDocument::class, 'requirement_id');
    }
}
