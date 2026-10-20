<?php

namespace App\Livewire\Administration\Roles;

use App\Livewire\Component;
use App\Services\UserAccessManagementService;
use App\Support\LocalizedLabel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

#[Layout('components.layouts.app')]
class Index extends Component
{
    public ?int $selectedRoleId = null;

    public array $permissionIds = [];

    public ?string $successMessage = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', Role::class);
    }

    public function selectRole(int $roleId): void
    {
        $role = Role::query()->with('permissions')->where('guard_name', 'web')->findOrFail($roleId);
        Gate::authorize('view', $role);
        $this->selectedRoleId = $role->id;
        $this->permissionIds = $role->permissions->pluck('id')->all();
        $this->successMessage = null;
        $this->resetValidation();
    }

    public function savePermissions(UserAccessManagementService $access): void
    {
        abort_unless($this->selectedRoleId !== null, 404);
        $role = Role::query()->where('guard_name', 'web')->findOrFail($this->selectedRoleId);
        Gate::authorize('update', $role);
        $this->validate([
            'permissionIds' => ['array'],
            'permissionIds.*' => [
                'integer',
                Rule::exists('permissions', 'id')->where('guard_name', 'web'),
            ],
        ]);
        $updated = $access->syncRolePermissions(Auth::user(), $role, $this->permissionIds);
        $this->successMessage = __('administration.roles.notifications.updated', [
            'role' => LocalizedLabel::role($updated->name),
        ]);
        $this->permissionIds = $updated->permissions->pluck('id')->all();
    }

    public function render()
    {
        Gate::authorize('viewAny', Role::class);

        return view('livewire.administration.roles.index', [
            'roles' => Role::query()
                ->with('permissions:id,name,guard_name')
                ->withCount('users')
                ->where('guard_name', 'web')
                ->orderBy('name')
                ->get(),
            'permissions' => Permission::query()
                ->where('guard_name', 'web')
                ->orderBy('name')
                ->get()
                ->groupBy(fn (Permission $permission): string => str($permission->name)->before('.')->toString()),
            'selectedRole' => $this->selectedRoleId
                ? Role::query()->find($this->selectedRoleId)
                : null,
        ])->title(__('administration.roles.meta_title'));
    }

    protected function messages(): array
    {
        return [
            'permissionIds.array' => __('administration.validation.array'),
            'permissionIds.*.integer' => __('administration.validation.integer'),
            'permissionIds.*.exists' => __('administration.validation.exists'),
        ];
    }

    protected function validationAttributes(): array
    {
        return __('administration.roles.validation.attributes');
    }
}
