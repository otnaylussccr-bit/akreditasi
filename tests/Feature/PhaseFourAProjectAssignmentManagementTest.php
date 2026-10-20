<?php

namespace Tests\Feature;

use App\Livewire\Administration\Users\Index as UserManagementIndex;
use App\Livewire\Dashboard;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationItem;
use App\Models\AccreditationProject;
use App\Models\AccreditationProjectMember;
use App\Models\AccreditationReviewSubmission;
use App\Models\AdministrativeAuditLog;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\AccreditationAuthorizationService;
use App\Services\AccreditationProjectContextService;
use App\Services\UserAccessManagementService;
use Database\Seeders\AccreditationRolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PhaseFourAProjectAssignmentManagementTest extends TestCase
{
    protected string $testLocale = 'id';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
    }

    public function test_created_viewer_gets_a_to_f_only_after_audited_project_assignment(): void
    {
        [$project, $criteria] = $this->projectWithCriteria('APU-BIOTEK-2026', range('A', 'F'));
        $admin = $this->userWithRole('Super Admin');
        $viewer = $this->createManagedUser($admin, 'TV Dashboard', 'dashboard@akreditasi.apu', 'Viewer');

        $this->assertDatabaseMissing('accreditation_project_members', [
            'project_id' => $project->id,
            'user_id' => $viewer->id,
        ]);
        $this->actingAs($viewer);
        Livewire::test(Dashboard::class)
            ->assertSee('Belum ada proyek yang dapat diakses');

        $this->actingAs($admin);
        session()->put(AccreditationProjectContextService::SESSION_KEY, 987654);
        Livewire::test(UserManagementIndex::class)
            ->call('startScopeEdit', $viewer->id)
            ->set('scopeProjectId', $project->id)
            ->set('membershipRoles', ['viewer'])
            ->call('saveProjectScope')
            ->assertHasNoErrors()
            ->assertSee('berhasil diperbarui');

        $this->assertSame(987654, session(AccreditationProjectContextService::SESSION_KEY));
        $this->assertDatabaseHas('accreditation_project_members', [
            'project_id' => $project->id,
            'user_id' => $viewer->id,
            'membership_role' => 'viewer',
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('accreditation_criterion_assignments', ['user_id' => $viewer->id]);
        $audit = AdministrativeAuditLog::query()->where('event', 'user.project_scope_updated')->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($viewer->id, $audit->subject_id);
        $this->assertSame(['viewer'], $audit->new_values['membership_roles']);
        $this->assertSame([], $audit->new_values['pic_criterion_ids']);
        $this->assertSame([], $audit->new_values['reviewer_criterion_ids']);

        $this->actingAs($viewer);
        $dashboard = Livewire::test(Dashboard::class)
            ->assertSee('wire:poll.30s.visible="refreshDashboard"', false)
            ->assertDontSee('Belum ada proyek yang dapat diakses');
        $this->assertSame(array_keys($criteria), $dashboard->viewData('criteriaProgress')->pluck('criterion.code')->all());
        $this->get(route('documents.index'))->assertForbidden();

        $this->actingAs($admin);
        Livewire::test(UserManagementIndex::class)
            ->call('startScopeEdit', $viewer->id)
            ->set('scopeProjectId', $project->id)
            ->set('membershipRoles', [])
            ->call('saveProjectScope')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('accreditation_project_members', [
            'project_id' => $project->id,
            'user_id' => $viewer->id,
            'membership_role' => 'viewer',
            'is_active' => false,
        ]);
        $this->actingAs($viewer);
        Livewire::test(Dashboard::class)->assertSee('Belum ada proyek yang dapat diakses');

        $this->actingAs($admin);
        Livewire::test(UserManagementIndex::class)
            ->call('startScopeEdit', $viewer->id)
            ->set('scopeProjectId', $project->id)
            ->set('membershipRoles', ['viewer'])
            ->call('saveProjectScope')
            ->assertHasNoErrors();
        $this->assertSame(1, AccreditationProjectMember::query()
            ->where('project_id', $project->id)
            ->where('user_id', $viewer->id)
            ->where('membership_role', 'viewer')
            ->count());
        $this->assertTrue((bool) AccreditationProjectMember::query()
            ->where('project_id', $project->id)
            ->where('user_id', $viewer->id)
            ->where('membership_role', 'viewer')
            ->value('is_active'));
    }

    public function test_pic_scope_is_assignment_limited_and_historical_project_remains_read_only(): void
    {
        [$project, $criteria, $requirements] = $this->projectWithCriteria('PIC-SCOPE', ['A', 'B']);
        [$historicalProject, $historicalCriteria] = $this->projectWithCriteria('PIC-HISTORY', ['C'], 'archived');
        $admin = $this->userWithRole('Super Admin');
        $pic = $this->createManagedUser($admin, 'PIC Baru', 'pic.baru@example.test', 'PIC Kriteria');
        $unassignedPic = $this->createManagedUser($admin, 'PIC Tanpa Scope', 'pic.empty@example.test', 'PIC Kriteria');
        $this->actingAs($admin);

        Livewire::test(UserManagementIndex::class)
            ->call('startScopeEdit', $pic->id)
            ->set('scopeProjectId', $project->id)
            ->set('membershipRoles', ['pic'])
            ->set('picCriterionIds', [$criteria['A']->id])
            ->call('saveProjectScope')
            ->assertHasNoErrors();
        Livewire::test(UserManagementIndex::class)
            ->call('startScopeEdit', $pic->id)
            ->set('scopeProjectId', $historicalProject->id)
            ->set('membershipRoles', ['pic'])
            ->set('picCriterionIds', [$historicalCriteria['C']->id])
            ->call('saveProjectScope')
            ->assertHasNoErrors();

        $authorization = app(AccreditationAuthorizationService::class);
        $this->assertTrue($authorization->isActivePic($pic, $criteria['A']));
        $this->assertFalse($authorization->isActivePic($pic, $criteria['B']));
        $this->assertTrue(Gate::forUser($pic)->allows('upload', $requirements['A']));
        $this->assertFalse(Gate::forUser($pic)->allows('upload', $requirements['B']));
        $this->assertFalse($authorization->canUploadForCriterion($pic, $historicalCriteria['C']));
        $this->assertSame(['A'], $authorization->accessibleCriteria($pic, $project)->pluck('code')->all());
        $this->assertFalse($authorization->accessibleProjects($unassignedPic)->exists());
        $this->assertDatabaseHas('accreditation_project_members', [
            'project_id' => $project->id,
            'user_id' => $pic->id,
            'membership_role' => 'pic',
            'is_active' => true,
        ]);

        $this->actingAs($admin);
        Livewire::test(UserManagementIndex::class)
            ->call('startScopeEdit', $pic->id)
            ->set('scopeProjectId', $project->id)
            ->set('membershipRoles', ['pic'])
            ->set('picCriterionIds', [])
            ->call('saveProjectScope')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('accreditation_criterion_assignments', [
            'criterion_id' => $criteria['A']->id,
            'user_id' => $pic->id,
            'assignment_role' => 'pic',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('accreditation_criterion_assignments', [
            'criterion_id' => $historicalCriteria['C']->id,
            'user_id' => $pic->id,
            'assignment_role' => 'pic',
            'is_active' => true,
        ]);
        $this->assertFalse($authorization->canViewProject($pic, $project));
    }

    public function test_reviewer_scope_is_criterion_limited_and_tampering_is_atomic(): void
    {
        [$project, $criteria, $requirements] = $this->projectWithCriteria('REVIEW-SCOPE', ['A', 'B']);
        [, $outsideCriteria] = $this->projectWithCriteria('REVIEW-OUTSIDE', ['C']);
        $admin = $this->userWithRole('Super Admin');
        $reviewer = $this->createManagedUser($admin, 'Reviewer Baru', 'reviewer.baru@example.test', 'Reviewer');
        $unassignedReviewer = $this->createManagedUser($admin, 'Reviewer Tanpa Scope', 'reviewer.empty@example.test', 'Reviewer');
        $submitter = $this->userWithRole('PIC Kriteria');
        $submissionA = $this->submission($requirements['A'], $submitter);
        $submissionB = $this->submission($requirements['B'], $submitter);
        $this->actingAs($admin);

        Livewire::test(UserManagementIndex::class)
            ->call('startScopeEdit', $reviewer->id)
            ->set('scopeProjectId', $project->id)
            ->set('membershipRoles', ['reviewer'])
            ->set('reviewerCriterionIds', [$criteria['B']->id])
            ->call('saveProjectScope')
            ->assertHasNoErrors();

        $authorization = app(AccreditationAuthorizationService::class);
        $this->assertFalse($authorization->canReviewSubmission($reviewer, $submissionA));
        $this->assertTrue($authorization->canReviewSubmission($reviewer, $submissionB));
        $this->assertSame(['B'], $authorization->accessibleCriteria($reviewer, $project)->pluck('code')->all());
        $this->assertFalse($authorization->accessibleProjects($unassignedReviewer)->exists());
        $this->assertFalse(Gate::forUser($reviewer)->allows('upload', $requirements['B']));

        $auditCount = AdministrativeAuditLog::query()->where('event', 'user.project_scope_updated')->count();
        Livewire::test(UserManagementIndex::class)
            ->call('startScopeEdit', $reviewer->id)
            ->set('scopeProjectId', $project->id)
            ->set('reviewerCriterionIds', [$outsideCriteria['C']->id])
            ->call('saveProjectScope')
            ->assertHasErrors(['reviewerCriterionIds.0']);

        $this->assertDatabaseMissing('accreditation_criterion_assignments', [
            'criterion_id' => $outsideCriteria['C']->id,
            'user_id' => $reviewer->id,
        ]);
        $this->assertSame($auditCount, AdministrativeAuditLog::query()->where('event', 'user.project_scope_updated')->count());

        $viewer = $this->createManagedUser($admin, 'Viewer Scope Guard', 'viewer.guard@example.test', 'Viewer');
        $this->actingAs($admin);
        Livewire::test(UserManagementIndex::class)
            ->call('startScopeEdit', $viewer->id)
            ->set('scopeProjectId', $project->id)
            ->set('picCriterionIds', [$criteria['A']->id])
            ->call('saveProjectScope')
            ->assertHasErrors(['picCriterionIds']);
        $this->assertDatabaseMissing('accreditation_criterion_assignments', ['user_id' => $viewer->id]);

        $this->assertCount(2, $authorization->accessibleProjects($admin)->get());
    }

    public function test_direct_scope_management_by_ordinary_user_is_forbidden(): void
    {
        [$project, $criteria] = $this->projectWithCriteria('DIRECT-GUARD', ['A']);
        $actor = $this->userWithRole('Viewer');
        $target = $this->userWithRole('PIC Kriteria');

        $this->expectException(AuthorizationException::class);
        app(UserAccessManagementService::class)->syncUserProjectScope(
            $actor,
            $target,
            $project,
            ['pic'],
            [$criteria['A']->id],
            [],
        );
    }

    private function createManagedUser(User $admin, string $name, string $email, string $role): User
    {
        $this->actingAs($admin);
        $roleId = Role::findByName($role, 'web')->id;
        Livewire::test(UserManagementIndex::class)
            ->call('startCreate')
            ->set('name', $name)
            ->set('email', $email)
            ->set('password', 'abc12345')
            ->set('password_confirmation', 'abc12345')
            ->set('roleIds', [$roleId])
            ->call('saveUser')
            ->assertHasNoErrors();

        return User::query()->where('email', $email)->sole();
    }

    private function projectWithCriteria(string $code, array $criterionCodes, string $status = 'active'): array
    {
        $program = StudyProgram::create([
            'code' => 'PS-'.Str::upper(Str::random(6)),
            'name' => 'Program Studi '.$code,
            'is_active' => true,
        ]);
        $project = AccreditationProject::create([
            'code' => $code,
            'name' => 'Akreditasi '.$code,
            'study_program_id' => $program->id,
            'final_deadline' => '2026-10-30',
            'status' => $status,
        ]);
        $criteria = [];
        $requirements = [];

        foreach ($criterionCodes as $index => $criterionCode) {
            $criterion = AccreditationCriterion::create([
                'project_id' => $project->id,
                'code' => $criterionCode,
                'name' => 'Kriteria '.$criterionCode,
                'color_header' => '#173E70',
                'color_body' => '#E7EEF8',
                'position' => $index + 1,
            ]);
            $item = AccreditationItem::create([
                'criterion_id' => $criterion->id,
                'number' => 2,
                'position' => 1,
                'title' => "Butir {$criterionCode}.2",
            ]);
            $requirement = AccreditationDocumentRequirement::create([
                'item_id' => $item->id,
                'number' => 1,
                'title' => "Rincian {$criterionCode}.2.1",
                'is_required' => true,
                'workflow_status' => 'draft',
                'current_review_revision' => 0,
            ]);
            $criteria[$criterionCode] = $criterion;
            $requirements[$criterionCode] = $requirement;
        }

        return [$project, $criteria, $requirements];
    }

    private function submission(AccreditationDocumentRequirement $requirement, User $submitter): AccreditationReviewSubmission
    {
        return AccreditationReviewSubmission::create([
            'requirement_id' => $requirement->id,
            'submitted_by' => $submitter->id,
            'revision_number' => 1,
            'status' => 'pending_review',
            'submitted_at' => now(),
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => $role.' '.Str::random(5),
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('ValidPassword!123'),
        ]);
        $user->assignRole($role);

        return $user;
    }
}
