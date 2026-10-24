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

        return in_array($routeName, ['led-assessment.index', 'led-assessment.relationship-explorer'], true)
            && $user->ledAssessorAssignments()->whereNull('revoked_at')->exists();
    }
}
