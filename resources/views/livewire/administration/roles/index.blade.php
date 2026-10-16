<div class="admin-page">
    <header class="page-heading admin-heading">
        <div>
            <span class="eyebrow dark">Administrasi Akses</span>
            <h1>Role & Permission</h1>
            <p>Kelola permission role existing melalui Spatie Permission. Role Super Admin dikunci.</p>
        </div>
        <a class="admin-secondary-link" href="{{ route('administration.users.index') }}" wire:navigate>Manajemen Pengguna</a>
    </header>

    @if ($successMessage)
        <div class="upload-success" role="status">{{ $successMessage }}</div>
    @endif

    <div class="role-management-grid">
        <section class="role-list-card">
            <header><strong>Role Existing</strong><span>{{ $roles->count() }} role</span></header>
            @foreach ($roles as $role)
                <button class="{{ $selectedRoleId === $role->id ? 'active' : '' }}" type="button" wire:click="selectRole({{ $role->id }})" wire:key="managed-role-{{ $role->id }}">
                    <span><strong>{{ $role->name }}</strong><small>{{ $role->users_count }} pengguna</small></span>
                    <em>{{ $role->permissions->count() }} permission</em>
                </button>
            @endforeach
        </section>

        <section class="permission-editor-card">
            @if ($selectedRole)
                <header>
                    <div><span>Permission Role</span><strong>{{ $selectedRole->name }}</strong></div>
                    @if ($selectedRole->name === \App\Services\UserAccessManagementService::SUPER_ADMIN_ROLE)
                        <span class="admin-status inactive">Dilindungi</span>
                    @endif
                </header>

                @if ($selectedRole->name === \App\Services\UserAccessManagementService::SUPER_ADMIN_ROLE)
                    <div class="workflow-lock-notice"><strong>Role sistem kritis</strong><span>Permission Super Admin dikelola oleh baseline sistem dan tidak dapat diubah melalui UI.</span></div>
                @endif

                <form wire:submit="savePermissions">
                    <div class="permission-groups">
                        @foreach ($permissions as $group => $groupPermissions)
                            <fieldset>
                                <legend>{{ str($group)->replace('_', ' ')->title() }}</legend>
                                @foreach ($groupPermissions as $permission)
                                    @php $reserved = in_array($permission->name, ['user.manage', 'role.manage'], true); @endphp
                                    <label wire:key="permission-option-{{ $permission->id }}">
                                        <input type="checkbox" wire:model="permissionIds" value="{{ $permission->id }}" @disabled($selectedRole->name === \App\Services\UserAccessManagementService::SUPER_ADMIN_ROLE)>
                                        <span>{{ $permission->name }}</span>
                                        @if ($reserved)<small>Khusus Super Admin</small>@endif
                                    </label>
                                @endforeach
                            </fieldset>
                        @endforeach
                    </div>
                    @error('permissionIds') <p class="field-error">{{ $message }}</p> @enderror
                    @error('permissionIds.*') <p class="field-error">{{ $message }}</p> @enderror
                    @if ($selectedRole->name !== \App\Services\UserAccessManagementService::SUPER_ADMIN_ROLE)
                        <button class="primary-button" type="submit" wire:loading.attr="disabled" wire:target="savePermissions">Simpan Permission</button>
                    @endif
                </form>
            @else
                <div class="empty-state"><h2>Pilih role</h2><p>Permission role akan ditampilkan dan dapat ditinjau di sini.</p></div>
            @endif
        </section>
    </div>
</div>
