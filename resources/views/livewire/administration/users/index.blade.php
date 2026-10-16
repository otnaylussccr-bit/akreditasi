<div class="admin-page">
    <header class="page-heading admin-heading">
        <div>
            <span class="eyebrow dark">Administrasi Akses</span>
            <h1>Manajemen Pengguna</h1>
            <p>Kelola identitas, role global, membership project, assignment kriteria, dan keamanan akun secara terpisah.</p>
        </div>
        <button class="primary-button admin-create-button" type="button" wire:click="startCreate">+ Pengguna Baru</button>
    </header>

    @if ($successMessage)
        <div class="upload-success" role="status">{{ $successMessage }}</div>
    @endif
    @error('account') <div class="admin-error" role="alert">{{ $message }}</div> @enderror

    <section class="admin-filter-card">
        <label>
            <span>Cari Pengguna</span>
            <input type="search" wire:model.live.debounce.350ms="search" placeholder="Nama atau email...">
        </label>
        <span>{{ $users->total() }} akun ditemukan</span>
    </section>

    @if ($editorOpen)
        <section class="admin-editor-card">
            <div class="admin-editor-heading">
                <div>
                    <span>{{ $editingUserId ? 'Edit Pengguna' : 'Pengguna Baru' }}</span>
                    <strong>{{ $editingUser?->name ?? 'Buat akun terotorisasi' }}</strong>
                </div>
                <button type="button" wire:click="cancelEdit">Tutup</button>
            </div>

            <div class="admin-editor-grid">
                <form wire:submit="saveUser" class="admin-form">
                    <label for="admin-user-name">Nama</label>
                    <input id="admin-user-name" type="text" wire:model="name" autocomplete="name" required>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror

                    <label for="admin-user-email">Email</label>
                    <input id="admin-user-email" type="email" wire:model="email" autocomplete="email" required>
                    @error('email') <p class="field-error">{{ $message }}</p> @enderror

                    @if ($editingUserId === null)
                        <div x-data="{ visible: false }" class="account-field">
                            <label for="admin-user-password">Password Awal</label>
                            <div class="password-control">
                                <input id="admin-user-password" wire:model="password" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="new-password" required>
                                <x-password-visibility-toggle label="password awal" />
                            </div>
                            @error('password') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div x-data="{ visible: false }" class="account-field">
                            <label for="admin-user-password-confirmation">Konfirmasi Password Awal</label>
                            <div class="password-control">
                                <input id="admin-user-password-confirmation" wire:model="password_confirmation" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="new-password" required>
                                <x-password-visibility-toggle label="konfirmasi password awal" />
                            </div>
                        </div>
                    @endif

                    <button class="primary-button" type="submit" wire:loading.attr="disabled" wire:target="saveUser">{{ $editingUserId ? 'Simpan Data Dasar' : 'Buat Pengguna' }}</button>
                </form>

                <div class="admin-role-editor">
                    <strong>Role Global</strong>
                    <p>Perubahan ini tidak mengubah membership atau assignment kriteria.</p>
                    @foreach ($roles as $role)
                        <label wire:key="user-role-option-{{ $role->id }}">
                            <input type="checkbox" wire:model="roleIds" value="{{ $role->id }}">
                            <span>{{ $role->name }}</span>
                        </label>
                    @endforeach
                    @error('roleIds') <p class="field-error">{{ $message }}</p> @enderror
                    @error('roleIds.*') <p class="field-error">{{ $message }}</p> @enderror
                    @if ($editingUserId !== null)
                        <button type="button" wire:click="saveRoles">Simpan Role</button>
                    @endif
                </div>
            </div>
        </section>
    @endif

    @if ($scopeEditorOpen && $scopeUser)
        <section class="admin-editor-card admin-scope-card">
            <div class="admin-editor-heading">
                <div>
                    <span>Project Membership & Assignment</span>
                    <strong>{{ $scopeUser->name }} · {{ $scopeUser->email }}</strong>
                </div>
                <button type="button" wire:click="cancelScopeEdit">Tutup</button>
            </div>

            <div class="admin-scope-notice">
                <strong>Role sistem tidak otomatis memberi scope project.</strong>
                <p>Membership Viewer membuka Dashboard seluruh kriteria dalam project. PIC dan Reviewer tetap memerlukan assignment kriteria aktif untuk memperoleh akses operasional.</p>
                <div class="admin-role-list">
                    @forelse ($scopeUser->roles as $role)<span>{{ $role->name }}</span>@empty<em>Tanpa role sistem</em>@endforelse
                </div>
            </div>

            @if ($scopeProjects->isEmpty())
                <div class="table-empty">Belum ada project yang dapat dikelola.</div>
            @else
                <form wire:submit="saveProjectScope" class="admin-scope-form">
                    <label class="admin-scope-project" for="scope-project">
                        <span>Project</span>
                        <select id="scope-project" wire:model.live="scopeProjectId">
                            @foreach ($scopeProjects as $scopeProject)
                                <option value="{{ $scopeProject->id }}">{{ $scopeProject->code }} · {{ $scopeProject->studyProgram->name }} · {{ ucfirst($scopeProject->status) }}</option>
                            @endforeach
                        </select>
                    </label>
                    @error('scopeProjectId') <p class="field-error">{{ $message }}</p> @enderror

                    <div class="admin-scope-grid">
                        <section class="admin-scope-section">
                            <header><strong>Project Membership</strong><span>Menetapkan posisi user di project terpilih.</span></header>
                            <div class="admin-membership-options">
                                @foreach ($membershipRoleLabels as $membershipRole => $membershipLabel)
                                    @php $hasRequiredRole = $scopeUser->hasRole($membershipLabel); @endphp
                                    <label class="{{ $hasRequiredRole ? '' : 'role-missing' }}">
                                        <input type="checkbox" wire:model="membershipRoles" value="{{ $membershipRole }}">
                                        <span><strong>{{ $membershipLabel }}</strong><small>{{ $hasRequiredRole ? 'Role global tersedia' : 'Memerlukan role global '.$membershipLabel }}</small></span>
                                    </label>
                                @endforeach
                            </div>
                            @error('membershipRoles') <p class="field-error">{{ $message }}</p> @enderror
                            @error('membershipRoles.*') <p class="field-error">{{ $message }}</p> @enderror
                        </section>

                        <section class="admin-scope-section">
                            <header><strong>Assignment Kriteria</strong><span>PIC mengelola dokumen; Reviewer memverifikasi hanya pada kriteria terpilih.</span></header>
                            <div class="admin-criteria-assignment-table">
                                <div class="admin-criteria-assignment-head"><span>Kriteria</span><span>PIC</span><span>Reviewer</span></div>
                                @forelse ($scopeCriteria as $scopeCriterion)
                                    <div class="admin-criteria-assignment-row" wire:key="scope-criterion-{{ $scopeCriterion->id }}" style="--criterion-header: {{ $scopeCriterion->color_header }}; --criterion-body: {{ $scopeCriterion->color_body }}">
                                        <span><strong>{{ $scopeCriterion->code }}</strong>{{ $scopeCriterion->name }}</span>
                                        <label class="{{ $scopeUser->hasRole('PIC Kriteria') ? '' : 'role-missing' }}">
                                            <input type="checkbox" wire:model="picCriterionIds" value="{{ $scopeCriterion->id }}" aria-label="PIC Kriteria {{ $scopeCriterion->code }}">
                                        </label>
                                        <label class="{{ $scopeUser->hasRole('Reviewer') ? '' : 'role-missing' }}">
                                            <input type="checkbox" wire:model="reviewerCriterionIds" value="{{ $scopeCriterion->id }}" aria-label="Reviewer Kriteria {{ $scopeCriterion->code }}">
                                        </label>
                                    </div>
                                @empty
                                    <div class="table-empty">Project ini belum memiliki kriteria.</div>
                                @endforelse
                            </div>
                            @if (! $scopeUser->hasRole('PIC Kriteria') || ! $scopeUser->hasRole('Reviewer'))
                                <p class="admin-scope-hint">Assignment baru hanya dapat diaktifkan jika role global yang sesuai sudah diberikan. Scope existing tidak berubah ketika role global diedit.</p>
                            @endif
                            @error('picCriterionIds') <p class="field-error">{{ $message }}</p> @enderror
                            @error('picCriterionIds.*') <p class="field-error">{{ $message }}</p> @enderror
                            @error('reviewerCriterionIds') <p class="field-error">{{ $message }}</p> @enderror
                            @error('reviewerCriterionIds.*') <p class="field-error">{{ $message }}</p> @enderror
                        </section>
                    </div>

                    <button class="primary-button admin-scope-submit" type="submit" wire:loading.attr="disabled" wire:target="saveProjectScope">Simpan Scope Project</button>
                </form>
            @endif
        </section>
    @endif

    @if ($resettingPasswordUser)
        <section class="admin-editor-card admin-reset-card">
            <div class="admin-editor-heading">
                <div><span>Reset Password</span><strong>{{ $resettingPasswordUser->name }}</strong></div>
                <button type="button" wire:click="cancelPasswordReset">Tutup</button>
            </div>
            <form wire:submit="performPasswordReset" class="admin-form compact">
                <div x-data="{ visible: false }" class="account-field">
                    <label for="reset-user-password">Password Sementara Baru</label>
                    <div class="password-control">
                        <input id="reset-user-password" wire:model="resetPassword" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="new-password" required>
                        <x-password-visibility-toggle label="password reset" />
                    </div>
                    @error('resetPassword') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div x-data="{ visible: false }" class="account-field">
                    <label for="reset-user-password-confirmation">Konfirmasi Password</label>
                    <div class="password-control">
                        <input id="reset-user-password-confirmation" wire:model="resetPassword_confirmation" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="new-password" required>
                        <x-password-visibility-toggle label="konfirmasi password reset" />
                    </div>
                </div>
                <button class="primary-button" type="submit" wire:loading.attr="disabled" wire:target="performPasswordReset">Reset dan Akhiri Session</button>
            </form>
        </section>
    @endif

    <section class="admin-table-card">
        <div class="admin-table-wrap">
            <table>
                <thead><tr><th>Pengguna</th><th>Status</th><th>Role Sistem</th><th>Project Membership</th><th>PIC / Reviewer Assignment</th><th>Aksi</th></tr></thead>
                <tbody>
                    @forelse ($users as $managedUser)
                        <tr wire:key="managed-user-{{ $managedUser->id }}">
                            <td><strong>{{ $managedUser->name }}</strong><span>{{ $managedUser->email }}</span></td>
                            <td><span class="admin-status {{ $managedUser->is_active ? 'active' : 'inactive' }}">{{ $managedUser->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td><div class="admin-role-list">@forelse ($managedUser->roles as $role)<span>{{ $role->name }}</span>@empty<em>Tanpa role</em>@endforelse</div></td>
                            <td>
                                <div class="admin-scope-list">
                                    @forelse ($managedUser->projectMemberships as $membership)
                                        <span><strong>{{ $membership->project->code }}</strong>{{ $membershipRoleLabels[$membership->membership_role] ?? $membership->membership_role }}</span>
                                    @empty
                                        <em>Belum ada membership aktif</em>
                                    @endforelse
                                </div>
                            </td>
                            <td>
                                <div class="admin-scope-list">
                                    @forelse ($managedUser->criterionAssignments as $assignment)
                                        <span><strong>{{ $assignment->criterion->project->code }} · {{ $assignment->criterion->code }}</strong>{{ $assignment->assignment_role === 'pic' ? 'PIC' : 'Reviewer' }}</span>
                                    @empty
                                        <em>Belum ada assignment aktif</em>
                                    @endforelse
                                </div>
                            </td>
                            <td>
                                <div class="admin-actions">
                                    <button type="button" wire:click="startEdit({{ $managedUser->id }})">Edit</button>
                                    <button type="button" wire:click="startScopeEdit({{ $managedUser->id }})">Kelola Scope</button>
                                    @if ($managedUser->id !== auth()->id())
                                        <button type="button" wire:click="startPasswordReset({{ $managedUser->id }})">Reset Password</button>
                                        <button class="{{ $managedUser->is_active ? 'danger' : '' }}" type="button" wire:click="toggleActive({{ $managedUser->id }})">{{ $managedUser->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="table-empty">Tidak ada pengguna sesuai pencarian.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="admin-pagination">{{ $users->links() }}</div>
        @endif
    </section>
</div>
