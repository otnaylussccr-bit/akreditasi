<?php

namespace Tests\Feature;

use App\Livewire\Administration\Roles\Index as RoleManagementIndex;
use App\Livewire\Administration\Users\Index as UserManagementIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Profile\ChangePassword;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationCriterionAssignment;
use App\Models\AccreditationProject;
use App\Models\AdministrativeAuditLog;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\AccreditationAuthorizationService;
use App\Services\UserAccessManagementService;
use App\Support\PasswordPolicy;
use Database\Seeders\AccreditationRolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PhaseFourAUserAccessManagementTest extends TestCase
{
    protected string $testLocale = 'id';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
    }

    public function test_inactive_account_cannot_login_and_existing_session_is_terminated(): void
    {
        $user = $this->userWithRole('Viewer', false);

        Livewire::test(Login::class)
            ->set('email', strtoupper($user->email))
            ->set('password', 'ValidPassword!123')
            ->call('login')
            ->assertHasErrors(['email'])
            ->assertSee('Email atau password tidak valid.');
        $this->assertGuest();

        $this->withSession([config('localization.session_key') => 'id'])
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('auth.inactive'))
            ->assertSessionHas(config('localization.session_key'), 'id');
        $this->assertGuest();
    }

    public function test_existing_password_can_still_login_without_forced_reset(): void
    {
        $user = $this->userWithRole('Viewer');
        $existingHash = $user->password;

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'ValidPassword!123')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame($existingHash, $user->fresh()->password);
    }

    public function test_reusable_password_policy_enforces_only_length_letters_numbers_and_confirmation(): void
    {
        $this->assertFalse(Validator::make([
            'password' => 'abc12345',
            'password_confirmation' => 'abc12345',
        ], ['password' => PasswordPolicy::rules()])->fails());

        foreach (['12345678', 'abcdefgh', 'abc1234'] as $invalidPassword) {
            $this->assertTrue(Validator::make([
                'password' => $invalidPassword,
                'password_confirmation' => $invalidPassword,
            ], ['password' => PasswordPolicy::rules()])->fails(), "{$invalidPassword} seharusnya ditolak.");
        }

        $this->assertTrue(Validator::make([
            'password' => 'abc12345',
            'password_confirmation' => 'abc12346',
        ], ['password' => PasswordPolicy::rules()])->fails());
    }

    public function test_password_form_is_server_empty_and_uses_standard_autofill_metadata(): void
    {
        $this->get(route('profile.password'))->assertRedirect(route('login'));

        $user = $this->userWithRole('Viewer');
        $this->actingAs($user);

        $component = Livewire::test(ChangePassword::class)
            ->assertSet('currentPassword', '')
            ->assertSet('newPassword', '')
            ->assertSet('newPassword_confirmation', '')
            ->assertSeeHtml('name="username"')
            ->assertSeeHtml('autocomplete="username"')
            ->assertSeeHtml('autocomplete="current-password"')
            ->assertSeeHtml('autocomplete="new-password"');

        $html = $component->html();

        foreach (['current-password', 'new-password', 'new-password-confirmation'] as $id) {
            $this->assertDoesNotMatchRegularExpression('/<input(?=[^>]*\bid="'.preg_quote($id, '/').'"\b)[^>]*\bvalue\s*=/i', $html);
        }

        $this->assertStringNotContainsString($user->password, $html);
        $this->assertStringNotContainsString('ValidPassword!123', $html);
        $middleware = Route::getRoutes()->getByName('profile.password')->gatherMiddleware();
        $this->assertContains('auth', $middleware);
        $this->assertContains('active', $middleware);
    }

    public function test_authenticated_user_can_change_password_and_other_sessions_are_revoked(): void
    {
        $user = $this->userWithRole('Viewer');
        $oldHash = $user->password;
        $this->actingAs($user);
        $currentSessionId = session()->getId();
        $this->sessionRecord($currentSessionId, $user);
        $this->sessionRecord('other-session', $user);
        config([
            'session.driver' => 'database',
            'session.connection' => config('database.default'),
        ]);

        $component = Livewire::test(ChangePassword::class)
            ->set('currentPassword', 'ValidPassword!123')
            ->set('newPassword', 'abc12345')
            ->set('newPassword_confirmation', 'abc12345')
            ->call('updatePassword')
            ->assertHasNoErrors()
            ->assertSee('Password berhasil diperbarui')
            ->assertSet('currentPassword', '')
            ->assertSet('newPassword', '')
            ->assertSet('newPassword_confirmation', '');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($currentSessionId, session()->getId());
        $freshUser = $user->fresh();
        $this->assertFalse(Hash::check('ValidPassword!123', $freshUser->password));
        $this->assertTrue(Hash::check('abc12345', $freshUser->password));
        $this->assertNotSame('abc12345', $freshUser->password);
        $this->assertDatabaseMissing('sessions', ['id' => $currentSessionId]);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
        $audit = AdministrativeAuditLog::query()->sole();
        $this->assertSame('user.password_changed', $audit->event);
        $this->assertSame(['other_access_revoked' => true], $audit->new_values);
        $auditPayload = json_encode([
            $audit->old_values,
            $audit->new_values,
        ]);
        $this->assertStringNotContainsString('password', strtolower($auditPayload));

        foreach (['ValidPassword!123', 'abc12345', $oldHash, $freshUser->password] as $secret) {
            $this->assertStringNotContainsString($secret, $auditPayload);
        }

        $component
            ->set('currentPassword', 'WrongPassword!123')
            ->set('newPassword', 'next12345')
            ->set('newPassword_confirmation', 'next12345')
            ->call('updatePassword')
            ->assertHasErrors(['currentPassword'])
            ->assertSet('successMessage', null)
            ->assertSet('currentPassword', '')
            ->assertSet('newPassword', '')
            ->assertSet('newPassword_confirmation', '');

        Auth::logout();
        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'ValidPassword!123')
            ->call('login')
            ->assertHasErrors(['email']);
        $this->assertGuest();

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'abc12345')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_password_change_rejects_wrong_weak_unconfirmed_and_unchanged_passwords(): void
    {
        $user = $this->userWithRole('PIC Kriteria');
        $this->actingAs($user);

        $wrongCurrent = Livewire::test(ChangePassword::class)
            ->set('currentPassword', 'WrongPassword!123')
            ->set('newPassword', 'abc12345')
            ->set('newPassword_confirmation', 'abc12345')
            ->call('updatePassword')
            ->assertHasErrors(['currentPassword'])
            ->assertSet('currentPassword', '')
            ->assertSet('newPassword', '')
            ->assertSet('newPassword_confirmation', '');

        Livewire::test(ChangePassword::class)
            ->set('currentPassword', 'ValidPassword!123')
            ->set('newPassword', 'abc1234')
            ->set('newPassword_confirmation', 'abc1234')
            ->call('updatePassword')
            ->assertHasErrors(['newPassword'])
            ->assertSet('currentPassword', '')
            ->assertSet('newPassword', '')
            ->assertSet('newPassword_confirmation', '');

        Livewire::test(ChangePassword::class)
            ->set('currentPassword', 'ValidPassword!123')
            ->set('newPassword', 'abc12345')
            ->set('newPassword_confirmation', 'abc12346')
            ->call('updatePassword')
            ->assertHasErrors(['newPassword', 'newPassword_confirmation'])
            ->assertSet('currentPassword', '')
            ->assertSet('newPassword', '')
            ->assertSet('newPassword_confirmation', '');

        Livewire::test(ChangePassword::class)
            ->set('currentPassword', 'ValidPassword!123')
            ->set('newPassword', 'ValidPassword!123')
            ->set('newPassword_confirmation', 'ValidPassword!123')
            ->call('updatePassword')
            ->assertHasErrors(['newPassword'])
            ->assertSet('currentPassword', '')
            ->assertSet('newPassword', '')
            ->assertSet('newPassword_confirmation', '');

        $this->assertTrue(Hash::check('ValidPassword!123', $user->fresh()->password));
        $this->assertDatabaseCount('administrative_audit_logs', 0);
        $this->assertStringNotContainsString('WrongPassword!123', $wrongCurrent->html());
        $this->assertStringNotContainsString($user->password, $wrongCurrent->html());
    }

    public function test_password_change_is_rate_limited_without_retaining_submitted_secrets(): void
    {
        $user = $this->userWithRole('Reviewer');
        $this->actingAs($user);
        $component = Livewire::test(ChangePassword::class);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $component
                ->set('currentPassword', 'WrongPassword!123')
                ->set('newPassword', 'abc12345')
                ->set('newPassword_confirmation', 'abc12345')
                ->call('updatePassword')
                ->assertHasErrors(['currentPassword'])
                ->assertSet('currentPassword', '')
                ->assertSet('newPassword', '')
                ->assertSet('newPassword_confirmation', '');
        }

        $component
            ->set('currentPassword', 'WrongPassword!123')
            ->set('newPassword', 'abc12345')
            ->set('newPassword_confirmation', 'abc12345')
            ->call('updatePassword')
            ->assertHasErrors(['currentPassword'])
            ->assertSee('Terlalu banyak percobaan')
            ->assertSet('currentPassword', '')
            ->assertSet('newPassword', '')
            ->assertSet('newPassword_confirmation', '');

        $this->assertTrue(Hash::check('ValidPassword!123', $user->fresh()->password));
        $this->assertDatabaseCount('administrative_audit_logs', 0);
        $this->assertStringNotContainsString('WrongPassword!123', $component->html());
    }

    public function test_password_change_service_can_only_update_the_authenticated_account(): void
    {
        $user = $this->userWithRole('Viewer');
        $otherUser = $this->userWithRole('PIC Kriteria');
        $otherHash = $otherUser->password;
        $this->actingAs($user);

        app(UserAccessManagementService::class)->changeOwnPassword(
            'ValidPassword!123',
            'abc12345',
            session()->getId(),
        );

        $this->assertTrue(Hash::check('abc12345', $user->fresh()->password));
        $this->assertSame($otherHash, $otherUser->fresh()->password);
    }

    public function test_only_active_super_admin_with_permission_can_open_management_pages(): void
    {
        foreach (['Viewer', 'PIC Kriteria', 'Reviewer'] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)
                ->get(route('administration.users.index'))
                ->assertForbidden();
            $this->get(route('administration.roles.index'))->assertForbidden();
        }

        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin)
            ->get(route('administration.users.index'))
            ->assertOk()
            ->assertSee('Manajemen Pengguna')
            ->assertSee(route('administration.roles.index'), false);
        $this->get(route('administration.roles.index'))
            ->assertOk()
            ->assertSee('Role &amp; Permission', false);

        Role::findByName('Super Admin', 'web')->revokePermissionTo('user.manage');
        $this->get(route('administration.users.index'))->assertForbidden();
    }

    public function test_super_admin_can_create_user_with_existing_role_and_safe_audit(): void
    {
        $admin = $this->userWithRole('Super Admin');
        $viewerRole = Role::findByName('Viewer', 'web');
        $this->actingAs($admin);

        Livewire::test(UserManagementIndex::class)
            ->call('startCreate')
            ->assertSet('editorOpen', true)
            ->set('name', 'Pengguna Baru')
            ->set('email', 'NEW.USER@EXAMPLE.TEST')
            ->set('password', 'abc12345')
            ->set('password_confirmation', 'abc12345')
            ->set('roleIds', [$viewerRole->id])
            ->call('saveUser')
            ->assertHasNoErrors()
            ->assertSet('password', '')
            ->assertSet('password_confirmation', '')
            ->assertSee('berhasil dibuat');

        $created = User::query()->where('email', 'new.user@example.test')->sole();
        $this->assertTrue($created->is_active);
        $this->assertTrue($created->hasExactRoles('Viewer'));
        $this->assertTrue(Hash::check('abc12345', $created->password));
        $audit = AdministrativeAuditLog::query()->where('event', 'user.created')->sole();
        $this->assertSame(['Viewer'], $audit->new_values['roles']);
        $this->assertArrayNotHasKey('password', $audit->new_values);
        $this->assertArrayNotHasKey('remember_token', $audit->new_values);
    }

    public function test_user_role_changes_preserve_project_membership_and_criterion_assignment(): void
    {
        [$project, $criterion] = $this->projectContext();
        $admin = $this->userWithRole('Super Admin');
        $pic = $this->userWithRole('PIC Kriteria');
        $assignment = AccreditationCriterionAssignment::create([
            'criterion_id' => $criterion->id,
            'user_id' => $pic->id,
            'assignment_role' => 'pic',
            'is_active' => true,
        ]);
        $viewerRole = Role::findByName('Viewer', 'web');
        $picRole = Role::findByName('PIC Kriteria', 'web');
        $this->actingAs($admin);

        Livewire::test(UserManagementIndex::class)
            ->call('startEdit', $pic->id)
            ->set('roleIds', [$picRole->id, $viewerRole->id])
            ->call('saveRoles')
            ->assertHasNoErrors()
            ->assertSee('tanpa mengubah scope Phase 1 atau histori assignment LED');

        $this->assertTrue($pic->fresh()->hasAllRoles(['PIC Kriteria', 'Viewer']));
        $this->assertDatabaseHas('accreditation_criterion_assignments', [
            'id' => $assignment->id,
            'criterion_id' => $criterion->id,
            'user_id' => $pic->id,
            'assignment_role' => 'pic',
            'is_active' => true,
        ]);
        $this->assertTrue(app(AccreditationAuthorizationService::class)
            ->accessibleProjects($pic->fresh())->whereKey($project->id)->exists());
    }

    public function test_deactivation_revokes_sessions_but_preserves_assignments_and_can_be_reversed(): void
    {
        [, $criterion] = $this->projectContext();
        $admin = $this->userWithRole('Super Admin');
        $reviewer = $this->userWithRole('Reviewer');
        $assignment = AccreditationCriterionAssignment::create([
            'criterion_id' => $criterion->id,
            'user_id' => $reviewer->id,
            'assignment_role' => 'reviewer',
            'is_active' => true,
        ]);
        $this->sessionRecord('reviewer-session', $reviewer);
        $this->actingAs($admin);

        $component = Livewire::test(UserManagementIndex::class)
            ->call('toggleActive', $reviewer->id)
            ->assertHasNoErrors()
            ->assertSee('dinonaktifkan');

        $this->assertFalse($reviewer->fresh()->is_active);
        $this->assertDatabaseMissing('sessions', ['id' => 'reviewer-session']);
        $this->assertDatabaseHas('accreditation_criterion_assignments', [
            'id' => $assignment->id,
            'is_active' => true,
        ]);

        $component->call('toggleActive', $reviewer->id)->assertSee('diaktifkan');
        $this->assertTrue($reviewer->fresh()->is_active);
        $this->assertDatabaseHas('administrative_audit_logs', ['event' => 'user.deactivated']);
        $this->assertDatabaseHas('administrative_audit_logs', ['event' => 'user.activated']);
    }

    public function test_final_super_admin_cannot_disable_or_remove_own_privilege(): void
    {
        $admin = $this->userWithRole('Super Admin');
        $viewerRole = Role::findByName('Viewer', 'web');
        $this->actingAs($admin);

        Livewire::test(UserManagementIndex::class)
            ->call('toggleActive', $admin->id)
            ->assertForbidden();
        $this->assertTrue($admin->fresh()->is_active);

        Livewire::test(UserManagementIndex::class)
            ->call('startEdit', $admin->id)
            ->set('roleIds', [$viewerRole->id])
            ->call('saveRoles')
            ->assertHasErrors(['roleIds']);

        $this->assertTrue($admin->fresh()->hasRole('Super Admin'));
        $this->assertSame(1, User::role('Super Admin')->where('is_active', true)->count());
    }

    public function test_admin_password_reset_is_rate_limited_safe_and_revokes_target_sessions(): void
    {
        $admin = $this->userWithRole('Super Admin');
        $target = $this->userWithRole('Viewer');
        $this->sessionRecord('target-session', $target);
        $this->actingAs($admin);

        Livewire::test(UserManagementIndex::class)
            ->call('startPasswordReset', $target->id)
            ->set('resetPassword', 'abc12345')
            ->set('resetPassword_confirmation', 'abc12345')
            ->call('performPasswordReset')
            ->assertHasNoErrors()
            ->assertSet('resetPassword', '')
            ->assertSet('resetPassword_confirmation', '')
            ->assertSee('berhasil direset');

        $this->assertTrue(Hash::check('abc12345', $target->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $audit = AdministrativeAuditLog::query()->where('event', 'user.password_reset')->sole();
        $this->assertSame(['authenticated_access_revoked' => true], $audit->new_values);
        $this->assertStringNotContainsString('abc12345', json_encode($audit->getAttributes()));
    }

    public function test_admin_user_forms_clear_password_secrets_after_validation_failures(): void
    {
        $admin = $this->userWithRole('Super Admin');
        $target = $this->userWithRole('Viewer');
        $viewerRole = Role::findByName('Viewer', 'web');
        $this->actingAs($admin);

        Livewire::test(UserManagementIndex::class)
            ->call('startCreate')
            ->set('name', 'Nama Tetap Tersimpan')
            ->set('email', 'invalid-email')
            ->set('password', 'abc12345')
            ->set('password_confirmation', 'abc12345')
            ->set('roleIds', [$viewerRole->id])
            ->call('saveUser')
            ->assertHasErrors(['email'])
            ->assertSet('name', 'Nama Tetap Tersimpan')
            ->assertSet('email', 'invalid-email')
            ->assertSet('roleIds', [$viewerRole->id])
            ->assertSet('editorOpen', true)
            ->assertSet('password', '')
            ->assertSet('password_confirmation', '');

        Livewire::test(UserManagementIndex::class)
            ->call('startPasswordReset', $target->id)
            ->set('resetPassword', 'short1')
            ->set('resetPassword_confirmation', 'short1')
            ->call('performPasswordReset')
            ->assertHasErrors(['resetPassword'])
            ->assertSet('resettingPasswordUserId', $target->id)
            ->assertSet('resetPassword', '')
            ->assertSet('resetPassword_confirmation', '');
    }

    public function test_admin_password_reset_clears_secrets_after_domain_validation_failure(): void
    {
        $admin = $this->userWithRole('Super Admin');
        $target = $this->userWithRole('Viewer');
        $this->actingAs($admin);

        Livewire::test(UserManagementIndex::class)
            ->call('startPasswordReset', $target->id)
            ->set('resetPassword', 'ValidPassword!123')
            ->set('resetPassword_confirmation', 'ValidPassword!123')
            ->call('performPasswordReset')
            ->assertHasErrors(['resetPassword'])
            ->assertSet('resettingPasswordUserId', $target->id)
            ->assertSet('resetPassword', '')
            ->assertSet('resetPassword_confirmation', '');

        $this->assertTrue(Hash::check('ValidPassword!123', $target->fresh()->password));
        $this->assertDatabaseMissing('administrative_audit_logs', ['event' => 'user.password_reset']);
    }

    public function test_role_management_protects_super_admin_and_reserved_permissions(): void
    {
        $admin = $this->userWithRole('Super Admin');
        $managerRole = Role::findByName('Project Manager', 'web');
        $superAdminRole = Role::findByName('Super Admin', 'web');
        $dashboardPermission = Permission::findByName('dashboard.view', 'web');
        $reservedPermission = Permission::findByName('user.manage', 'web');
        $this->actingAs($admin);

        Livewire::test(RoleManagementIndex::class)
            ->call('selectRole', $managerRole->id)
            ->set('permissionIds', [$dashboardPermission->id, $reservedPermission->id])
            ->call('savePermissions')
            ->assertHasErrors(['permissionIds']);
        $this->assertFalse($managerRole->fresh()->hasPermissionTo('user.manage'));

        Livewire::test(RoleManagementIndex::class)
            ->call('selectRole', $managerRole->id)
            ->set('permissionIds', [$dashboardPermission->id])
            ->call('savePermissions')
            ->assertHasNoErrors()
            ->assertSee('berhasil diperbarui');
        $this->assertSame(['dashboard.view'], $managerRole->fresh()->permissions->pluck('name')->all());

        Livewire::test(RoleManagementIndex::class)
            ->call('selectRole', $superAdminRole->id)
            ->call('savePermissions')
            ->assertForbidden();
        $this->assertTrue($superAdminRole->fresh()->hasPermissionTo('user.manage'));
    }

    public function test_direct_service_calls_cannot_escalate_an_ordinary_user(): void
    {
        $viewer = $this->userWithRole('Viewer');
        $target = $this->userWithRole('PIC Kriteria');

        $this->expectException(AuthorizationException::class);
        app(UserAccessManagementService::class)->syncUserRoles(
            $viewer,
            $target,
            [Role::findByName('Super Admin', 'web')->id],
        );
    }

    private function userWithRole(string $role, bool $active = true): User
    {
        $user = User::create([
            'name' => $role.' '.Str::random(5),
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('ValidPassword!123'),
            'is_active' => $active,
            'deactivated_at' => $active ? null : now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function sessionRecord(string $id, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'test-payload',
            'last_activity' => now()->timestamp,
        ]);
    }

    private function projectContext(): array
    {
        $suffix = strtoupper(Str::random(6));
        $program = StudyProgram::create([
            'code' => 'PRODI-'.$suffix,
            'name' => 'Program Studi '.$suffix,
            'is_active' => true,
        ]);
        $project = AccreditationProject::create([
            'code' => 'PROJECT-'.$suffix,
            'name' => 'Akreditasi '.$suffix,
            'study_program_id' => $program->id,
            'final_deadline' => '2026-10-30',
            'status' => 'active',
        ]);
        $criterion = AccreditationCriterion::create([
            'project_id' => $project->id,
            'code' => 'A',
            'name' => 'Kriteria A',
            'color_header' => '#173E70',
            'color_body' => '#E7EEF8',
            'position' => 1,
        ]);

        return [$project, $criterion];
    }
}
