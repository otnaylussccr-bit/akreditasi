<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\AccreditationActivityLog;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
    }

    public function test_kpi_buckets_are_exhaustive_exclude_headers_and_use_completed_work_items(): void
    {
        $project = $this->project('SUMMARY', '2026-10-30', 'APU-BIOTEK-2026');
        $criterionA = $this->criterion($project, 'A', 1, 'Tata Kelola');
        $criterionB = $this->criterion($project, 'B', 2, 'Pendidikan');
        $this->itemWithStatuses($criterionA, 1, 1, 'Header A', ['not_started']);
        $mixed = $this->item($criterionA, 2, 2, 'A.2 Campuran');

        foreach (range(1, 10) as $number) {
            $status = match ($number) {
                1 => 'verified',
                4 => 'pending_review',
                default => 'not_started',
            };
            $this->requirement($mixed, $number, $status);
        }

        $this->itemWithStatuses($criterionA, 3, 3, 'A.3 Selesai', ['verified']);
        $this->itemWithStatuses($criterionA, 4, 4, 'A.4 Draft', ['draft']);
        $this->itemWithStatuses($criterionA, 5, 5, 'A.5 Revisi', ['need_revision']);
        $this->itemWithStatuses($criterionA, 6, 6, 'A.6 Menunggu', ['pending_review']);
        $this->itemWithStatuses($criterionA, 7, 7, 'A.7 Belum Mulai', ['not_started']);
        $this->itemWithStatuses($criterionB, 1, 1, 'Header B', ['not_started']);
        $this->itemWithStatuses($criterionB, 2, 2, 'B.2 Selesai', ['verified']);
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);

        $component = Livewire::test(Dashboard::class)
            ->assertSee('Ringkasan Progres')
            ->assertSee('Dalam Proses')
            ->assertSee('Draft')
            ->assertSee('title="Dalam Proses: 1"', false)
            ->assertSee('title="Belum Mulai: 1"', false);
        $summary = $component->viewData('summary');
        $criteriaProgress = $component->viewData('criteriaProgress');
        $statusBuckets = $component->viewData('statusBuckets');
        $mixedSummary = $summary['work_items']->firstWhere('id', $mixed->id);

        $this->assertSame(9, $summary['structure_count']);
        $this->assertSame(2, $summary['header_count']);
        $this->assertSame(7, $summary['work_count']);
        $this->assertSame(7, $summary['status_counts']->sum());
        $this->assertSame([
            'verified' => 2,
            'pending_review' => 1,
            'need_revision' => 1,
            'draft' => 1,
            'in_progress' => 1,
            'not_started' => 1,
        ], $summary['status_counts']->all());
        $this->assertSame('in_progress', $mixedSummary->aggregate_status);
        $this->assertSame(1, $mixedSummary->verified_required_count);
        $this->assertSame(10, $mixedSummary->required_count);
        $this->assertSame(10, $mixedSummary->verified_progress);
        $this->assertSame(2, $summary['verified_count']);
        $this->assertSame(29, $summary['completion_percentage']);
        $this->assertSame(7, $criteriaProgress->sum(fn (array $entry): int => $entry['summary']['work_count']));
        $this->assertSame(2, $criteriaProgress->sum(fn (array $entry): int => $entry['summary']['verified_count']));
        $this->assertSame([
            'verified' => '#43A878',
            'pending_review' => '#E9AC36',
            'need_revision' => '#C94B4B',
            'draft' => '#4D91D1',
            'in_progress' => '#F2BE4D',
            'not_started' => '#DF7373',
        ], $statusBuckets->pluck('color', 'status')->all());

        foreach ($criteriaProgress as $entry) {
            $this->assertSame($entry['summary']['work_count'], $entry['segments']->sum('count'));
        }
    }

    public function test_deadline_reports_remaining_days_today_and_overdue_without_fake_time_progress(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        $project = $this->project('DEADLINE', '2026-10-30');
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);
        $component = Livewire::test(Dashboard::class)
            ->assertSee('16 hari lagi')
            ->assertSee('tanggal mulai project tidak tersedia');

        $this->assertSame(16, $component->viewData('deadline')['days']);
        $this->assertSame('upcoming', $component->viewData('deadline')['state']);

        $project->update(['final_deadline' => '2026-10-14']);
        $component->call('$refresh')->assertSee('Jatuh tempo hari ini');
        $this->assertSame('today', $component->viewData('deadline')['state']);

        $project->update(['final_deadline' => '2026-10-10']);
        $component->call('$refresh')->assertSee('Terlambat 4 hari');
        $this->assertSame(-4, $component->viewData('deadline')['days']);
        $this->assertSame('overdue', $component->viewData('deadline')['state']);
    }

    public function test_attention_items_follow_explicit_priority_and_threshold(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        $project = $this->project('ATTENTION', '2026-10-30');
        $criterion = $this->criterion($project, 'A', 1, 'Tata Kelola');
        $this->itemWithStatuses($criterion, 1, 1, 'Terlambat', ['not_started'], '2026-10-01');
        $revision = $this->item($criterion, 2, 2, 'Campuran Perlu Revisi', '2026-12-31');
        $this->requirement($revision, 1, 'verified');
        $this->requirement($revision, 2, 'need_revision');
        $this->itemWithStatuses($criterion, 3, 3, 'Menunggu Verifikasi', ['pending_review'], '2026-12-31');
        $this->itemWithStatuses($criterion, 4, 4, 'Deadline Mendekat', ['draft'], '2026-10-20');
        $this->itemWithStatuses($criterion, 5, 5, 'Deadline Masih Jauh', ['not_started'], '2027-01-31');
        $this->itemWithStatuses($criterion, 6, 6, 'Selesai Walau Terlambat', ['verified'], '2026-10-01');
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);

        $component = Livewire::test(Dashboard::class);
        $attention = $component->viewData('attentionItems');

        $this->assertSame([
            'Terlambat',
            'Campuran Perlu Revisi',
            'Menunggu Verifikasi',
            'Deadline Mendekat',
        ], $attention->pluck('item.title')->all());
        $this->assertSame([0, 1, 2, 3], $attention->pluck('priority')->all());
        $this->assertSame('in_progress', $attention[1]['item']->aggregate_status);
        $this->assertTrue($attention[1]['item']->has_required_revision);
        $component
            ->assertSee('Terlambat 13 hari')
            ->assertSee('6 hari lagi')
            ->assertDontSee('Deadline Masih Jauh')
            ->assertDontSee('Selesai Walau Terlambat');
    }

    public function test_activity_feed_is_permission_checked_criterion_scoped_and_sanitized(): void
    {
        $project = $this->project('ACTIVITY', '2026-10-30');
        $allowedCriterion = $this->criterion($project, 'A', 1, 'Diizinkan');
        $hiddenCriterion = $this->criterion($project, 'B', 2, 'Tersembunyi');
        $outsideProject = $this->project('OUTSIDE', '2026-11-30');
        $outsideCriterion = $this->criterion($outsideProject, 'C', 1, 'Project Lain');
        $allowedRequirement = $this->requirement($this->item($allowedCriterion, 1, 1, 'Butir Diizinkan'), 1, 'draft');
        $hiddenRequirement = $this->requirement($this->item($hiddenCriterion, 1, 1, 'BUTIR-RAHASIA'), 1, 'draft');
        $outsideRequirement = $this->requirement($this->item($outsideCriterion, 1, 1, 'BUTIR-LUAR'), 1, 'draft');
        $actor = $this->userWithRole('PIC Kriteria');
        $reviewer = $this->userWithRole('Reviewer');
        $viewer = $this->userWithRole('Viewer');
        $this->assignment($allowedCriterion, $actor, 'pic', true);
        $this->assignment($allowedCriterion, $reviewer, 'reviewer', true);
        $this->membership($project, $viewer, 'viewer', true);
        $allowedDocument = $this->document($allowedRequirement, $actor, 'allowed.pdf');
        $hiddenDocument = $this->document($hiddenRequirement, $actor, 'hidden.pdf');
        $outsideDocument = $this->document($outsideRequirement, $actor, 'outside.pdf');
        $this->activity($project, $allowedDocument, $actor, 'allowed-secret-hash');
        $this->activity($project, $hiddenDocument, $actor, 'hidden-secret-hash');
        $this->activity($outsideProject, $outsideDocument, $actor, 'outside-secret-hash');
        foreach (range(1, 50) as $index) {
            $this->activity($project, $hiddenDocument, $actor, "hidden-crowding-secret-{$index}");
        }
        $this->assertFalse($actor->can('activity.view'));
        $this->actingAs($actor);

        $picComponent = Livewire::test(Dashboard::class)
            ->assertSee('Butir Diizinkan')
            ->assertSee($actor->name)
            ->assertDontSee('BUTIR-RAHASIA')
            ->assertDontSee('BUTIR-LUAR')
            ->assertDontSee('allowed-secret-hash')
            ->assertDontSee('hidden-secret-hash')
            ->assertDontSee('outside-secret-hash');

        $this->assertTrue($picComponent->viewData('canViewActivity'));
        $this->assertCount(1, $picComponent->viewData('activities'));
        $this->assertSame('A.1', $picComponent->viewData('activities')->first()['item_code']);
        $this->assertSame(route('follow-up-led.index', [
            'proyek' => $project->id,
            'kriteria' => $allowedCriterion->id,
            'cari' => 'Butir Diizinkan',
        ]), $picComponent->viewData('activities')->first()['url']);

        $this->actingAs($reviewer);
        $reviewerComponent = Livewire::test(Dashboard::class)
            ->assertSee('Butir Diizinkan')
            ->assertDontSee('BUTIR-RAHASIA')
            ->assertDontSee('BUTIR-LUAR');

        $this->assertTrue($reviewerComponent->viewData('canViewActivity'));
        $this->assertCount(1, $reviewerComponent->viewData('activities'));

        $this->actingAs($viewer);
        $viewerComponent = Livewire::test(Dashboard::class)
            ->assertSee('Aktivitas tidak tersedia untuk peran ini')
            ->assertDontSee('Butir Diizinkan');

        $this->assertFalse($viewerComponent->viewData('canViewActivity'));
        $this->assertTrue($viewerComponent->viewData('activities')->isEmpty());
    }

    public function test_follow_up_status_accents_and_progress_bars_use_the_dashboard_color_contract(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);

        foreach ([
            'verified' => '#43A878',
            'in_progress' => '#F2BE4D',
            'not_started' => '#DF7373',
            'need_revision' => '#C94B4B',
            'pending_review' => '#E9AC36',
            'draft' => '#4D91D1',
        ] as $status => $color) {
            $this->assertStringContainsString(
                ".follow-up-item-card.aggregate-status-{$status} { --aggregate-color: {$color};",
                $css,
            );
            $this->assertStringContainsString(
                ".progress-track.progress-{$status} span { background: {$color}; }",
                $css,
            );
        }

        $this->assertStringContainsString('.status-not_started { color: #8e3030; background: #fdeaea; }', $css);
        $this->assertStringContainsString('.status-in_progress { color: #73530d; background: #fff0c7; }', $css);
        $this->assertStringContainsString('.status-need_revision { color: #8f2525; background: #f8d9d9; }', $css);
    }

    public function test_criterion_identity_colors_only_tint_requested_dashboard_cells(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        $project = $this->project('CRITERION-COLORS', '2026-10-30');
        $criterionB = $this->criterion($project, 'B', 1, 'Kriteria B', '#7A3E00', '#FFF0D8');
        $criterionC = $this->criterion($project, 'C', 2, 'Kriteria C', '#155E75', '#DDF7FA');
        $this->itemWithStatuses($criterionB, 2, 2, 'Butir B.2 Berwarna', ['draft'], '2026-10-18');
        $this->itemWithStatuses($criterionC, 2, 2, 'Butir C.2 Berwarna', ['need_revision'], '2026-10-19');
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);

        Livewire::test(Dashboard::class)
            ->assertSee('class="criterion-identity-row" style="--criterion-header: #7A3E00; --criterion-body: #FFF0D8"', false)
            ->assertSee('class="criterion-identity-row" style="--criterion-header: #155E75; --criterion-body: #DDF7FA"', false)
            ->assertSee('class="attention-criterion-row" style="--criterion-header: #7A3E00; --criterion-body: #FFF0D8"', false)
            ->assertSee('class="attention-criterion-row" style="--criterion-header: #155E75; --criterion-body: #DDF7FA"', false)
            ->assertSee('B.2')
            ->assertSee('Butir B.2 Berwarna')
            ->assertSee('C.2')
            ->assertSee('Butir C.2 Berwarna');

        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.criteria-progress-table .criterion-identity-code-cell, .criteria-progress-table .criterion-identity-name-cell { background: var(--criterion-body); }', $css);
        $this->assertStringContainsString('.criteria-progress-table .criterion-identity-code-cell { border-left: 4px solid var(--criterion-header); }', $css);
        $this->assertStringContainsString('.dashboard-attention-table .attention-criterion-code-cell, .dashboard-attention-table .attention-criterion-title-cell { background: var(--criterion-body); }', $css);
        $this->assertStringContainsString('.dashboard-attention-table .attention-criterion-code-cell { border-left: 4px solid var(--criterion-header); color: #24364b; }', $css);
        $this->assertStringContainsString('.criteria-stacked-bar span { height: 100%; background: var(--segment-color); }', $css);
    }

    public function test_dashboard_scope_matches_each_role_and_rejects_project_tampering(): void
    {
        $project = $this->project('ROLES', '2026-10-30');
        $criterionA = $this->criterion($project, 'A', 1, 'Kriteria A');
        $criterionB = $this->criterion($project, 'B', 2, 'Kriteria B');
        $otherProject = $this->project('ROLES-OTHER', '2026-11-30');
        $criterionC = $this->criterion($otherProject, 'C', 1, 'Kriteria C');
        $this->itemWithStatuses($criterionA, 1, 1, 'Pekerjaan A', ['not_started']);
        $this->itemWithStatuses($criterionB, 1, 1, 'Pekerjaan B', ['not_started']);
        $this->itemWithStatuses($criterionC, 1, 1, 'Pekerjaan C', ['not_started']);
        $admin = $this->userWithRole('Super Admin');
        $manager = $this->userWithRole('Project Manager');
        $pic = $this->userWithRole('PIC Kriteria');
        $reviewer = $this->userWithRole('Reviewer');
        $viewer = $this->userWithRole('Viewer');
        $this->membership($project, $manager, 'project_manager', true);
        $this->membership($project, $viewer, 'viewer', true);
        $this->assignment($criterionA, $pic, 'pic', true);
        $this->assignment($criterionB, $reviewer, 'reviewer', true);

        $this->actingAs($admin);
        $this->assertCount(2, Livewire::test(Dashboard::class)->viewData('projects'));

        $this->actingAs($manager);
        $managerDashboard = Livewire::test(Dashboard::class);
        $this->assertSame(2, $managerDashboard->viewData('summary')['work_count']);
        $this->assertSame(['A', 'B'], $managerDashboard->viewData('criteriaProgress')->pluck('criterion.code')->all());

        $this->actingAs($pic);
        $picDashboard = Livewire::test(Dashboard::class);
        $this->assertSame(1, $picDashboard->viewData('summary')['work_count']);
        $this->assertSame(['A'], $picDashboard->viewData('criteriaProgress')->pluck('criterion.code')->all());

        $this->actingAs($reviewer);
        $reviewerDashboard = Livewire::test(Dashboard::class);
        $this->assertSame(1, $reviewerDashboard->viewData('summary')['work_count']);
        $this->assertSame(['B'], $reviewerDashboard->viewData('criteriaProgress')->pluck('criterion.code')->all());

        $this->actingAs($viewer);
        $viewerDashboard = Livewire::test(Dashboard::class)
            ->assertSee('Aktivitas tidak tersedia untuk peran ini');
        $this->assertSame(2, $viewerDashboard->viewData('summary')['work_count']);
        $this->assertSame(['A', 'B'], $viewerDashboard->viewData('criteriaProgress')->pluck('criterion.code')->all());

        $this->actingAs($pic);
        $picDashboard->call('selectProject', $otherProject->id)->assertForbidden();
        Livewire::withQueryParams(['proyek' => $otherProject->id])
            ->test(Dashboard::class)
            ->assertForbidden();
    }

    public function test_dashboard_has_an_informative_empty_state_without_accessible_projects(): void
    {
        $this->project('EMPTY', '2026-10-30');
        $viewer = $this->userWithRole('Viewer');
        $this->actingAs($viewer);

        $component = Livewire::test(Dashboard::class)
            ->assertSee('Belum ada proyek yang dapat diakses');

        $this->assertNull($component->viewData('project'));
        $this->assertTrue($component->viewData('projects')->isEmpty());
    }

    private function project(string $suffix, string $deadline, ?string $code = null): AccreditationProject
    {
        $program = StudyProgram::create([
            'code' => 'PS-'.$suffix,
            'name' => 'Program Studi '.$suffix,
            'is_active' => true,
        ]);

        return AccreditationProject::create([
            'code' => $code ?? 'PROJECT-'.$suffix,
            'name' => 'Akreditasi '.$suffix,
            'study_program_id' => $program->id,
            'final_deadline' => $deadline,
            'status' => 'active',
        ]);
    }

    private function criterion(
        AccreditationProject $project,
        string $code,
        int $position,
        string $name,
        string $colorHeader = '#173E70',
        string $colorBody = '#E7EEF8',
    ): AccreditationCriterion {
        return AccreditationCriterion::create([
            'project_id' => $project->id,
            'code' => $code,
            'name' => $name,
            'color_header' => $colorHeader,
            'color_body' => $colorBody,
            'position' => $position,
        ]);
    }

    private function item(
        AccreditationCriterion $criterion,
        int $number,
        int $position,
        string $title,
        ?string $deadline = null,
    ): AccreditationItem {
        return AccreditationItem::create([
            'criterion_id' => $criterion->id,
            'number' => $number,
            'position' => $position,
            'title' => $title,
            'source_deadline' => $deadline,
        ]);
    }

    private function itemWithStatuses(
        AccreditationCriterion $criterion,
        int $number,
        int $position,
        string $title,
        array $statuses,
        ?string $deadline = null,
    ): AccreditationItem {
        $item = $this->item($criterion, $number, $position, $title, $deadline);

        foreach ($statuses as $index => $status) {
            $this->requirement($item, $index + 1, $status);
        }

        return $item;
    }

    private function requirement(
        AccreditationItem $item,
        int $number,
        string $status,
    ): AccreditationDocumentRequirement {
        return AccreditationDocumentRequirement::create([
            'item_id' => $item->id,
            'number' => $number,
            'title' => 'Rincian '.$number.' '.$item->title,
            'is_required' => true,
            'workflow_status' => $status,
            'current_review_revision' => 0,
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

    private function membership(
        AccreditationProject $project,
        User $user,
        string $role,
        bool $active,
    ): void {
        AccreditationProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'membership_role' => $role,
            'is_active' => $active,
        ]);
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

    private function document(
        AccreditationDocumentRequirement $requirement,
        User $uploader,
        string $name,
    ): AccreditationDocument {
        $uuid = (string) Str::uuid();

        return AccreditationDocument::create([
            'requirement_id' => $requirement->id,
            'uploaded_by' => $uploader->id,
            'original_name' => $name,
            'disk' => 'local',
            'storage_path' => "accreditation/{$requirement->id}/{$uuid}.pdf",
            'storage_uuid' => $uuid,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1200,
            'sha256' => str_repeat('a', 64),
            'revision_number' => 1,
            'is_active' => true,
            'uploaded_at' => now(),
        ]);
    }

    private function activity(
        AccreditationProject $project,
        AccreditationDocument $document,
        User $actor,
        string $secret,
    ): void {
        AccreditationActivityLog::create([
            'project_id' => $project->id,
            'actor_id' => $actor->id,
            'event' => 'document.uploaded',
            'subject_type' => AccreditationDocument::class,
            'subject_id' => $document->id,
            'new_values' => [
                'sha256' => $secret,
                'review_note' => 'Jangan tampilkan catatan mentah.',
            ],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'sensitive-agent',
            'created_at' => now(),
        ]);
    }
}
