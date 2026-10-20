<div class="admin-page">
    <header class="page-heading admin-heading">
        <div>
            <span class="eyebrow dark">{{ __('administration.roles.eyebrow') }}</span>
            <h1>{{ __('administration.roles.title') }}</h1>
            <p>{{ __('administration.roles.intro') }}</p>
        </div>
        <a class="admin-secondary-link" href="{{ route('administration.users.index') }}" wire:navigate>{{ __('administration.roles.actions.manage_users') }}</a>
    </header>

    @if ($successMessage)
        <div class="upload-success" role="status">{{ $successMessage }}</div>
    @endif

    <div class="role-management-grid">
        <section class="role-list-card">
            <header><strong>{{ __('administration.roles.existing_roles') }}</strong><span>{{ trans_choice('administration.roles.counts.roles', $roles->count(), ['count' => $roles->count()]) }}</span></header>
            @foreach ($roles as $role)
                <button class="{{ $selectedRoleId === $role->id ? 'active' : '' }}" type="button" wire:click="selectRole({{ $role->id }})" wire:key="managed-role-{{ $role->id }}">
                    <span><strong>{{ \App\Support\LocalizedLabel::role($role->name) }}</strong><small>{{ trans_choice('administration.roles.counts.users', $role->users_count, ['count' => $role->users_count]) }}</small></span>
                    <em>{{ trans_choice('administration.roles.counts.permissions', $role->permissions->count(), ['count' => $role->permissions->count()]) }}</em>
                </button>
            @endforeach
        </section>

        <section class="permission-editor-card">
            @if ($selectedRole)
                <header>
                    <div><span>{{ __('administration.roles.permission_editor') }}</span><strong>{{ \App\Support\LocalizedLabel::role($selectedRole->name) }}</strong></div>
                    @if ($selectedRole->name === \App\Services\UserAccessManagementService::SUPER_ADMIN_ROLE)
                        <span class="admin-status inactive">{{ __('administration.roles.protected') }}</span>
                    @endif
                </header>

                @if ($selectedRole->name === \App\Services\UserAccessManagementService::SUPER_ADMIN_ROLE)
                    <div class="workflow-lock-notice"><strong>{{ __('administration.roles.lock.title') }}</strong><span>{{ __('administration.roles.lock.body') }}</span></div>
                @endif

                <form wire:submit="savePermissions">
                    <div class="permission-groups">
                        @php
                            $permissionGroupLabels = __('administration.roles.permission_groups');
                            $permissionLabels = __('administration.roles.permission_labels');
                        @endphp
                        @foreach ($permissions as $group => $groupPermissions)
                            <fieldset>
                                <legend>{{ $permissionGroupLabels[$group] ?? str($group)->replace('_', ' ')->title() }}</legend>
                                @foreach ($groupPermissions as $permission)
                                    @php $reserved = in_array($permission->name, ['user.manage', 'role.manage'], true); @endphp
                                    <label wire:key="permission-option-{{ $permission->id }}">
                                        <input type="checkbox" wire:model="permissionIds" value="{{ $permission->id }}" aria-label="{{ __('administration.roles.aria.permission', ['permission' => $permissionLabels[$permission->name] ?? $permission->name, 'role' => \App\Support\LocalizedLabel::role($selectedRole->name)]) }}" @disabled($selectedRole->name === \App\Services\UserAccessManagementService::SUPER_ADMIN_ROLE)>
                                        <span>{{ $permissionLabels[$permission->name] ?? $permission->name }}</span>
                                        @if ($reserved)<small>{{ __('administration.roles.super_admin_only') }}</small>@endif
                                    </label>
                                @endforeach
                            </fieldset>
                        @endforeach
                    </div>
                    @error('permissionIds') <p class="field-error">{{ $message }}</p> @enderror
                    @error('permissionIds.*') <p class="field-error">{{ $message }}</p> @enderror
                    @if ($selectedRole->name !== \App\Services\UserAccessManagementService::SUPER_ADMIN_ROLE)
                        <button class="primary-button" type="submit" wire:loading.attr="disabled" wire:target="savePermissions">{{ __('administration.roles.actions.save_permissions') }}</button>
                    @endif
                </form>
            @else
                <div class="empty-state"><h2>{{ __('administration.roles.empty.title') }}</h2><p>{{ __('administration.roles.empty.body') }}</p></div>
            @endif
        </section>
    </div>
</div>
