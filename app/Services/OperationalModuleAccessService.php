<?php

namespace App\Services;

use App\Models\User;

class OperationalModuleAccessService
{
    public function allows(User $user, string $routeName): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasExactRoles('Viewer')) {
            return true;
        }

        return $routeName === 'led-assessment.index'
            && $user->ledAssessorAssignments()->whereNull('revoked_at')->exists();
    }
}
