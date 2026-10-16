<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AdministrativeAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit administrasi tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Audit administrasi tidak dapat dihapus.'));
    }
}
