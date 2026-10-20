<div class="admin-page">
    <header class="page-heading admin-heading">
        <div>
            <span class="eyebrow dark">{{ __('administration.users.eyebrow') }}</span>
            <h1>{{ __('administration.users.title') }}</h1>
            <p>{{ __('administration.users.intro') }}</p>
        </div>
        <div class="admin-heading-actions">
            <a class="admin-secondary-link" href="{{ route('administration.led-setup.index') }}" wire:navigate>{{ __('administration.users.actions.manage_led_assignments') }}</a>
            <button class="primary-button admin-create-button" type="button" wire:click="startCreate">{{ __('administration.users.actions.create') }}</button>
        </div>
    </header>

    @if ($successMessage)
        <div class="upload-success" role="status">{{ $successMessage }}</div>
    @endif
    @error('account') <div class="admin-error" role="alert">{{ $message }}</div> @enderror

    <section class="admin-filter-card">
        <label>
            <span>{{ __('administration.users.search.label') }}</span>
            <input type="search" wire:model.live.debounce.350ms="search" placeholder="{{ __('administration.users.search.placeholder') }}">
        </label>
        <span>{{ trans_choice('administration.users.search.results', $users->total(), ['count' => $users->total()]) }}</span>
    </section>

    @if ($editorOpen)
        <section class="admin-editor-card">
            <div class="admin-editor-heading">
                <div>
                    <span>{{ $editingUserId ? __('administration.users.editor.edit') : __('administration.users.editor.create') }}</span>
                    <strong>{{ $editingUser?->name ?? __('administration.users.editor.create_authorized_account') }}</strong>
                </div>
                <button type="button" wire:click="cancelEdit">{{ __('administration.users.actions.close') }}</button>
            </div>

            <div class="admin-editor-grid">
                <form wire:submit="saveUser" class="admin-form">
                    <label for="admin-user-name">{{ __('administration.users.fields.name') }}</label>
                    <input id="admin-user-name" type="text" wire:model="name" autocomplete="name" required>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror

                    <label for="admin-user-email">{{ __('administration.users.fields.email') }}</label>
                    <input id="admin-user-email" type="email" wire:model="email" autocomplete="email" required>
                    @error('email') <p class="field-error">{{ $message }}</p> @enderror

                    @if ($editingUserId === null)
                        <div x-data="{ visible: false }" class="account-field">
                            <label for="admin-user-password">{{ __('administration.users.fields.initial_password') }}</label>
                            <div class="password-control">
                                <input id="admin-user-password" wire:model="password" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="new-password" required>
                                <x-password-visibility-toggle :label="__('administration.users.fields.initial_password')" />
                            </div>
                            @error('password') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div x-data="{ visible: false }" class="account-field">
                            <label for="admin-user-password-confirmation">{{ __('administration.users.fields.initial_password_confirmation') }}</label>
                            <div class="password-control">
                                <input id="admin-user-password-confirmation" wire:model="password_confirmation" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="new-password" required>
                                <x-password-visibility-toggle :label="__('administration.users.fields.initial_password_confirmation')" />
                            </div>
                        </div>
                    @endif

                    <button class="primary-button" type="submit" wire:loading.attr="disabled" wire:target="saveUser">{{ $editingUserId ? __('administration.users.actions.save_basic_data') : __('administration.users.actions.create_user') }}</button>
                </form>

                <div class="admin-role-editor">
                    <strong>{{ __('administration.users.global_roles.title') }}</strong>
                    <p>{{ __('administration.users.global_roles.help') }}</p>
                    @foreach ($roles as $role)
                        <label wire:key="user-role-option-{{ $role->id }}">
                            <input type="checkbox" wire:model="roleIds" value="{{ $role->id }}">
                            <span>{{ \App\Support\LocalizedLabel::role($role->name) }}</span>
                        </label>
                    @endforeach
                    @error('roleIds') <p class="field-error">{{ $message }}</p> @enderror
                    @error('roleIds.*') <p class="field-error">{{ $message }}</p> @enderror
                    @if ($editingUserId !== null)
                        <button type="button" wire:click="saveRoles">{{ __('administration.users.actions.save_roles') }}</button>
                    @endif
                </div>
            </div>
        </section>
    @endif

    @if ($scopeEditorOpen && $scopeUser)
        <section class="admin-editor-card admin-scope-card">
            <div class="admin-editor-heading">
                <div>
                    <span>{{ __('administration.users.scope.title') }}</span>
                    <strong>{{ $scopeUser->name }} · {{ $scopeUser->email }}</strong>
                </div>
                <button type="button" wire:click="cancelScopeEdit">{{ __('administration.users.actions.close') }}</button>
            </div>

            <div class="admin-scope-notice">
                <strong>{{ __('administration.users.scope.notice_title') }}</strong>
                <p>{{ __('administration.users.scope.notice_body') }}</p>
                <div class="admin-role-list">
                    @forelse ($scopeUser->roles as $role)<span>{{ \App\Support\LocalizedLabel::role($role->name) }}</span>@empty<em>{{ __('administration.users.empty.no_system_role') }}</em>@endforelse
                </div>
            </div>

            @if ($scopeProjects->isEmpty())
                <div class="table-empty">{{ __('administration.users.empty.no_manageable_projects') }}</div>
            @else
                <form wire:submit="saveProjectScope" class="admin-scope-form">
                    <label class="admin-scope-project" for="scope-project">
                        <span>{{ __('administration.users.fields.project') }}</span>
                        <select id="scope-project" wire:model.live="scopeProjectId">
                            @foreach ($scopeProjects as $scopeProject)
                                <option value="{{ $scopeProject->id }}">{{ $scopeProject->code }} · {{ $scopeProject->studyProgram->name }} · {{ \App\Support\LocalizedLabel::status($scopeProject->status) }}</option>
                            @endforeach
                        </select>
                    </label>
                    @error('scopeProjectId') <p class="field-error">{{ $message }}</p> @enderror

                    <div class="admin-scope-grid">
                        <section class="admin-scope-section">
                            <header><strong>{{ __('administration.users.scope.membership_title') }}</strong><span>{{ __('administration.users.scope.membership_help') }}</span></header>
                            <div class="admin-membership-options">
                                @foreach ($membershipRoleLabels as $membershipRole => $membershipLabel)
                                    @php $hasRequiredRole = $scopeUser->hasRole($membershipRoleNames[$membershipRole]); @endphp
                                    <label class="{{ $hasRequiredRole ? '' : 'role-missing' }}">
                                        <input type="checkbox" wire:model="membershipRoles" value="{{ $membershipRole }}">
                                        <span><strong>{{ $membershipLabel }}</strong><small>{{ $hasRequiredRole ? __('administration.users.scope.role_available') : __('administration.users.scope.role_required', ['role' => $membershipLabel]) }}</small></span>
                                    </label>
                                @endforeach
                            </div>
                            @error('membershipRoles') <p class="field-error">{{ $message }}</p> @enderror
                            @error('membershipRoles.*') <p class="field-error">{{ $message }}</p> @enderror
                        </section>

                        <section class="admin-scope-section">
                            <header><strong>{{ __('administration.users.scope.criterion_assignments_title') }}</strong><span>{{ __('administration.users.scope.criterion_assignments_help') }}</span></header>
                            <div class="admin-criteria-assignment-table">
                                <div class="admin-criteria-assignment-head"><span>{{ __('administration.users.fields.criterion') }}</span><span>{{ \App\Support\LocalizedLabel::role('PIC Kriteria') }}</span><span>{{ \App\Support\LocalizedLabel::role('Reviewer') }}</span></div>
                                @forelse ($scopeCriteria as $scopeCriterion)
                                    <div class="admin-criteria-assignment-row" wire:key="scope-criterion-{{ $scopeCriterion->id }}" style="--criterion-header: {{ $scopeCriterion->color_header }}; --criterion-body: {{ $scopeCriterion->color_body }}">
                                        <span><strong>{{ $scopeCriterion->code }}</strong>{{ $scopeCriterion->name }}</span>
                                        <label class="{{ $scopeUser->hasRole('PIC Kriteria') ? '' : 'role-missing' }}">
                                            <input type="checkbox" wire:model="picCriterionIds" value="{{ $scopeCriterion->id }}" aria-label="{{ __('administration.users.aria.criterion_pic', ['criterion' => $scopeCriterion->code]) }}">
                                        </label>
                                        <label class="{{ $scopeUser->hasRole('Reviewer') ? '' : 'role-missing' }}">
                                            <input type="checkbox" wire:model="reviewerCriterionIds" value="{{ $scopeCriterion->id }}" aria-label="{{ __('administration.users.aria.criterion_reviewer', ['criterion' => $scopeCriterion->code]) }}">
                                        </label>
                                    </div>
                                @empty
                                    <div class="table-empty">{{ __('administration.users.empty.no_project_criteria') }}</div>
                                @endforelse
                            </div>
                            @if (! $scopeUser->hasRole('PIC Kriteria') || ! $scopeUser->hasRole('Reviewer'))
                                <p class="admin-scope-hint">{{ __('administration.users.scope.assignment_role_hint') }}</p>
                            @endif
                            @error('picCriterionIds') <p class="field-error">{{ $message }}</p> @enderror
                            @error('picCriterionIds.*') <p class="field-error">{{ $message }}</p> @enderror
                            @error('reviewerCriterionIds') <p class="field-error">{{ $message }}</p> @enderror
                            @error('reviewerCriterionIds.*') <p class="field-error">{{ $message }}</p> @enderror
                        </section>
                    </div>

                    <button class="primary-button admin-scope-submit" type="submit" wire:loading.attr="disabled" wire:target="saveProjectScope">{{ __('administration.users.actions.save_project_scope') }}</button>
                </form>
            @endif
        </section>
    @endif

    @if ($resettingPasswordUser)
        <section class="admin-editor-card admin-reset-card">
            <div class="admin-editor-heading">
                <div><span>{{ __('administration.users.password_reset.title') }}</span><strong>{{ $resettingPasswordUser->name }}</strong></div>
                <button type="button" wire:click="cancelPasswordReset">{{ __('administration.users.actions.close') }}</button>
            </div>
            <form wire:submit="performPasswordReset" class="admin-form compact">
                <div x-data="{ visible: false }" class="account-field">
                    <label for="reset-user-password">{{ __('administration.users.fields.temporary_password') }}</label>
                    <div class="password-control">
                        <input id="reset-user-password" wire:model="resetPassword" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="new-password" required>
                        <x-password-visibility-toggle :label="__('administration.users.fields.temporary_password')" />
                    </div>
                    @error('resetPassword') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div x-data="{ visible: false }" class="account-field">
                    <label for="reset-user-password-confirmation">{{ __('administration.users.fields.password_confirmation') }}</label>
                    <div class="password-control">
                        <input id="reset-user-password-confirmation" wire:model="resetPassword_confirmation" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="new-password" required>
                        <x-password-visibility-toggle :label="__('administration.users.fields.password_confirmation')" />
                    </div>
                </div>
                <button class="primary-button" type="submit" wire:loading.attr="disabled" wire:target="performPasswordReset">{{ __('administration.users.actions.reset_password_and_sessions') }}</button>
            </form>
        </section>
    @endif

    <section class="admin-table-card">
        <div class="admin-table-wrap">
            <table>
                <thead><tr><th>{{ __('administration.users.table.user') }}</th><th>{{ __('administration.users.table.status') }}</th><th>{{ __('administration.users.table.global_roles') }}</th><th>{{ __('administration.users.table.memberships') }}</th><th>{{ __('administration.users.table.criterion_assignments') }}</th><th>{{ __('administration.users.table.led_history') }}</th><th>{{ __('administration.users.table.actions') }}</th></tr></thead>
                <tbody>
                    @forelse ($users as $managedUser)
                        <tr wire:key="managed-user-{{ $managedUser->id }}">
                            <td><strong>{{ $managedUser->name }}</strong><span>{{ $managedUser->email }}</span></td>
                            <td><span class="admin-status {{ $managedUser->is_active ? 'active' : 'inactive' }}">{{ \App\Support\LocalizedLabel::status($managedUser->is_active ? 'active' : 'inactive') }}</span></td>
                            <td><div class="admin-role-list">@forelse ($managedUser->roles as $role)<span>{{ \App\Support\LocalizedLabel::role($role->name) }}</span>@empty<em>{{ __('administration.users.empty.no_role') }}</em>@endforelse</div></td>
                            <td>
                                <div class="admin-scope-list">
                                    @forelse ($managedUser->projectMemberships as $membership)
                                        <span><strong>{{ $membership->project->code }}</strong>{{ $membershipRoleLabels[$membership->membership_role] ?? $membership->membership_role }}</span>
                                    @empty
                                        <em>{{ __('administration.users.empty.no_active_membership') }}</em>
                                    @endforelse
                                </div>
                            </td>
                            <td>
                                <div class="admin-scope-list">
                                    @forelse ($managedUser->criterionAssignments as $assignment)
                                        <span><strong>{{ $assignment->criterion->project->code }} · {{ $assignment->criterion->code }}</strong>{{ $assignment->assignment_role === 'pic' ? \App\Support\LocalizedLabel::role('PIC Kriteria') : \App\Support\LocalizedLabel::role('Reviewer') }}</span>
                                    @empty
                                        <em>{{ __('administration.users.empty.no_active_assignment') }}</em>
                                    @endforelse
                                </div>
                            </td>
                            <td>
                                <div class="admin-led-assignment-list">
                                    @forelse ($managedUser->ledAssessorAssignments as $ledAssignment)
                                        <article class="{{ $ledAssignment->revoked_at ? 'revoked' : 'active' }}">
                                            <div>
                                                <strong>{{ $ledAssignment->cycle->project->code }} · {{ $ledAssignment->cycle->cycle_name }} (#{{ $ledAssignment->cycle->cycle_number }})</strong>
                                                <span>{{ __('administration.users.history.indicator', ['criterion' => $ledAssignment->indicator->criterion_code, 'indicator' => $ledAssignment->indicator->indicator_number, 'title' => $ledAssignment->indicator->title]) }}</span>
                                                <b>{{ \App\Support\LocalizedLabel::assignmentRole($ledAssignment->assignment_role) }}</b>
                                            </div>
                                            <small>{{ __('administration.users.history.assigned', ['date' => \App\Support\BusinessDateTime::label($ledAssignment->assigned_at, 'd M Y H:i'), 'actor' => $ledAssignment->assigner?->name ?? __('administration.users.history.actor_unavailable')]) }}</small>
                                            @if ($ledAssignment->revoked_at)
                                                <small>{{ __('administration.users.history.revoked', ['date' => \App\Support\BusinessDateTime::label($ledAssignment->revoked_at, 'd M Y H:i'), 'actor' => $ledAssignment->revoker?->name ?? __('administration.users.history.actor_unavailable')]) }}</small>
                                            @else
                                                <small>{{ __('administration.users.history.status', ['status' => \App\Support\LocalizedLabel::status('active')]) }}</small>
                                            @endif
                                            @if ($ledAssignment->revocation_reason)<em>{{ $ledAssignment->revocation_reason }}</em>@endif
                                            <a href="{{ route('administration.led-setup.index', ['proyek' => $ledAssignment->cycle->project_id, 'siklus' => $ledAssignment->cycle_id, 'indikator' => $ledAssignment->indicator_id]) }}" wire:navigate>{{ __('administration.users.actions.manage_led_assignments') }}</a>
                                        </article>
                                    @empty
                                        <em>{{ __('administration.users.empty.no_led_history') }}</em>
                                    @endforelse
                                </div>
                            </td>
                            <td>
                                <div class="admin-actions">
                                    <button type="button" wire:click="startEdit({{ $managedUser->id }})">{{ __('administration.users.actions.edit') }}</button>
                                    <button type="button" wire:click="startScopeEdit({{ $managedUser->id }})">{{ __('administration.users.actions.manage_phase_one_scope') }}</button>
                                    @if ($managedUser->id !== auth()->id())
                                        <button type="button" wire:click="startPasswordReset({{ $managedUser->id }})">{{ __('administration.users.actions.reset_password') }}</button>
                                        <button class="{{ $managedUser->is_active ? 'danger' : '' }}" type="button" wire:click="toggleActive({{ $managedUser->id }})">{{ $managedUser->is_active ? __('administration.users.actions.deactivate') : __('administration.users.actions.activate') }}</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="table-empty">{{ __('administration.users.empty.no_search_results') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="admin-pagination">{{ $users->links() }}</div>
        @endif
    </section>
</div>
