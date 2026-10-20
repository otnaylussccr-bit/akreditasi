<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AccreditationLedAssessmentEvidence extends Model
{
    public $timestamps = false;

    protected $table = 'accreditation_led_assessment_evidence';

    protected $fillable = [
        'revision_id', 'document_id', 'verification_submission_id',
        'document_revision_number', 'document_sha256', 'evidence_role',
        'note', 'linked_by', 'linked_at',
    ];

    protected function casts(): array
    {
        return ['linked_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('LED assessment evidence is an immutable provenance snapshot.'));
        static::deleting(fn (): never => throw new LogicException('LED assessment evidence is an immutable provenance snapshot.'));
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(AccreditationLedAssessmentRevision::class, 'revision_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(AccreditationDocument::class, 'document_id');
    }

    public function verificationSubmission(): BelongsTo
    {
        return $this->belongsTo(AccreditationReviewSubmission::class, 'verification_submission_id');
    }
}
