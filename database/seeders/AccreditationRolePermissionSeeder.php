<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccreditationRolePermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'dashboard.view',
        'project.view',
        'project.manage',
        'user.manage',
        'role.manage',
        'assignment.manage',
        'criterion.view',
        'document.view',
        'document.upload',
        'document.submit',
        'document.review',
        'document.verify',
        'document.request_revision',
        'activity.view',
    ];

    public const ROLE_PERMISSIONS = [
        'Super Admin' => [
            'dashboard.view', 'project.view', 'project.manage', 'user.manage',
            'role.manage', 'assignment.manage', 'criterion.view', 'document.view',
            'document.upload', 'document.submit', 'activity.view',
        ],
        'Project Manager' => [
            'dashboard.view', 'project.view', 'project.manage', 'assignment.manage',
            'criterion.view', 'document.view', 'document.upload', 'document.submit',
            'activity.view',
        ],
        'PIC Kriteria' => [
            'dashboard.view', 'project.view', 'criterion.view', 'document.view',
            'document.upload', 'document.submit',
        ],
        'Reviewer' => [
            'dashboard.view', 'project.view', 'criterion.view', 'document.view',
            'document.review', 'document.verify', 'document.request_revision',
            'activity.view',
        ],
        'Viewer' => [
            'dashboard.view', 'project.view', 'criterion.view',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);

            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
