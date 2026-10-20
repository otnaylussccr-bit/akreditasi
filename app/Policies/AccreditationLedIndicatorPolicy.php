<?php

namespace App\Policies;

use App\Models\AccreditationLedIndicator;
use App\Models\User;

class AccreditationLedIndicatorPolicy
{
    public function view(User $user, AccreditationLedIndicator $indicator): bool
    {
        return $user->can('project.view');
    }

    public function update(User $user, AccreditationLedIndicator $indicator): bool
    {
        return $indicator->instrumentVersion->status === 'draft'
            && $user->hasRole('Super Admin')
            && $user->can('project.manage');
    }
}
