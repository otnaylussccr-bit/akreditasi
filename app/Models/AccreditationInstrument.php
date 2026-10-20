<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccreditationInstrument extends Model
{
    protected $guarded = [];

    public function versions(): HasMany
    {
        return $this->hasMany(AccreditationLedInstrumentVersion::class, 'instrument_id');
    }
}
