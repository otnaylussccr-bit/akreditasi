<?php

namespace Tests\Feature;

use App\Livewire\FollowUpLed\Index;
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

class FollowUpLedTest extends TestCase
{
    protected string $testLocale = 'id';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('follow-up-led.index'))
            ->assertRedirect(route('login'));
    }

    public function test_super_admin_sees_all_criteria_in_position_order(): void
    {
        $this->biotechnologyStructure();
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);

        $criteria = Livewire::test(Index::class)->viewData('criteria');

        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F'], $criteria->pluck('code')->all());
    }

    public function test_criterion_b_paginates_all_sixteen_items(): void
    {
        [, $criteria] = $this->biotechnologyStructure();
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);

        $component = Livewire::test(Index::class)
            ->call('selectCriterion', $criteria['B']->id);

        $items = $component->viewData('items');

        $this->assertCount(10, $items);
        $this->assertSame(16, $items->total());
        $this->assertTrue($items->hasPages());
        $component->assertSeeHtml('table-pagination');
    }

    public function test_selecting_criterion_d_only_displays_criterion_d_items(): void
    {
        [, $criteria] = $this->biotechnologyStructure();
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);

        $items = Livewire::test(Index::class)
            ->call('selectCriterion', $criteria['D']->id)
            ->viewData('items');

        $this->assertCount(5, $items);
        $this->assertTrue($items->every(fn (AccreditationItem $item) => $item->criterion_id === $criteria['D']->id));
    }

    public function test_criterion_filter_uses_database_position_not_insertion_order(): void
    {
        $project = $this->project('ORDER');
        $this->criterion($project, 'F', 3, 'Ketiga');
        $this->criterion($project, 'A', 1, 'Pertama');
        $this->criterion($project, 'D', 2, 'Kedua');
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);

        $criteria = Livewire::test(Index::class)->viewData('criteria');

        $this->assertSame(['A', 'D', 'F'], $criteria->pluck('code')->all());
    }

    public function test_pic_only_sees_active_pic_assignments(): void
    {
        [$project, $criteria] = $this->threeCriteriaProject('PIC');
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criteria['B'], $pic, 'pic', true);
        $this->assignment($criteria['C'], $pic, 'pic', false);
        $this->actingAs($pic);

        $component = Livewire::test(Index::class);

        $this->assertSame(['B'], $component->viewData('criteria')->pluck('code')->all());
        $this->assertSame($criteria['B']->id, $component->get('activeCriterionId'));
        $component->assertDontSee($criteria['A']->name)->assertDontSee($criteria['C']->name);
    }

    public function test_reviewer_only_sees_active_reviewer_assignments(): void
    {
        [, $criteria] = $this->threeCriteriaProject('REVIEWER');
        $reviewer = $this->userWithRole('Reviewer');
        $this->assignment($criteria['A'], $reviewer, 'reviewer', true);
        $this->assignment($criteria['B'], $reviewer, 'pic', true);
        $this->actingAs($reviewer);

        $criteriaData = Livewire::test(Index::class)->viewData('criteria');

        $this->assertSame(['A'], $criteriaData->pluck('code')->all());
    }

    public function test_project_manager_only_sees_project_with_active_manager_membership(): void
    {
        $outside = $this->project('PM-OUTSIDE', 'Program Luar');
        $this->criterion($outside, 'A', 1, 'Kriteria Luar');
        $assigned = $this->project('PM-ASSIGNED', 'Program Ditugaskan');
        $criterion = $this->criterion($assigned, 'A', 1, 'Kriteria Ditugaskan');
        $this->item($criterion, 1, 1, 'Butir Ditugaskan');
        $manager = $this->userWithRole('Project Manager');
        $this->membership($outside, $manager, 'viewer', true);
        $this->membership($assigned, $manager, 'project_manager', true);
        $this->actingAs($manager);

        Livewire::test(Index::class)
            ->assertSee('Program Ditugaskan')
            ->assertDontSee('Program Luar');
    }

    public function test_viewer_is_read_only_within_viewer_membership(): void
    {
        [$project, $criteria] = $this->threeCriteriaProject('VIEWER');
        $viewer = $this->userWithRole('Viewer');
        $this->membership($project, $viewer, 'viewer', true);
        $this->actingAs($viewer);

        $component = Livewire::test(Index::class);

        $this->assertSame(['A', 'B', 'C'], $component->viewData('criteria')->pluck('code')->all());
        $this->assertFalse($viewer->can('document.upload'));
        $component->assertSee($criteria['A']->name);
    }

    public function test_multiple_roles_only_combine_valid_active_scopes(): void
    {
        [, $criteria] = $this->threeCriteriaProject('MULTI');
        $user = $this->userWithRole('PIC Kriteria');
        $user->assignRole('Reviewer');
        $this->assignment($criteria['A'], $user, 'pic', true);
        $this->assignment($criteria['B'], $user, 'reviewer', true);
        $this->assignment($criteria['C'], $user, 'pic', false);
        $this->actingAs($user);

        $criteriaData = Livewire::test(Index::class)->viewData('criteria');

        $this->assertSame(['A', 'B'], $criteriaData->pluck('code')->all());
    }

    public function test_manipulated_criterion_id_is_forbidden(): void
    {
        [, $criteria] = $this->threeCriteriaProject('CRITERION-GUARD');
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criteria['A'], $pic, 'pic', true);
        $this->actingAs($pic);

        Livewire::test(Index::class)
            ->call('selectCriterion', $criteria['B']->id)
            ->assertForbidden();
    }

    public function test_manipulated_item_id_outside_active_criterion_is_forbidden(): void
    {
        [, $criteria] = $this->threeCriteriaProject('ITEM-GUARD');
        $itemB = $this->item($criteria['B'], 1, 1, 'Butir B Terlarang');
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);

        Livewire::test(Index::class)
            ->call('openDetail', $itemB->id)
            ->assertForbidden();
    }

    public function test_inactive_assignment_immediately_blocks_an_existing_component_session(): void
    {
        [, $criteria] = $this->threeCriteriaProject('DEACTIVATE');
        $pic = $this->userWithRole('PIC Kriteria');
        $assignment = $this->assignment($criteria['A'], $pic, 'pic', true);
        $this->actingAs($pic);
        $component = Livewire::test(Index::class);

        $assignment->update(['is_active' => false]);

        $component->call('$refresh')->assertForbidden();
    }

    public function test_biotechnology_headers_are_excluded_from_work_items(): void
    {
        $this->biotechnologyStructure();
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);

        $component = Livewire::test(Index::class);

        $this->assertSame(6, $component->viewData('headerCount'));
        $this->assertSame(33, $component->viewData('workItemCount'));
        $this->assertTrue($component->viewData('items')->first()->is_led_header);
    }

    public function test_biotechnology_scope_totals_are_thirty_nine_six_and_thirty_three(): void
    {
        $this->biotechnologyStructure();
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);
        $component = Livewire::test(Index::class);

        $this->assertSame(39, $component->viewData('structureCount'));
        $this->assertSame(6, $component->viewData('headerCount'));
        $this->assertSame(33, $component->viewData('workItemCount'));
    }

    public function test_header_led_has_no_progress_or_placeholder_requirement_ui(): void
    {
        [, $criteria, $items] = $this->biotechnologyStructure();
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);

        $component = Livewire::test(Index::class)
            ->call('openDetail', $items['A'][1]->id)
            ->assertSee('Header Laporan Evaluasi Diri')
            ->assertDontSee('A Header Placeholder')
            ->assertDontSee('Upload tersedia pada tahap berikutnya');

        $this->assertNull($component->viewData('selectedItem')->verified_progress);
        $this->assertSame($criteria['A']->id, $component->get('activeCriterionId'));
    }

    public function test_item_a_two_keeps_all_ten_requirements_in_number_order(): void
    {
        [, , $items] = $this->biotechnologyStructure();
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);

        $selected = Livewire::test(Index::class)
            ->call('openDetail', $items['A'][2]->id)
            ->viewData('selectedItem');

        $this->assertCount(10, $selected->requirements);
        $this->assertSame(range(1, 10), $selected->requirements->pluck('number')->all());
    }

    public function test_filters_only_apply_inside_the_active_criterion(): void
    {
        [, $criteria, $items] = $this->biotechnologyStructure();
        $special = $this->requirement($items['B'][2], 2, 'Bukti Khusus B', true, 'draft');
        $items['B'][2]->update(['source_pic' => 'Tim B']);
        $this->requirement($items['A'][2], 11, 'Bukti Khusus B', true, 'draft');
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);

        $displayed = Livewire::test(Index::class)
            ->call('selectCriterion', $criteria['B']->id)
            ->set('search', 'Bukti Khusus B')
            ->set('workflowStatus', 'draft')
            ->set('sourcePic', 'Tim B')
            ->viewData('items');

        $workItems = $displayed->where('is_led_header', false);
        $this->assertCount(1, $workItems);
        $this->assertSame($special->item_id, $workItems->first()->id);
        $this->assertTrue($displayed->every(fn (AccreditationItem $item) => $item->criterion_id === $criteria['B']->id));
    }

    public function test_reset_filters_preserves_the_active_criterion(): void
    {
        [, $criteria] = $this->biotechnologyStructure();
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);

        Livewire::test(Index::class)
            ->call('selectCriterion', $criteria['D']->id)
            ->set('search', 'Tidak Ada')
            ->set('workflowStatus', 'draft')
            ->call('resetFilters')
            ->assertSet('activeCriterionId', $criteria['D']->id)
            ->assertSet('search', '')
            ->assertSet('workflowStatus', '');
    }

    public function test_work_item_is_verified_only_when_all_required_requirements_are_verified(): void
    {
        $project = $this->project('PROGRESS');
        $criterion = $this->criterion($project, 'A', 1, 'Progress');
        $partial = $this->item($criterion, 1, 1, 'Belum Lengkap');
        $complete = $this->item($criterion, 2, 2, 'Sudah Lengkap');
        $this->requirement($partial, 1, 'Verified', true, 'verified');
        $this->requirement($partial, 2, 'Draft', true, 'draft');
        $this->requirement($complete, 1, 'Wajib Verified', true, 'verified');
        $this->requirement($complete, 2, 'Opsional Draft', false, 'draft');
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);

        $items = Livewire::test(Index::class)->viewData('items')->keyBy('title');

        $this->assertSame('in_progress', $items['Belum Lengkap']->aggregate_status);
        $this->assertSame(50, $items['Belum Lengkap']->verified_progress);
        $this->assertSame('verified', $items['Sudah Lengkap']->aggregate_status);
        $this->assertSame(100, $items['Sudah Lengkap']->verified_progress);
        $this->assertSame(1, $items['Sudah Lengkap']->required_count);
    }

    public function test_mixed_required_statuses_keep_progress_and_expose_revision_signal(): void
    {
        $project = $this->project('MIXED');
        $criterion = $this->criterion($project, 'A', 1, 'Mixed Status');
        $item = $this->item($criterion, 2, 2, 'Butir Campuran');

        foreach (range(1, 10) as $number) {
            $status = match ($number) {
                1 => 'verified',
                4 => 'need_revision',
                default => 'not_started',
            };
            $this->requirement($item, $number, 'Rincian '.$number, true, $status);
        }

        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);

        $component = Livewire::test(Index::class)->call('openDetail', $item->id);
        $displayed = $component->viewData('items')->first();

        $this->assertSame('in_progress', $displayed->aggregate_status);
        $this->assertSame(1, $displayed->verified_required_count);
        $this->assertSame(10, $displayed->required_count);
        $this->assertSame(10, $displayed->verified_progress);
        $this->assertTrue($displayed->has_required_revision);
        $component
            ->assertSee('Dalam Proses')
            ->assertSee('Ada rincian perlu revisi')
            ->assertSee('Tindakan diperlukan')
            ->assertSee('aggregate-status-in_progress', false)
            ->assertSee('progress-need_revision', false)
            ->assertSee('style="width: 10%"', false);
    }

    public function test_active_item_renders_operational_requirements_and_quick_summary(): void
    {
        $project = $this->project('HYBRID');
        $criterion = $this->criterion($project, 'A', 1, 'Tata Kelola');
        $item = $this->item($criterion, 2, 2, 'Dokumen Tata Kelola', 'Tim Mutu');
        $item->update(['source_detail_text' => 'Ringkasan operasional butir untuk panel kanan.']);
        $verified = $this->requirement($item, 1, 'Dokumen Terverifikasi', true, 'verified');
        $this->requirement($item, 2, 'Dokumen Dalam Penyusunan', true, 'draft');
        $admin = $this->userWithRole('Super Admin');
        $uuid = (string) Str::uuid();
        $document = AccreditationDocument::create([
            'requirement_id' => $verified->id,
            'uploaded_by' => $admin->id,
            'original_name' => 'bukti-terverifikasi.pdf',
            'disk' => 'local',
            'storage_path' => "accreditation/{$verified->id}/{$uuid}.pdf",
            'storage_uuid' => $uuid,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1200,
            'sha256' => str_repeat('a', 64),
            'revision_number' => 1,
            'is_active' => true,
            'uploaded_at' => now(),
        ]);
        $this->actingAs($admin);

        $component = Livewire::test(Index::class)
            ->assertSee('Rincian Dokumen Operasional')
            ->assertSee('Dokumen Terverifikasi')
            ->assertSee('Dokumen Dalam Penyusunan')
            ->assertSee('Wajib')
            ->assertSee('1 file')
            ->assertSee('Terkunci')
            ->assertSee('Unggah')
            ->assertSee(route('documents.preview', $document), false)
            ->assertSee('Ringkasan Cepat Butir')
            ->assertSee('Ringkasan operasional butir untuk panel kanan.')
            ->assertSee('1 / 2')
            ->assertSee('50%');

        $selected = $component->viewData('selectedItem');
        $this->assertSame($item->id, $selected->id);
        $this->assertCount(1, $selected->requirements->first()->documents);
    }

    private function biotechnologyStructure(): array
    {
        $project = $this->project('BIOTEK', 'Bioteknologi', 'APU-BIOTEK-2026');
        $distribution = ['A' => 3, 'B' => 16, 'C' => 10, 'D' => 5, 'E' => 2, 'F' => 3];
        $criteria = [];
        $items = [];

        foreach ($distribution as $code => $count) {
            $position = array_search($code, array_keys($distribution), true) + 1;
            $criterion = $this->criterion($project, $code, $position, 'Kriteria '.$code);
            $criteria[$code] = $criterion;

            for ($number = 1; $number <= $count; $number++) {
                $item = $this->item(
                    $criterion,
                    $number,
                    $number,
                    $code.' Item '.$number,
                    $number === 1 ? null : 'Tim '.$code,
                );
                $items[$code][$number] = $item;

                if ($number === 1) {
                    $this->requirement($item, 1, $code.' Header Placeholder', true, 'not_started');
                } elseif ($code === 'A' && $number === 2) {
                    for ($requirementNumber = 1; $requirementNumber <= 10; $requirementNumber++) {
                        $this->requirement(
                            $item,
                            $requirementNumber,
                            'A.2 Rincian '.$requirementNumber,
                            true,
                            'not_started',
                        );
                    }
                } else {
                    $this->requirement($item, 1, $code.'.'.$number.' Rincian', true, 'not_started');
                }
            }
        }

        return [$project, $criteria, $items];
    }

    private function threeCriteriaProject(string $suffix): array
    {
        $project = $this->project($suffix);
        $criteria = [
            'A' => $this->criterion($project, 'A', 1, $suffix.' Kriteria A'),
            'B' => $this->criterion($project, 'B', 2, $suffix.' Kriteria B'),
            'C' => $this->criterion($project, 'C', 3, $suffix.' Kriteria C'),
        ];

        foreach ($criteria as $code => $criterion) {
            $this->item($criterion, 1, 1, $suffix.' Butir '.$code);
        }

        return [$project, $criteria];
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => $role.' Test',
            'email' => strtolower(str_replace(' ', '.', $role)).'.'.uniqid().'@example.test',
            'password' => Hash::make('ValidPassword!123'),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function project(
        string $suffix,
        string $programName = 'Program Studi Test',
        ?string $projectCode = null,
    ): AccreditationProject {
        $program = StudyProgram::create([
            'code' => 'PS-'.$suffix,
            'name' => $programName,
            'is_active' => true,
        ]);

        return AccreditationProject::create([
            'code' => $projectCode ?? 'PROJECT-'.$suffix,
            'name' => 'Project '.$suffix,
            'study_program_id' => $program->id,
            'final_deadline' => '2026-10-30',
            'status' => 'active',
        ]);
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
            'color_header' => '#123456',
            'color_body' => '#dbeafe',
            'position' => $position,
        ]);
    }

    private function item(
        AccreditationCriterion $criterion,
        int $number,
        int $position,
        string $title,
        ?string $sourcePic = null,
    ): AccreditationItem {
        return AccreditationItem::create([
            'criterion_id' => $criterion->id,
            'number' => $number,
            'position' => $position,
            'title' => $title,
            'source_pic' => $sourcePic,
            'source_deadline' => '2026-10-20',
        ]);
    }

    private function requirement(
        AccreditationItem $item,
        int $number,
        string $title,
        bool $required,
        string $status,
    ): AccreditationDocumentRequirement {
        return AccreditationDocumentRequirement::create([
            'item_id' => $item->id,
            'number' => $number,
            'title' => $title,
            'is_required' => $required,
            'workflow_status' => $status,
            'current_review_revision' => 0,
        ]);
    }

    private function assignment(
        AccreditationCriterion $criterion,
        User $user,
        string $role,
        bool $active,
    ): AccreditationCriterionAssignment {
        return AccreditationCriterionAssignment::create([
            'criterion_id' => $criterion->id,
            'user_id' => $user->id,
            'assignment_role' => $role,
            'is_active' => $active,
        ]);
    }

    private function membership(
        AccreditationProject $project,
        User $user,
        string $role,
        bool $active,
    ): AccreditationProjectMember {
        return AccreditationProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'membership_role' => $role,
            'is_active' => $active,
        ]);
    }
}
