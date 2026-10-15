<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccreditationReviewSubmission extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(AccreditationDocumentRequirement::class, 'requirement_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(
            AccreditationDocument::class,
            'accreditation_submission_documents',
            'submission_id',
            'document_id',
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(AccreditationReviewEvent::class, 'submission_id');
    }
}
