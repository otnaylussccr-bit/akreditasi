<?php

namespace App\Policies;

use App\Models\User;
use App\Services\UserAccessManagementService;

class UserPolicy
{
    public function __construct(
        private readonly UserAccessManagementService $access,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $this->access->canManageUsers($actor);
    }

    public function view(User $actor, User $user): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(User $actor, User $user): bool
    {
        return $this->viewAny($actor);
    }

    public function manageRoles(User $actor, User $user): bool
    {
        return $this->viewAny($actor);
    }

    public function manageProjectScope(User $actor, User $user): bool
    {
        return $this->viewAny($actor);
    }

    public function resetPassword(User $actor, User $user): bool
    {
        return $this->viewAny($actor) && ! $actor->is($user);
    }

    public function toggleActive(User $actor, User $user): bool
    {
        return $this->viewAny($actor) && ! $actor->is($user);
    }
}
