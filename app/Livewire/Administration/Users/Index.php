<?php

namespace App\Livewire\Administration\Users;

use App\Livewire\Component;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationCriterionAssignment;
use App\Models\AccreditationProject;
use App\Models\AccreditationProjectMember;
use App\Models\User;
use App\Services\UserAccessManagementService;
use App\Support\LocalizedLabel;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    private const MEMBERSHIP_ROLE_LABELS = [
        'project_manager' => 'Project Manager',
        'viewer' => 'Viewer',
        'pic' => 'PIC Kriteria',
        'reviewer' => 'Reviewer',
    ];

    #[Url(as: 'cari', except: '')]
    public string $search = '';

    public ?int $editingUserId = null;

    public bool $editorOpen = false;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public array $roleIds = [];

    public bool $scopeEditorOpen = false;

    #[Locked]
    public ?int $scopeUserId = null;

    public ?int $scopeProjectId = null;

    public array $membershipRoles = [];

    public array $picCriterionIds = [];

    public array $reviewerCriterionIds = [];

    public ?int $resettingPasswordUserId = null;

    public string $resetPassword = '';

    public string $resetPassword_confirmation = '';

    public ?string $successMessage = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', User::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function startCreate(): void
    {
        Gate::authorize('create', User::class);
        $this->resetScopeEditor();
        $this->resetEditor();
        $this->editorOpen = true;
        $this->resetValidation();
        $this->successMessage = null;
    }

    public function startEdit(int $userId): void
    {
        $user = $this->authorizedUser($userId, 'update');
        $this->resetScopeEditor();
        $this->editingUserId = $user->id;
        $this->editorOpen = true;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->password_confirmation = '';
        $this->roleIds = $user->roles()->where('guard_name', 'web')->pluck('roles.id')->all();
        $this->resettingPasswordUserId = null;
        $this->resetValidation();
        $this->successMessage = null;
    }

    public function cancelEdit(): void
    {
        Gate::authorize('viewAny', User::class);
        $this->resetEditor();
        $this->editorOpen = false;
        $this->resetValidation();
    }

    public function saveUser(UserAccessManagementService $access): void
    {
        try {
            $rules = [
                'name' => ['required', 'string', 'max:255'],
                'email' => [
                    'required',
                    'email',
                    'max:255',
                    Rule::unique('users', 'email')->ignore($this->editingUserId),
                ],
            ];

            if ($this->editingUserId === null) {
                Gate::authorize('create', User::class);
                $rules['password'] = PasswordPolicy::rules();
                $rules['roleIds'] = ['required', 'array', 'min:1'];
                $rules['roleIds.*'] = ['integer', Rule::exists('roles', 'id')->where('guard_name', 'web')];
                $validated = $this->validate($rules);
                $user = $access->createUser(Auth::user(), $validated, $this->roleIds);
                $this->successMessage = __('administration.users.notifications.created', ['name' => $user->name]);
            } else {
                $user = $this->authorizedUser($this->editingUserId, 'update');
                $validated = $this->validate($rules);
                $user = $access->updateUser(Auth::user(), $user, $validated);
                $this->successMessage = __('administration.users.notifications.updated', ['name' => $user->name]);
            }

            $this->resetEditor();
            $this->editorOpen = false;
            $this->resetValidation();
        } finally {
            $this->reset(['password', 'password_confirmation']);
        }
    }

    public function saveRoles(UserAccessManagementService $access): void
    {
        abort_unless($this->editingUserId !== null, 404);
        $user = $this->authorizedUser($this->editingUserId, 'manageRoles');
        $this->validate([
            'roleIds' => ['array'],
            'roleIds.*' => ['integer', Rule::exists('roles', 'id')->where('guard_name', 'web')],
        ]);
        $access->syncUserRoles(Auth::user(), $user, $this->roleIds);
        $this->successMessage = __('administration.users.notifications.roles_updated', ['name' => $user->name]);
        $this->resetEditor();
        $this->editorOpen = false;
        $this->resetValidation();
    }

    public function toggleActive(int $userId, UserAccessManagementService $access): void
    {
        $user = $this->authorizedUser($userId, 'toggleActive');
        $updated = $access->setUserActive(Auth::user(), $user, ! $user->is_active);
        $this->successMessage = $updated->is_active
            ? __('administration.users.notifications.activated', ['name' => $updated->name])
            : __('administration.users.notifications.deactivated', ['name' => $updated->name]);
        $this->resetEditor();
        $this->resetScopeEditor();
    }

    public function startScopeEdit(int $userId): void
    {
        $user = $this->authorizedUser($userId, 'manageProjectScope');
        $this->resetEditor();
        $this->editorOpen = false;
        $this->reset(['resettingPasswordUserId', 'resetPassword', 'resetPassword_confirmation']);
        $this->scopeUserId = $user->id;
        $this->scopeEditorOpen = true;
        $this->scopeProjectId = $this->preferredScopeProjectId($user);
        $this->resetValidation();
        $this->successMessage = null;

        if ($this->scopeProjectId !== null) {
            $this->loadProjectScope($user, $this->authorizedScopeProject($this->scopeProjectId));
        }
    }

    public function updatedScopeProjectId(): void
    {
        abort_unless($this->scopeEditorOpen && $this->scopeUserId !== null, 404);
        $user = $this->authorizedUser($this->scopeUserId, 'manageProjectScope');
        $this->resetScopeValues();

        if ($this->scopeProjectId !== null) {
            $this->loadProjectScope($user, $this->authorizedScopeProject($this->scopeProjectId));
        }
    }

    public function saveProjectScope(UserAccessManagementService $access): void
    {
        abort_unless($this->scopeEditorOpen && $this->scopeUserId !== null && $this->scopeProjectId !== null, 404);
        $user = $this->authorizedUser($this->scopeUserId, 'manageProjectScope');
        $project = $this->authorizedScopeProject($this->scopeProjectId);
        $criterionExists = Rule::exists('accreditation_criteria', 'id')
            ->where(fn ($query) => $query->where('project_id', $project->id));
        $this->validate([
            'scopeProjectId' => ['required', 'integer', Rule::exists('accreditation_projects', 'id')],
            'membershipRoles' => ['array'],
            'membershipRoles.*' => ['string', 'distinct', Rule::in(array_keys(self::MEMBERSHIP_ROLE_LABELS))],
            'picCriterionIds' => ['array'],
            'picCriterionIds.*' => ['integer', 'distinct', $criterionExists],
            'reviewerCriterionIds' => ['array'],
            'reviewerCriterionIds.*' => ['integer', 'distinct', $criterionExists],
        ]);

        $access->syncUserProjectScope(
            Auth::user(),
            $user,
            $project,
            $this->membershipRoles,
            $this->picCriterionIds,
            $this->reviewerCriterionIds,
        );
        $this->loadProjectScope($user->fresh('roles'), $project);
        $this->successMessage = __('administration.users.notifications.scope_updated', [
            'project' => $project->code,
            'name' => $user->name,
        ]);
        $this->resetValidation();
    }

    public function cancelScopeEdit(): void
    {
        Gate::authorize('viewAny', User::class);
        $this->resetScopeEditor();
        $this->resetValidation();
    }

    public function startPasswordReset(int $userId): void
    {
        $user = $this->authorizedUser($userId, 'resetPassword');
        $this->resetScopeEditor();
        $this->resettingPasswordUserId = $user->id;
        $this->resetPassword = '';
        $this->resetPassword_confirmation = '';
        $this->resetValidation();
        $this->successMessage = null;
    }

    public function cancelPasswordReset(): void
    {
        Gate::authorize('viewAny', User::class);
        $this->reset(['resettingPasswordUserId', 'resetPassword', 'resetPassword_confirmation']);
        $this->resetValidation();
    }

    public function performPasswordReset(UserAccessManagementService $access): void
    {
        try {
            abort_unless($this->resettingPasswordUserId !== null, 404);
            $user = $this->authorizedUser($this->resettingPasswordUserId, 'resetPassword');
            $key = 'admin-password-reset|'.Auth::id().'|'.$user->id.'|'.request()->ip();

            if (RateLimiter::tooManyAttempts($key, 5)) {
                throw ValidationException::withMessages([
                    'resetPassword' => __('administration.users.validation.password_reset_throttled', [
                        'seconds' => RateLimiter::availableIn($key),
                    ]),
                ]);
            }

            RateLimiter::hit($key, 600);
            $validated = $this->validate([
                'resetPassword' => PasswordPolicy::rules(),
            ]);
            $access->resetPassword(Auth::user(), $user, $validated['resetPassword']);
            $this->successMessage = __('administration.users.notifications.password_reset', ['name' => $user->name]);
            $this->reset(['resettingPasswordUserId', 'resetPassword', 'resetPassword_confirmation']);
            $this->resetValidation();
        } finally {
            $this->reset(['resetPassword', 'resetPassword_confirmation']);
        }
    }

    public function render()
    {
        Gate::authorize('viewAny', User::class);
        $search = trim($this->search);

        return view('livewire.administration.users.index', [
            'users' => User::query()
                ->with('roles:id,name,guard_name')
                ->with([
                    'projectMemberships' => fn ($memberships) => $memberships
                        ->where('is_active', true)
                        ->with('project:id,code,name'),
                    'criterionAssignments' => fn ($assignments) => $assignments
                        ->where('is_active', true)
                        ->with(['criterion:id,project_id,code,name', 'criterion.project:id,code']),
                    'ledAssessorAssignments' => fn ($assignments) => $assignments
                        ->with([
                            'cycle:id,project_id,cycle_number,cycle_name,status',
                            'cycle.project:id,code,name',
                            'indicator:id,indicator_number,criterion_code,title',
                            'assigner:id,name',
                            'revoker:id,name',
                        ])
                        ->orderByDesc('assigned_at')
                        ->orderByDesc('id'),
                ])
                ->when($search !== '', fn ($users) => $users->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                }))
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(10),
            'roles' => Role::query()->where('guard_name', 'web')->orderBy('name')->get(),
            'editingUser' => $this->editingUserId
                ? User::query()->find($this->editingUserId)
                : null,
            'resettingPasswordUser' => $this->resettingPasswordUserId
                ? User::query()->find($this->resettingPasswordUserId)
                : null,
            'scopeUser' => $this->scopeUserId
                ? User::query()->with('roles:id,name,guard_name')->find($this->scopeUserId)
                : null,
            'scopeProjects' => $this->scopeEditorOpen
                ? AccreditationProject::query()
                    ->with('studyProgram:id,name')
                    ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                    ->orderBy('final_deadline')
                    ->orderBy('id')
                    ->get(['id', 'code', 'name', 'study_program_id', 'status'])
                : collect(),
            'scopeCriteria' => $this->scopeProjectId
                ? AccreditationCriterion::query()
                    ->where('project_id', $this->scopeProjectId)
                    ->orderBy('position')
                    ->get(['id', 'project_id', 'code', 'name', 'color_header', 'color_body'])
                : collect(),
            'membershipRoleNames' => self::MEMBERSHIP_ROLE_LABELS,
            'membershipRoleLabels' => array_map(
                fn (string $role): string => LocalizedLabel::role($role),
                self::MEMBERSHIP_ROLE_LABELS,
            ),
        ])->title(__('administration.users.meta_title'));
    }

    protected function messages(): array
    {
        return [
            'name.required' => __('administration.validation.required'),
            'name.string' => __('administration.validation.string'),
            'name.max' => __('administration.validation.max_string'),
            'email.required' => __('administration.validation.required'),
            'email.email' => __('administration.validation.email'),
            'email.max' => __('administration.validation.max_string'),
            'email.unique' => __('administration.validation.unique'),
            'password.required' => __('administration.validation.required'),
            'password.string' => __('administration.validation.string'),
            'password.confirmed' => __('administration.validation.confirmed'),
            'password.min' => __('administration.validation.min_string'),
            'password.regex' => __('administration.validation.password_format'),
            'roleIds.required' => __('administration.validation.required'),
            'roleIds.array' => __('administration.validation.array'),
            'roleIds.min' => __('administration.validation.min_array'),
            'roleIds.*.integer' => __('administration.validation.integer'),
            'roleIds.*.exists' => __('administration.validation.exists'),
            'scopeProjectId.required' => __('administration.validation.required'),
            'scopeProjectId.integer' => __('administration.validation.integer'),
            'scopeProjectId.exists' => __('administration.validation.exists'),
            'membershipRoles.array' => __('administration.validation.array'),
            'membershipRoles.*.string' => __('administration.validation.string'),
            'membershipRoles.*.distinct' => __('administration.validation.distinct'),
            'membershipRoles.*.in' => __('administration.validation.in'),
            'picCriterionIds.array' => __('administration.validation.array'),
            'picCriterionIds.*.integer' => __('administration.validation.integer'),
            'picCriterionIds.*.distinct' => __('administration.validation.distinct'),
            'picCriterionIds.*.exists' => __('administration.validation.exists'),
            'reviewerCriterionIds.array' => __('administration.validation.array'),
            'reviewerCriterionIds.*.integer' => __('administration.validation.integer'),
            'reviewerCriterionIds.*.distinct' => __('administration.validation.distinct'),
            'reviewerCriterionIds.*.exists' => __('administration.validation.exists'),
            'resetPassword.required' => __('administration.validation.required'),
            'resetPassword.string' => __('administration.validation.string'),
            'resetPassword.confirmed' => __('administration.validation.confirmed'),
            'resetPassword.min' => __('administration.validation.min_string'),
            'resetPassword.regex' => __('administration.validation.password_format'),
        ];
    }

    protected function validationAttributes(): array
    {
        return __('administration.users.validation.attributes');
    }

    private function authorizedUser(int $userId, string $ability): User
    {
        $user = User::query()->with('roles')->findOrFail($userId);
        Gate::authorize($ability, $user);

        return $user;
    }

    private function resetEditor(): void
    {
        $this->reset([
            'editingUserId',
            'name',
            'email',
            'password',
            'password_confirmation',
            'roleIds',
            'resettingPasswordUserId',
            'resetPassword',
            'resetPassword_confirmation',
        ]);
    }

    private function authorizedScopeProject(int $projectId): AccreditationProject
    {
        $project = AccreditationProject::query()->findOrFail($projectId);
        Gate::authorize('manageAssignments', $project);

        return $project;
    }

    private function preferredScopeProjectId(User $user): ?int
    {
        $membershipProjectId = AccreditationProjectMember::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('project_id')
            ->value('project_id');

        if ($membershipProjectId) {
            return (int) $membershipProjectId;
        }

        $assignment = AccreditationCriterionAssignment::query()
            ->with('criterion:id,project_id')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('criterion_id')
            ->first();

        return $assignment?->criterion?->project_id
            ?? AccreditationProject::query()
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderBy('final_deadline')
                ->orderBy('id')
                ->value('id');
    }

    private function loadProjectScope(User $user, AccreditationProject $project): void
    {
        $this->membershipRoles = AccreditationProjectMember::query()
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereIn('membership_role', array_keys(self::MEMBERSHIP_ROLE_LABELS))
            ->orderBy('membership_role')
            ->pluck('membership_role')
            ->all();
        $this->picCriterionIds = $this->activeAssignmentIds($user, $project, 'pic');
        $this->reviewerCriterionIds = $this->activeAssignmentIds($user, $project, 'reviewer');
    }

    private function activeAssignmentIds(User $user, AccreditationProject $project, string $role): array
    {
        return AccreditationCriterionAssignment::query()
            ->where('user_id', $user->id)
            ->where('assignment_role', $role)
            ->where('is_active', true)
            ->whereHas('criterion', fn ($criterion) => $criterion->where('project_id', $project->id))
            ->orderBy('criterion_id')
            ->pluck('criterion_id')
            ->all();
    }

    private function resetScopeEditor(): void
    {
        $this->reset(['scopeEditorOpen', 'scopeUserId', 'scopeProjectId']);
        $this->resetScopeValues();
    }

    private function resetScopeValues(): void
    {
        $this->reset(['membershipRoles', 'picCriterionIds', 'reviewerCriterionIds']);
    }
}
