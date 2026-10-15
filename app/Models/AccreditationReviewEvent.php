<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccreditationReviewEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AccreditationReviewSubmission::class, 'submission_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AccreditationReviewAttachment::class, 'review_event_id');
    }
}
