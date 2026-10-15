<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccreditationProjectMember extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AccreditationProject::class, 'project_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
