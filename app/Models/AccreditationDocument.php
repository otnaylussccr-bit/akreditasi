<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AccreditationDocument extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'uploaded_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(AccreditationDocumentRequirement::class, 'requirement_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewSubmissions(): BelongsToMany
    {
        return $this->belongsToMany(
            AccreditationReviewSubmission::class,
            'accreditation_submission_documents',
            'document_id',
            'submission_id',
        );
    }
}
