<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationCriterionAssignment;
use App\Models\AccreditationDocument;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationItem;
use App\Models\AccreditationProject;
use App\Models\AccreditationProjectMember;
use App\Models\AccreditationReviewSubmission;
use App\Models\StudyProgram;
use App\Models\User;
use Database\Seeders\AccreditationRolePermissionSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PhaseTwoTest extends TestCase
{
    protected string $testLocale = 'id';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
    }

    public function test_valid_login_redirects_to_dashboard(): void
    {
        $user = User::create([
            'name' => 'User Test',
            'email' => 'valid@example.test',
            'password' => Hash::make('ValidPassword!123'),
        ]);
        $user->assignRole('Viewer');

        Livewire::test(Login::class)
            ->set('email', 'VALID@example.test')
            ->set('password', 'ValidPassword!123')
            ->call('login')
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_login_uses_a_generic_error(): void
    {
        User::create([
            'name' => 'User Test',
            'email' => 'valid@example.test',
            'password' => Hash::make('ValidPassword!123'),
        ]);

        Livewire::test(Login::class)
            ->set('email', 'valid@example.test')
            ->set('password', 'WrongPassword!123')
            ->call('login')
            ->assertHasErrors(['email'])
            ->assertSee('Email atau password tidak valid.');

        $this->assertGuest();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_super_admin_can_render_dashboard(): void
    {
        $user = $this->userWithRole('Super Admin');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ringkasan Progres');
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $user = $this->userWithRole('Viewer');

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_roles_receive_function_specific_permissions(): void
    {
        $superAdmin = Role::findByName('Super Admin', 'web');
        $reviewer = Role::findByName('Reviewer', 'web');
        $viewer = Role::findByName('Viewer', 'web');

        $this->assertTrue($superAdmin->hasPermissionTo('user.manage'));
        $this->assertFalse($superAdmin->hasPermissionTo('document.verify'));
        $this->assertTrue($reviewer->hasPermissionTo('document.verify'));
        $this->assertFalse($reviewer->hasPermissionTo('document.upload'));
        $this->assertFalse($viewer->hasPermissionTo('document.view'));
        $this->assertFalse($viewer->hasPermissionTo('project.manage'));
    }

    public function test_project_manager_is_scoped_by_active_project_membership(): void
    {
        [$project, $criterion] = $this->projectWithCriterion('A');
        [$otherProject] = $this->projectWithCriterion('B');
        $manager = $this->userWithRole('Project Manager');

        AccreditationProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $manager->id,
            'membership_role' => 'project_manager',
            'is_active' => true,
        ]);

        $this->assertTrue(Gate::forUser($manager)->allows('view', $project));
        $this->assertTrue(Gate::forUser($manager)->allows('view', $criterion));
        $this->assertTrue(Gate::forUser($manager)->allows('manageAssignments', $project));
        $this->assertFalse(Gate::forUser($manager)->allows('view', $otherProject));
        $this->assertFalse(Gate::forUser($manager)->allows('manageAssignments', $otherProject));
    }

    public function test_pic_is_limited_to_active_criterion_assignments(): void
    {
        [$project, $assigned] = $this->projectWithCriterion('A');
        $unassigned = $this->criterion($project, 'B', 2);
        $pic = $this->userWithRole('PIC Kriteria');

        AccreditationCriterionAssignment::create([
            'criterion_id' => $assigned->id,
            'user_id' => $pic->id,
            'assignment_role' => 'pic',
            'is_active' => true,
        ]);

        $this->assertTrue(Gate::forUser($pic)->allows('view', $assigned));
        $this->assertTrue(Gate::forUser($pic)->allows('upload', $assigned));
        $this->assertFalse(Gate::forUser($pic)->allows('view', $unassigned));
        $this->assertFalse(Gate::forUser($pic)->allows('upload', $unassigned));
    }

    public function test_reviewer_is_limited_to_active_criterion_assignments(): void
    {
        [$project, $assigned] = $this->projectWithCriterion('A');
        $unassigned = $this->criterion($project, 'B', 2);
        $reviewer = $this->userWithRole('Reviewer');
        $submitter = $this->userWithRole('PIC Kriteria');

        AccreditationCriterionAssignment::create([
            'criterion_id' => $assigned->id,
            'user_id' => $reviewer->id,
            'assignment_role' => 'reviewer',
            'is_active' => true,
        ]);

        $assignedSubmission = $this->submission($assigned, $submitter);
        $unassignedSubmission = $this->submission($unassigned, $submitter);

        $this->assertTrue(Gate::forUser($reviewer)->allows('verify', $assignedSubmission));
        $this->assertFalse(Gate::forUser($reviewer)->allows('verify', $unassignedSubmission));
    }

    public function test_viewer_has_read_only_access_for_its_project(): void
    {
        [$project, $criterion] = $this->projectWithCriterion('A');
        $viewer = $this->userWithRole('Viewer');

        AccreditationProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $viewer->id,
            'membership_role' => 'viewer',
            'is_active' => true,
        ]);

        $this->assertTrue(Gate::forUser($viewer)->allows('view', $project));
        $this->assertTrue(Gate::forUser($viewer)->allows('view', $criterion));
        $this->assertFalse(Gate::forUser($viewer)->allows('update', $project));
        $this->assertFalse(Gate::forUser($viewer)->allows('upload', $criterion));
    }

    public function test_multiple_roles_combine_only_their_authorized_scopes(): void
    {
        [$managedProject, $managedCriterion] = $this->projectWithCriterion('A');
        [$reviewedProject, $reviewedCriterion] = $this->projectWithCriterion('B');
        [$outsideProject, $outsideCriterion] = $this->projectWithCriterion('C');
        $user = $this->userWithRole('Project Manager');
        $user->assignRole('Reviewer');

        AccreditationProjectMember::create([
            'project_id' => $managedProject->id,
            'user_id' => $user->id,
            'membership_role' => 'project_manager',
            'is_active' => true,
        ]);
        AccreditationCriterionAssignment::create([
            'criterion_id' => $reviewedCriterion->id,
            'user_id' => $user->id,
            'assignment_role' => 'reviewer',
            'is_active' => true,
        ]);

        $this->assertTrue(Gate::forUser($user)->allows('view', $managedCriterion));
        $this->assertTrue(Gate::forUser($user)->allows('view', $reviewedCriterion));
        $this->assertTrue(Gate::forUser($user)->allows('manageAssignments', $managedProject));
        $this->assertFalse(Gate::forUser($user)->allows('manageAssignments', $reviewedProject));
        $this->assertFalse(Gate::forUser($user)->allows('view', $outsideProject));
        $this->assertFalse(Gate::forUser($user)->allows('view', $outsideCriterion));
    }

    public function test_self_review_is_blocked_even_for_an_assigned_reviewer(): void
    {
        [, $criterion] = $this->projectWithCriterion('A');
        $reviewer = $this->userWithRole('Reviewer');

        AccreditationCriterionAssignment::create([
            'criterion_id' => $criterion->id,
            'user_id' => $reviewer->id,
            'assignment_role' => 'reviewer',
            'is_active' => true,
        ]);

        $submission = $this->submission($criterion, $reviewer);

        $this->assertFalse(Gate::forUser($reviewer)->allows('review', $submission));
        $this->assertFalse(Gate::forUser($reviewer)->allows('verify', $submission));
        $this->assertFalse(Gate::forUser($reviewer)->allows('requestRevision', $submission));
    }

    public function test_review_is_blocked_when_snapshot_contains_a_file_uploaded_by_the_reviewer(): void
    {
        [, $criterion] = $this->projectWithCriterion('A');
        $reviewer = $this->userWithRole('Reviewer');
        $submitter = $this->userWithRole('PIC Kriteria');

        AccreditationCriterionAssignment::create([
            'criterion_id' => $criterion->id,
            'user_id' => $reviewer->id,
            'assignment_role' => 'reviewer',
            'is_active' => true,
        ]);

        $submission = $this->submission($criterion, $submitter);
        $this->attachDocument($submission, $reviewer);

        $this->assertFalse(Gate::forUser($reviewer)->allows('review', $submission));
        $this->assertFalse(Gate::forUser($reviewer)->allows('verify', $submission));
        $this->assertFalse(Gate::forUser($reviewer)->allows('requestRevision', $submission));
    }

    public function test_reviewer_can_review_a_valid_submission_and_files_from_other_users(): void
    {
        [, $criterion] = $this->projectWithCriterion('A');
        $reviewer = $this->userWithRole('Reviewer');
        $submitter = $this->userWithRole('PIC Kriteria');

        AccreditationCriterionAssignment::create([
            'criterion_id' => $criterion->id,
            'user_id' => $reviewer->id,
            'assignment_role' => 'reviewer',
            'is_active' => true,
        ]);

        $submission = $this->submission($criterion, $submitter);
        $this->attachDocument($submission, $submitter);

        $this->assertTrue(Gate::forUser($reviewer)->allows('review', $submission));
        $this->assertTrue(Gate::forUser($reviewer)->allows('verify', $submission));
        $this->assertTrue(Gate::forUser($reviewer)->allows('requestRevision', $submission));
    }

    public function test_role_permission_seeder_is_idempotent(): void
    {
        $beforeRoles = Role::count();
        $beforePermissions = Permission::count();
        $beforeMappings = Role::with('permissions')->get()
            ->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions->pluck('name')->sort()->values()->all()])
            ->all();

        $this->seed(AccreditationRolePermissionSeeder::class);

        $afterMappings = Role::with('permissions')->get()
            ->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions->pluck('name')->sort()->values()->all()])
            ->all();

        $this->assertSame($beforeRoles, Role::count());
        $this->assertSame($beforePermissions, Permission::count());
        $this->assertSame($beforeMappings, $afterMappings);
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => $role.' User',
            'email' => strtolower(str_replace(' ', '.', $role)).'.'.uniqid().'@example.test',
            'password' => Hash::make('ValidPassword!123'),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function projectWithCriterion(string $suffix): array
    {
        $program = StudyProgram::create([
            'code' => 'PS-'.$suffix.'-'.uniqid(),
            'name' => 'Program '.$suffix,
            'is_active' => true,
        ]);
        $project = AccreditationProject::create([
            'code' => 'PROJECT-'.$suffix.'-'.uniqid(),
            'name' => 'Project '.$suffix,
            'study_program_id' => $program->id,
            'final_deadline' => '2026-10-30',
            'status' => 'active',
        ]);

        return [$project, $this->criterion($project, $suffix, 1)];
    }

    private function criterion(AccreditationProject $project, string $code, int $position): AccreditationCriterion
    {
        return AccreditationCriterion::create([
            'project_id' => $project->id,
            'code' => $code,
            'name' => 'Kriteria '.$code,
            'color_header' => '#123456',
            'color_body' => '#abcdef',
            'position' => $position,
        ]);
    }

    private function submission(AccreditationCriterion $criterion, User $submitter): AccreditationReviewSubmission
    {
        $item = AccreditationItem::create([
            'criterion_id' => $criterion->id,
            'number' => 1,
            'position' => 1,
            'title' => 'Butir '.$criterion->code,
        ]);
        $requirement = AccreditationDocumentRequirement::create([
            'item_id' => $item->id,
            'number' => 1,
            'title' => 'Dokumen '.$criterion->code,
            'is_required' => true,
            'workflow_status' => 'submitted',
            'current_review_revision' => 1,
        ]);

        return AccreditationReviewSubmission::create([
            'requirement_id' => $requirement->id,
            'submitted_by' => $submitter->id,
            'revision_number' => 1,
            'status' => 'pending_review',
            'submitted_at' => now(),
        ]);
    }

    private function attachDocument(AccreditationReviewSubmission $submission, User $uploader): void
    {
        $document = AccreditationDocument::create([
            'requirement_id' => $submission->requirement_id,
            'uploaded_by' => $uploader->id,
            'original_name' => 'document.pdf',
            'disk' => 'local',
            'storage_path' => 'accreditation/document.pdf',
            'storage_uuid' => (string) Str::uuid(),
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', (string) Str::uuid()),
            'revision_number' => 1,
            'is_active' => true,
            'uploaded_at' => now(),
        ]);

        $submission->documents()->attach($document->id);
    }
}
