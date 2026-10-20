<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccreditationLedInstrumentVersion extends Model
{
    protected $fillable = [
        'instrument_id', 'version_code', 'assessment_pathway', 'education_level',
        'source_title', 'source_reference', 'source_effective_date', 'source_filename',
        'source_disk', 'source_storage_path', 'source_pdf_sha256', 'rubric_manifest_sha256',
        'status', 'published_by', 'published_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'source_effective_date' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(AccreditationInstrument::class, 'instrument_id');
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(AccreditationLedIndicator::class, 'instrument_version_id')->orderBy('position');
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(AccreditationLedAssessmentCycle::class, 'instrument_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
