<?php

namespace Tests\Feature;

use App\Livewire\Documents\Index;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationCriterionAssignment;
use App\Models\AccreditationDocument;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationItem;
use App\Models\AccreditationProject;
use App\Models\AccreditationProjectMember;
use App\Models\StudyProgram;
use App\Models\User;
use Database\Seeders\AccreditationRolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('documents.index'))->assertRedirect(route('login'));
    }

    public function test_input_document_menu_targets_index_and_active_state_follows_route(): void
    {
        [$project, $criterion, , $requirement] = $this->structure();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, 'pic', true);
        $this->actingAs($pic);

        $this->get(route('documents.index'))
            ->assertOk()
            ->assertSee('class="nav-link active" href="'.route('documents.index', ['proyek' => $project->id]).'"', false);

        $this->get(route('documents.upload', $requirement))
            ->assertOk()
            ->assertSee('class="nav-link active" href="'.route('documents.index', ['proyek' => $project->id]).'"', false);

        $this->get(route('follow-up-led.index'))
            ->assertOk()
            ->assertSee('class="nav-link active" href="'.route('follow-up-led.index', ['proyek' => $project->id]).'"', false)
            ->assertSee('href="'.route('documents.index', ['proyek' => $project->id]).'"', false);

        $this->assertSame($project->id, $criterion->project_id);
    }

    public function test_super_admin_sees_all_projects_and_criteria_with_emergency_upload_access(): void
    {
        [$project, $criterionA, , $requirementA] = $this->structure('ADMIN-A');
        $criterionB = $this->criterion($project, 'B', 2, 'Tata Pamong dan Kerja Sama');
        [, $requirementB] = $this->workItem($criterionB, 2, 'Dokumen Kerja Sama');
        [$otherProject] = $this->structure('ADMIN-B');
        $admin = $this->userWithRole('Super Admin');
        $document = $this->document($requirementA, $admin);
        $this->actingAs($admin);

        $component = Livewire::test(Index::class)
            ->assertSee($project->name)
            ->assertSee($otherProject->code)
            ->assertSee($criterionA->name)
            ->assertSee($criterionB->name)
            ->assertSee($requirementA->title)
            ->assertSee(route('documents.preview', $document), false)
            ->assertSee(route('documents.upload', $requirementA), false);

        $component
            ->call('selectCriterion', $criterionB->id)
            ->assertSee($requirementB->title)
            ->assertSee(route('documents.upload', $requirementB), false);
    }

    public function test_pic_only_sees_criteria_with_active_pic_assignment(): void
    {
        [$project, $criterionA, , $requirementA] = $this->structure('PIC-SCOPE');
        $criterionB = $this->criterion($project, 'B', 2, 'Kriteria B Tidak Ditugaskan');
        [, $requirementB] = $this->workItem($criterionB, 2, 'Rincian B Terlarang');
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterionA, $pic, 'pic', true);
        $this->assignment($criterionB, $pic, 'pic', false);
        $this->actingAs($pic);

        $component = Livewire::test(Index::class);

        $this->assertSame(['A'], $component->viewData('criteria')->pluck('code')->all());
        $component
            ->assertSee($requirementA->title)
            ->assertSee(route('documents.upload', $requirementA), false)
            ->assertDontSee($criterionB->name)
            ->assertDontSee($requirementB->title);
    }

    public function test_reviewer_can_see_existing_file_but_never_receives_upload_action(): void
    {
        [, $criterion, , $requirement] = $this->structure('REVIEWER');
        $reviewer = $this->userWithRole('Reviewer');
        $uploader = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $reviewer, 'reviewer', true);
        $document = $this->document($requirement, $uploader);
        $this->actingAs($reviewer);

        Livewire::test(Index::class)
            ->assertSee($requirement->title)
            ->assertSee(route('documents.preview', $document), false)
            ->assertDontSee(route('documents.upload', $requirement), false);
    }

    public function test_viewer_project_membership_is_read_only(): void
    {
        [$project, , , $requirement] = $this->structure('VIEWER');
        $viewer = $this->userWithRole('Viewer');
        $this->membership($project, $viewer, 'viewer');
        $this->actingAs($viewer);

        Livewire::test(Index::class)
            ->assertSee($requirement->title)
            ->assertSee('Read-only')
            ->assertDontSee(route('documents.upload', $requirement), false);
    }

    public function test_header_led_has_no_upload_action_while_work_item_does(): void
    {
        [$project, $criterion, , $workRequirement] = $this->structure('APU-BIOTEK-2026');
        [, $headerRequirement] = $this->workItem($criterion, 1, 'Header Visi Misi');
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, 'pic', true);
        $this->actingAs($pic);

        Livewire::test(Index::class)
            ->assertSee('Header LED tetap ditampilkan')
            ->assertDontSee(route('documents.upload', $headerRequirement), false)
            ->assertSee(route('documents.upload', $workRequirement), false);
    }

    public function test_upload_link_targets_the_exact_requirement(): void
    {
        [$project, $criterion, , $firstRequirement] = $this->structure('EXACT');
        [$secondItem, $secondRequirement] = $this->workItem($criterion, 3, 'Lampiran Tambahan');
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, 'pic', true);
        $this->actingAs($pic);

        $response = $this->get(route('documents.index'));

        $response->assertOk();
        $response->assertSee('href="'.route('documents.upload', ['requirement' => $firstRequirement, 'proyek' => $project->id]).'"', false);
        $response->assertSee('href="'.route('documents.upload', ['requirement' => $secondRequirement, 'proyek' => $project->id]).'"', false);
        $response->assertSee($secondItem->title);
    }

    public function test_manipulated_criterion_and_requirement_outside_scope_are_forbidden(): void
    {
        [$project, $criterionA] = $this->structure('GUARD');
        $criterionB = $this->criterion($project, 'B', 2, 'Kriteria B Terlarang');
        [, $outsideRequirement] = $this->workItem($criterionB, 2, 'Rincian Terlarang');
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterionA, $pic, 'pic', true);
        $this->actingAs($pic);

        Livewire::test(Index::class)
            ->call('selectCriterion', $criterionB->id)
            ->assertForbidden();

        $this->get(route('documents.index', ['kriteria' => $criterionB->id]))->assertForbidden();
        $this->get(route('documents.upload', $outsideRequirement))->assertForbidden();
    }

    private function structure(string $projectCode = 'INDEX'): array
    {
        $suffix = strtoupper(Str::random(7));
        $program = StudyProgram::create([
            'code' => 'PRODI-'.$suffix,
            'name' => 'Bioteknologi '.$suffix,
            'is_active' => true,
        ]);
        $project = AccreditationProject::create([
            'code' => $projectCode === 'APU-BIOTEK-2026' ? $projectCode : $projectCode.'-'.$suffix,
            'name' => 'Project '.$projectCode.' '.$suffix,
            'study_program_id' => $program->id,
            'final_deadline' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $criterion = $this->criterion($project, 'A', 1, 'Visi, Misi, Tujuan dan Strategi');
        [$item, $requirement] = $this->workItem($criterion, 2, 'Statuta Perguruan Tinggi');

        return [$project, $criterion, $item, $requirement];
    }

    private function criterion(
        AccreditationProject $project,
        string $code,
        int $position,
        string $name,
    ): AccreditationCriterion {
        return AccreditationCriterion::create([
            'project_id' => $project->id,
            'code' => $code,
            'name' => $name,
            'color_header' => '#173E70',
            'color_body' => '#E7EEF8',
            'position' => $position,
        ]);
    }

    private function workItem(
        AccreditationCriterion $criterion,
        int $number,
        string $title,
    ): array {
        $item = AccreditationItem::create([
            'criterion_id' => $criterion->id,
            'number' => $number,
            'position' => $number,
            'title' => $title,
        ]);
        $requirement = AccreditationDocumentRequirement::create([
            'item_id' => $item->id,
            'number' => 1,
            'title' => 'Rincian '.$title,
            'is_required' => true,
            'workflow_status' => 'not_started',
            'current_review_revision' => 0,
        ]);

        return [$item, $requirement];
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

    private function assignment(
        AccreditationCriterion $criterion,
        User $user,
        string $role,
        bool $active,
    ): void {
        AccreditationCriterionAssignment::create([
            'criterion_id' => $criterion->id,
            'user_id' => $user->id,
            'assignment_role' => $role,
            'is_active' => $active,
        ]);
    }

    private function membership(AccreditationProject $project, User $user, string $role): void
    {
        AccreditationProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'membership_role' => $role,
            'is_active' => true,
        ]);
    }

    private function document(AccreditationDocumentRequirement $requirement, User $uploader): AccreditationDocument
    {
        $uuid = (string) Str::uuid();

        return AccreditationDocument::create([
            'requirement_id' => $requirement->id,
            'uploaded_by' => $uploader->id,
            'original_name' => 'dokumen-existing.pdf',
            'disk' => 'local',
            'storage_path' => "accreditation/test/{$uuid}.pdf",
            'storage_uuid' => $uuid,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', $uuid),
            'revision_number' => 1,
            'is_active' => true,
            'uploaded_at' => now(),
        ]);
    }
}
