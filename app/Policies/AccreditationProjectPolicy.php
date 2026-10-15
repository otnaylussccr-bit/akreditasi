<?php

namespace App\Policies;

use App\Models\AccreditationProject;
use App\Models\User;
use App\Services\AccreditationAuthorizationService;

class AccreditationProjectPolicy
{
    public function __construct(private AccreditationAuthorizationService $authorization) {}

    public function view(User $user, AccreditationProject $project): bool
    {
        return $this->authorization->canViewProject($user, $project);
    }

    public function update(User $user, AccreditationProject $project): bool
    {
        return $this->authorization->canManageProject($user, $project);
    }

    public function manageAssignments(User $user, AccreditationProject $project): bool
    {
        return $this->authorization->canManageAssignments($user, $project);
    }
}
