<?php

namespace App\Policies;

use App\Models\User;
use App\Services\UserAccessManagementService;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function __construct(
        private readonly UserAccessManagementService $access,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $this->access->canManageRoles($actor);
    }

    public function view(User $actor, Role $role): bool
    {
        return $this->viewAny($actor);
    }

    public function update(User $actor, Role $role): bool
    {
        return $this->viewAny($actor) && $role->name !== UserAccessManagementService::SUPER_ADMIN_ROLE;
    }
}
