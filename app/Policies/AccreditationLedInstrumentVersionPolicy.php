<?php

namespace App\Policies;

use App\Models\AccreditationLedInstrumentVersion;
use App\Models\User;

class AccreditationLedInstrumentVersionPolicy
{
    public function view(User $user, AccreditationLedInstrumentVersion $version): bool
    {
        return $user->can('project.view');
    }

    public function update(User $user, AccreditationLedInstrumentVersion $version): bool
    {
        return $version->status === 'draft' && $user->hasRole('Super Admin') && $user->can('project.manage');
    }

    public function publish(User $user, AccreditationLedInstrumentVersion $version): bool
    {
        return $this->update($user, $version);
    }
}
