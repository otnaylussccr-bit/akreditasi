<?php

namespace App\Services;

use App\Models\AccreditationCriterion;
use App\Models\AccreditationCriterionAssignment;
use App\Models\AccreditationProject;
use App\Models\AccreditationProjectMember;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserAccessManagementService
{
    public const SUPER_ADMIN_ROLE = 'Super Admin';

    private const RESERVED_PERMISSIONS = ['user.manage', 'role.manage'];

    private const MEMBERSHIP_ROLE_MAP = [
        'project_manager' => 'Project Manager',
        'viewer' => 'Viewer',
        'pic' => 'PIC Kriteria',
        'reviewer' => 'Reviewer',
    ];

    private const ASSIGNMENT_ROLE_MAP = [
        'pic' => 'PIC Kriteria',
        'reviewer' => 'Reviewer',
    ];

    public function __construct(
        private readonly AdministrativeAuditService $audit,
        private readonly AccreditationAuthorizationService $authorization,
    ) {}

    public function canManageUsers(User $actor): bool
    {
        return $actor->is_active
            && $actor->hasRole(self::SUPER_ADMIN_ROLE)
            && $actor->can('user.manage');
    }

    public function canManageRoles(User $actor): bool
    {
        return $actor->is_active
            && $actor->hasRole(self::SUPER_ADMIN_ROLE)
            && $actor->can('role.manage');
    }

    public function createUser(User $actor, array $attributes, array $roleIds): User
    {
        $this->assertCanManageUsers($actor);

        return DB::transaction(function () use ($actor, $attributes, $roleIds): User {
            $roles = $this->rolesByIds($roleIds);
            $user = User::create([
                'name' => trim($attributes['name']),
                'email' => strtolower(trim($attributes['email'])),
                'password' => $attributes['password'],
                'is_active' => true,
            ]);
            $user->syncRoles($roles);

            $this->audit->record(
                $actor,
                'user.created',
                $user,
                $user->name,
                newValues: [
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_active' => true,
                    'roles' => $roles->pluck('name')->sort()->values()->all(),
                ],
            );

            return $user->load('roles');
        });
    }

    public function updateUser(User $actor, User $user, array $attributes): User
    {
        $this->assertCanManageUsers($actor);

        return DB::transaction(function () use ($actor, $user, $attributes): User {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            $oldValues = $target->only(['name', 'email']);
            $target->update([
                'name' => trim($attributes['name']),
                'email' => strtolower(trim($attributes['email'])),
            ]);

            $this->audit->record(
                $actor,
                'user.updated',
                $target,
                $target->name,
                $oldValues,
                $target->only(['name', 'email']),
            );

            return $target;
        });
    }

    public function syncUserRoles(User $actor, User $user, array $roleIds): User
    {
        $this->assertCanManageUsers($actor);

        return DB::transaction(function () use ($actor, $user, $roleIds): User {
            $activeSuperAdminCount = $this->lockActiveSuperAdmins()->count();
            $target = User::query()->with('roles')->lockForUpdate()->findOrFail($user->id);
            $roles = $this->rolesByIds($roleIds);
            $oldRoles = $target->roles->pluck('name')->sort()->values();
            $newRoles = $roles->pluck('name')->sort()->values();
            $removesSuperAdmin = $oldRoles->contains(self::SUPER_ADMIN_ROLE)
                && ! $newRoles->contains(self::SUPER_ADMIN_ROLE);

            if ($removesSuperAdmin && $target->is($actor)) {
                throw ValidationException::withMessages([
                    'roleIds' => 'Role Super Admin tidak dapat dicabut dari akun Anda sendiri.',
                ]);
            }

            if ($removesSuperAdmin && $target->is_active && $activeSuperAdminCount <= 1) {
                throw ValidationException::withMessages([
                    'roleIds' => 'Role Super Admin terakhir yang aktif tidak dapat dicabut.',
                ]);
            }

            $target->syncRoles($roles);
            $this->audit->record(
                $actor,
                'user.roles_updated',
                $target,
                $target->name,
                ['roles' => $oldRoles->all()],
                ['roles' => $newRoles->all()],
            );

            return $target->load('roles');
        });
    }

    public function setUserActive(User $actor, User $user, bool $active): User
    {
        $this->assertCanManageUsers($actor);

        return DB::transaction(function () use ($actor, $user, $active): User {
            $activeSuperAdminCount = $this->lockActiveSuperAdmins()->count();
            $target = User::query()->with('roles')->lockForUpdate()->findOrFail($user->id);

            if (! $active && $target->is($actor)) {
                throw ValidationException::withMessages([
                    'account' => 'Akun yang sedang digunakan tidak dapat dinonaktifkan.',
                ]);
            }

            if (! $active
                && $target->is_active
                && $target->hasRole(self::SUPER_ADMIN_ROLE)
                && $activeSuperAdminCount <= 1) {
                throw ValidationException::withMessages([
                    'account' => 'Super Admin aktif terakhir tidak dapat dinonaktifkan.',
                ]);
            }

            $oldActive = $target->is_active;
            $target->forceFill([
                'is_active' => $active,
                'deactivated_at' => $active ? null : now(),
                'deactivated_by' => $active ? null : $actor->id,
                'remember_token' => $active ? $target->remember_token : Str::random(60),
            ])->save();

            if (! $active) {
                $this->invalidateSessions($target);
            }

            $this->audit->record(
                $actor,
                $active ? 'user.activated' : 'user.deactivated',
                $target,
                $target->name,
                ['is_active' => $oldActive],
                ['is_active' => $active, 'authenticated_access_revoked' => ! $active],
            );

            return $target;
        });
    }

    public function resetPassword(User $actor, User $user, string $password): User
    {
        $this->assertCanManageUsers($actor);

        if ($user->is($actor)) {
            throw ValidationException::withMessages([
                'resetPassword' => 'Gunakan halaman Profil untuk mengubah password akun Anda sendiri.',
            ]);
        }

        return DB::transaction(function () use ($actor, $user, $password): User {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);

            if (Hash::check($password, $target->password)) {
                throw ValidationException::withMessages([
                    'resetPassword' => 'Password baru harus berbeda dari password pengguna saat ini.',
                ]);
            }

            $target->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();
            $this->invalidateSessions($target);
            $this->audit->record(
                $actor,
                'user.password_reset',
                $target,
                $target->name,
                newValues: ['authenticated_access_revoked' => true],
            );

            return $target;
        });
    }

    public function syncUserProjectScope(
        User $actor,
        User $user,
        AccreditationProject $project,
        array $membershipRoles,
        array $picCriterionIds,
        array $reviewerCriterionIds,
    ): User {
        $this->assertCanManageUsers($actor);
        $membershipRoles = array_values(array_unique($membershipRoles));
        abort_unless(array_diff($membershipRoles, array_keys(self::MEMBERSHIP_ROLE_MAP)) === [], 422);
        $picCriterionIds = $this->normalizedUniqueIds($picCriterionIds);
        $reviewerCriterionIds = $this->normalizedUniqueIds($reviewerCriterionIds);

        return DB::transaction(function () use (
            $actor,
            $user,
            $project,
            $membershipRoles,
            $picCriterionIds,
            $reviewerCriterionIds,
        ): User {
            $lockedProject = AccreditationProject::query()->lockForUpdate()->findOrFail($project->id);
            $target = User::query()->with('roles')->lockForUpdate()->findOrFail($user->id);

            $this->assertCanManageUsers($actor);

            if (! $this->authorization->canManageAssignments($actor, $lockedProject)) {
                throw new AuthorizationException('Anda tidak berhak mengelola scope project ini.');
            }

            $criteria = AccreditationCriterion::query()
                ->where('project_id', $lockedProject->id)
                ->whereKey(array_values(array_unique([...$picCriterionIds, ...$reviewerCriterionIds])))
                ->lockForUpdate()
                ->get(['id', 'project_id']);
            abort_unless(
                $criteria->count() === count(array_unique([...$picCriterionIds, ...$reviewerCriterionIds])),
                422,
            );

            $memberships = AccreditationProjectMember::query()
                ->where('project_id', $lockedProject->id)
                ->where('user_id', $target->id)
                ->whereIn('membership_role', array_keys(self::MEMBERSHIP_ROLE_MAP))
                ->orderBy('membership_role')
                ->lockForUpdate()
                ->get()
                ->keyBy('membership_role');
            $assignments = AccreditationCriterionAssignment::query()
                ->where('user_id', $target->id)
                ->whereIn('assignment_role', array_keys(self::ASSIGNMENT_ROLE_MAP))
                ->whereHas('criterion', fn ($query) => $query->where('project_id', $lockedProject->id))
                ->orderBy('criterion_id')
                ->orderBy('assignment_role')
                ->lockForUpdate()
                ->get();
            $oldValues = $this->projectScopeSnapshot($target, $lockedProject);

            foreach (self::MEMBERSHIP_ROLE_MAP as $membershipRole => $requiredRole) {
                $active = in_array($membershipRole, $membershipRoles, true);

                $membership = $memberships->get($membershipRole);

                if ($active && ! $target->hasRole($requiredRole) && ! $membership?->is_active) {
                    throw ValidationException::withMessages([
                        'membershipRoles' => "Role global {$requiredRole} diperlukan untuk membership ini.",
                    ]);
                }

                if ($membership) {
                    if ($membership->is_active !== $active) {
                        $membership->update(['is_active' => $active]);
                    }
                } elseif ($active) {
                    AccreditationProjectMember::create([
                        'project_id' => $lockedProject->id,
                        'user_id' => $target->id,
                        'membership_role' => $membershipRole,
                        'is_active' => true,
                    ]);
                }
            }

            $this->syncCriterionAssignments(
                $target,
                'pic',
                $picCriterionIds,
                $assignments->where('assignment_role', 'pic'),
            );
            $this->syncCriterionAssignments(
                $target,
                'reviewer',
                $reviewerCriterionIds,
                $assignments->where('assignment_role', 'reviewer'),
            );

            $newValues = $this->projectScopeSnapshot($target, $lockedProject);

            if ($oldValues !== $newValues) {
                $this->audit->record(
                    $actor,
                    'user.project_scope_updated',
                    $target,
                    $target->name,
                    $oldValues,
                    $newValues,
                );
            }

            return $target;
        });
    }

    public function changeOwnPassword(
        User $user,
        string $currentPassword,
        string $newPassword,
        ?string $currentSessionId,
    ): void {
        if (! $user->is_active) {
            throw new AuthorizationException('Akun tidak aktif.');
        }

        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => 'Password saat ini tidak valid.',
            ]);
        }

        if (Hash::check($newPassword, $user->password)) {
            throw ValidationException::withMessages([
                'newPassword' => 'Password baru harus berbeda dari password saat ini.',
            ]);
        }

        DB::transaction(function () use ($user, $newPassword, $currentSessionId): void {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            $target->forceFill([
                'password' => $newPassword,
                'remember_token' => Str::random(60),
            ])->save();
            $this->invalidateSessions($target, $currentSessionId);
            $this->audit->record(
                $target,
                'user.password_changed',
                $target,
                $target->name,
                newValues: ['other_access_revoked' => true],
            );
        });
    }

    public function syncRolePermissions(User $actor, Role $role, array $permissionIds): Role
    {
        $this->assertCanManageRoles($actor);

        return DB::transaction(function () use ($actor, $role, $permissionIds): Role {
            $target = Role::query()->with('permissions')->lockForUpdate()->findOrFail($role->id);

            if ($target->guard_name !== 'web' || $target->name === self::SUPER_ADMIN_ROLE) {
                throw ValidationException::withMessages([
                    'permissionIds' => 'Permission role Super Admin dilindungi dan tidak dapat diubah.',
                ]);
            }

            $permissions = Permission::query()
                ->where('guard_name', 'web')
                ->whereKey($permissionIds)
                ->get();
            abort_unless($permissions->count() === count(array_unique($permissionIds)), 422);

            if ($permissions->pluck('name')->intersect(self::RESERVED_PERMISSIONS)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'permissionIds' => 'Permission administrasi kritis hanya diperuntukkan bagi Super Admin.',
                ]);
            }

            $oldPermissions = $target->permissions->pluck('name')->sort()->values()->all();
            $newPermissions = $permissions->pluck('name')->sort()->values()->all();
            $target->syncPermissions($permissions);
            $this->audit->record(
                $actor,
                'role.permissions_updated',
                $target,
                $target->name,
                ['permissions' => $oldPermissions],
                ['permissions' => $newPermissions],
            );

            return $target->load('permissions');
        });
    }

    private function rolesByIds(array $roleIds)
    {
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        $roles = Role::query()->where('guard_name', 'web')->whereKey($roleIds)->get();
        abort_unless($roles->count() === count($roleIds), 422);

        return $roles;
    }

    private function syncCriterionAssignments(
        User $target,
        string $assignmentRole,
        array $criterionIds,
        Collection $existingAssignments,
    ): void {
        $requiredRole = self::ASSIGNMENT_ROLE_MAP[$assignmentRole];

        $existingActiveIds = $existingAssignments
            ->where('is_active', true)
            ->pluck('criterion_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (! $target->hasRole($requiredRole) && array_diff($criterionIds, $existingActiveIds) !== []) {
            throw ValidationException::withMessages([
                $assignmentRole === 'pic' ? 'picCriterionIds' : 'reviewerCriterionIds' => "Role global {$requiredRole} diperlukan untuk assignment ini.",
            ]);
        }

        $selected = array_flip($criterionIds);

        foreach ($existingAssignments as $assignment) {
            $active = isset($selected[$assignment->criterion_id]);

            if ($assignment->is_active !== $active) {
                $assignment->update(['is_active' => $active]);
            }

            unset($selected[$assignment->criterion_id]);
        }

        foreach (array_keys($selected) as $criterionId) {
            AccreditationCriterionAssignment::create([
                'criterion_id' => $criterionId,
                'user_id' => $target->id,
                'assignment_role' => $assignmentRole,
                'is_active' => true,
            ]);
        }
    }

    private function projectScopeSnapshot(User $target, AccreditationProject $project): array
    {
        return [
            'project_id' => $project->id,
            'project_code' => $project->code,
            'membership_roles' => AccreditationProjectMember::query()
                ->where('project_id', $project->id)
                ->where('user_id', $target->id)
                ->where('is_active', true)
                ->whereIn('membership_role', array_keys(self::MEMBERSHIP_ROLE_MAP))
                ->orderBy('membership_role')
                ->pluck('membership_role')
                ->all(),
            'pic_criterion_ids' => $this->activeCriterionAssignmentIds($target, $project, 'pic'),
            'reviewer_criterion_ids' => $this->activeCriterionAssignmentIds($target, $project, 'reviewer'),
        ];
    }

    private function activeCriterionAssignmentIds(
        User $target,
        AccreditationProject $project,
        string $assignmentRole,
    ): array {
        return AccreditationCriterionAssignment::query()
            ->where('user_id', $target->id)
            ->where('assignment_role', $assignmentRole)
            ->where('is_active', true)
            ->whereHas('criterion', fn ($query) => $query->where('project_id', $project->id))
            ->orderBy('criterion_id')
            ->pluck('criterion_id')
            ->all();
    }

    private function normalizedUniqueIds(array $ids): array
    {
        $normalized = array_map('intval', array_values($ids));
        abort_unless(count($normalized) === count(array_unique($normalized)), 422);

        sort($normalized);

        return $normalized;
    }

    private function lockActiveSuperAdmins(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($roles) => $roles
                ->where('name', self::SUPER_ADMIN_ROLE)
                ->where('guard_name', 'web'))
            ->orderBy('users.id')
            ->lockForUpdate()
            ->get();
    }

    private function invalidateSessions(User $user, ?string $exceptSessionId = null): void
    {
        if (! Schema::hasTable(config('session.table', 'sessions'))) {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($exceptSessionId, fn ($sessions) => $sessions->where('id', '!=', $exceptSessionId))
            ->delete();
    }

    private function assertCanManageUsers(User $actor): void
    {
        if (! $this->canManageUsers($actor)) {
            throw new AuthorizationException('Anda tidak berhak mengelola pengguna.');
        }
    }

    private function assertCanManageRoles(User $actor): void
    {
        if (! $this->canManageRoles($actor)) {
            throw new AuthorizationException('Anda tidak berhak mengelola role dan permission.');
        }
    }
}
