<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AccreditationReviewAttachment extends Model
{
    protected $fillable = [
        'review_event_id',
        'storage_uuid',
        'storage_path',
        'original_filename',
        'mime_type',
        'extension',
        'file_size',
        'sha256',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Lampiran koreksi bersifat immutable.'));
        static::deleting(fn () => throw new LogicException('Lampiran koreksi bersifat immutable.'));
    }

    public function reviewEvent(): BelongsTo
    {
        return $this->belongsTo(AccreditationReviewEvent::class, 'review_event_id');
    }
}
