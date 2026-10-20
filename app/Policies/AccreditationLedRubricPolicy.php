<?php

namespace App\Policies;

use App\Models\AccreditationLedRubric;
use App\Models\User;

class AccreditationLedRubricPolicy
{
    public function view(User $user, AccreditationLedRubric $rubric): bool
    {
        return $user->can('project.view');
    }

    public function update(User $user, AccreditationLedRubric $rubric): bool
    {
        return $rubric->indicator->instrumentVersion->status === 'draft'
            && $user->hasRole('Super Admin')
            && $user->can('project.manage');
    }
}
