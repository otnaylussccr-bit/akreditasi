<?php

namespace App\Support;

final class LocalizedLabel
{
    public static function role(string $role, ?string $locale = null): string
    {
        $key = match ($role) {
            'Super Admin' => 'super_admin',
            'Project Manager' => 'project_manager',
            'PIC Kriteria' => 'criterion_pic',
            'Reviewer' => 'reviewer',
            'Viewer' => 'viewer',
            default => null,
        };

        return $key ? __('common.roles.'.$key, [], $locale) : $role;
    }

    public static function status(?string $status): string
    {
        if (! $status) {
            return __('common.status.not_started');
        }

        $key = 'common.status.'.$status;
        $translated = __($key);

        return $translated === $key ? str($status)->replace('_', ' ')->title()->toString() : $translated;
    }

    public static function assignmentRole(string $role): string
    {
        $key = match ($role) {
            'assessor_primary' => 'lead_assessor',
            'assessor_support' => 'supporting_assessor',
            'reviewer' => 'led_reviewer',
            default => null,
        };

        return $key ? __('common.assignment_roles.'.$key) : $role;
    }

    public static function mappingRole(string $role): string
    {
        $key = 'common.mapping_roles.'.$role;
        $translated = __($key);

        return $translated === $key ? $role : $translated;
    }
}
