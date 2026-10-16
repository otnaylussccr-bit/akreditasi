<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\TeamActivity\Index;
use App\Models\AccreditationActivityLog;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class TeamActivityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
    }

    public function test_pic_and_reviewer_only_receive_assigned_activity_before_pagination(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        $project = $this->project('SCOPE');
        $allowedCriterion = $this->criterion($project, 'A', 1, 'Diizinkan');
        $hiddenCriterion = $this->criterion($project, 'B', 2, 'Tersembunyi');
        $allowedRequirement = $this->requirement($allowedCriterion, 'Butir Diizinkan');
        $hiddenRequirement = $this->requirement($hiddenCriterion, 'BUTIR-RAHASIA');
        $pic = $this->user('PIC Kriteria', 'PIC Scope');
        $reviewer = $this->user('Reviewer', 'Reviewer Scope');
        $manager = $this->user('Project Manager', 'Manager Scope');
        $viewer = $this->user('Viewer', 'Viewer Scope');
        $this->assignment($allowedCriterion, $pic, 'pic', true);
        $this->assignment($allowedCriterion, $reviewer, 'reviewer', true);
        $this->membership($project, $manager, 'project_manager', true);
        $this->membership($project, $viewer, 'viewer', true);
        $allowedDocument = $this->document($allowedRequirement, $pic, 'allowed.pdf');
        $hiddenDocument = $this->document($hiddenRequirement, $pic, 'hidden.pdf');

        foreach (range(1, 30) as $index) {
            $this->activity($project, $hiddenDocument, $pic, 'document.uploaded', now()->subMinutes($index), "hidden-secret-{$index}");
        }

        foreach (range(1, 12) as $index) {
            $this->activity($project, $allowedDocument, $pic, 'document.uploaded', now()->subHours($index), "allowed-secret-{$index}");
        }

        $this->actingAs($pic);
        $picComponent = Livewire::withQueryParams(['proyek' => $project->id])
            ->test(Index::class)
            ->assertSee('Butir Diizinkan')
            ->assertDontSee('BUTIR-RAHASIA')
            ->assertDontSee('allowed-secret-1')
            ->assertDontSee('hidden-secret-1');
        $picActivities = $picComponent->viewData('activities');

        $this->assertSame(12, $picActivities->total());
        $this->assertCount(10, $picActivities->items());
        $this->assertSame(['A.1'], $picActivities->getCollection()->pluck('item_code')->unique()->values()->all());

        $this->actingAs($reviewer);
        $reviewerComponent = Livewire::withQueryParams(['proyek' => $project->id])
            ->test(Index::class)
            ->assertSee('Butir Diizinkan')
            ->assertDontSee('BUTIR-RAHASIA');
        $this->assertSame(12, $reviewerComponent->viewData('activities')->total());

        $this->actingAs($manager);
        $managerComponent = Livewire::withQueryParams(['proyek' => $project->id])->test(Index::class);
        $this->assertSame(42, $managerComponent->viewData('activities')->total());

        $this->actingAs($viewer);
        Livewire::withQueryParams(['proyek' => $project->id])->test(Index::class)->assertForbidden();

        $this->actingAs($pic);
        $component = Livewire::withQueryParams(['proyek' => $project->id])->test(Index::class);
        AccreditationCriterionAssignment::query()
            ->where('criterion_id', $allowedCriterion->id)
            ->where('user_id', $pic->id)
            ->update(['is_active' => false]);
        $component->call('$refresh')->assertForbidden();
    }

    public function test_filters_kpis_categories_and_pic_tie_break_use_scoped_real_events(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        $project = $this->project('SUMMARY');
        $criterion = $this->criterion($project, 'A', 1, 'Tata Kelola');
        $revisionRequirement = $this->requirement($criterion, 'Revisi Terbuka', 'need_revision');
        $verifiedRequirement = $this->requirement($criterion, 'Sudah Selesai', 'verified', 2);
        $alpha = $this->user('PIC Kriteria', 'Alpha PIC');
        $beta = $this->user('PIC Kriteria', 'Beta PIC');
        $reviewer = $this->user('Reviewer', 'Reviewer Internal');
        $admin = $this->user('Super Admin', 'Admin Activity');
        $document = $this->document($revisionRequirement, $alpha, 'Dokumen Kebijakan.pdf');
        $otherDocument = $this->document($verifiedRequirement, $beta, 'Dokumen Selesai.pdf');
        $submission = $this->submission($verifiedRequirement, $alpha, 1);
        $revisionSubmission = $this->submission($revisionRequirement, $alpha, 1, 'need_revision');
        $revisionSubmission->documents()->attach($document);

        $this->activity($project, $document, $alpha, 'document.uploaded', Carbon::parse('2026-10-14 09:00:00'), 'today-secret');
        $this->activity($project, $submission, $alpha, 'document.submitted', Carbon::parse('2026-10-12 10:00:00'));
        $this->activity($project, $revisionSubmission, $beta, 'document.revision_requested', Carbon::parse('2026-10-11 11:00:00'));
        $this->activity($project, $otherDocument, $beta, 'document.emergency_uploaded', Carbon::parse('2026-10-10 08:00:00'));
        $this->activity($project, $submission, $reviewer, 'document.verified', Carbon::parse('2026-10-13 13:00:00'));

        $this->actingAs($admin);
        $component = Livewire::withQueryParams(['proyek' => $project->id])
            ->test(Index::class)
            ->assertSee('Aktivitas Hari Ini')
            ->assertSee('Semua event hari ini, tidak dipengaruhi periode')
            ->assertDontSee('today-secret');

        $this->assertSame(1, $component->viewData('todayCount'));
        $this->assertSame(1, $component->viewData('verifiedCount'));
        $this->assertSame(1, $component->viewData('openRevisionCount'));
        $this->assertSame([
            'name' => 'Alpha PIC',
            'initials' => 'AP',
            'count' => 2,
        ], $component->viewData('mostActivePic'));
        $this->assertSame([
            'upload' => 2,
            'submission' => 1,
            'revision' => 1,
            'verification' => 1,
        ], $component->viewData('categories')->pluck('count', 'category')->all());

        $component
            ->set('event', 'document.verified')
            ->set('dateFrom', '2026-10-13')
            ->set('dateTo', '2026-10-13')
            ->assertSee('Dokumen terverifikasi');

        $this->assertSame(1, $component->viewData('todayCount'));
        $this->assertSame(1, $component->viewData('verifiedCount'));
        $this->assertSame(1, $component->viewData('activities')->total());
        $this->assertSame(
            ['document.verified'],
            $component->viewData('activities')->getCollection()->pluck('event')->all(),
        );
        $this->assertNull($component->viewData('mostActivePic'));

        $component
            ->call('resetFilters')
            ->set('search', 'Kebijakan')
            ->assertSee('Revisi Terbuka')
            ->assertDontSee('Sudah Selesai');
        $this->assertSame(2, $component->viewData('activities')->total());

        $component->set('event', 'not-a-real-event')->assertForbidden();
    }

    public function test_date_filter_uses_wib_day_boundaries_instead_of_utc_midnight(): void
    {
        $project = $this->project('WIB-BOUNDARY');
        $criterion = $this->criterion($project, 'A', 1, 'Batas Hari WIB');
        $requirement = $this->requirement($criterion, 'Dokumen Batas Hari');
        $pic = $this->user('PIC Kriteria', 'PIC Batas Hari');
        $admin = $this->user('Super Admin', 'Admin Batas Hari');
        $document = $this->document($requirement, $pic, 'batas-hari.pdf');

        $this->activity($project, $document, $pic, 'document.uploaded', Carbon::parse('2026-10-15 16:59:59', 'UTC'));
        $this->activity($project, $document, $pic, 'document.uploaded', Carbon::parse('2026-10-15 17:00:00', 'UTC'));
        $insideStartId = AccreditationActivityLog::query()->max('id');
        $this->activity($project, $document, $pic, 'document.uploaded', Carbon::parse('2026-10-16 16:59:59', 'UTC'));
        $insideEndId = AccreditationActivityLog::query()->max('id');
        $this->activity($project, $document, $pic, 'document.uploaded', Carbon::parse('2026-10-16 17:00:00', 'UTC'));

        $this->actingAs($admin);
        $component = Livewire::withQueryParams([
            'proyek' => $project->id,
            'dari' => '2026-10-16',
            'sampai' => '2026-10-16',
        ])->test(Index::class);

        $this->assertSame(2, $component->viewData('activities')->total());
        $this->assertSame(
            [$insideEndId, $insideStartId],
            $component->viewData('activities')->getCollection()->pluck('id')->all(),
        );
    }

    public function test_dashboard_and_activity_page_share_order_projection_and_project_link(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        $project = $this->project('CONSISTENCY');
        $criterion = $this->criterion($project, 'A', 1, 'Konsistensi');
        $requirement = $this->requirement($criterion, 'Butir Konsisten');
        $pic = $this->user('PIC Kriteria', 'PIC Konsisten');
        $this->assignment($criterion, $pic, 'pic', true);
        $document = $this->document($requirement, $pic, 'consistent.pdf');
        $this->activity($project, $document, $pic, 'document.uploaded', now());
        $this->actingAs($pic);

        $dashboard = Livewire::withQueryParams(['proyek' => $project->id])
            ->test(Dashboard::class)
            ->assertSee('Lihat Semua');
        $activityPage = Livewire::withQueryParams(['proyek' => $project->id])->test(Index::class);
        $dashboardActivity = $dashboard->viewData('activities')->first();
        $pageActivity = $activityPage->viewData('activities')->getCollection()->first();

        $this->assertSame(route('team-activity.index', ['proyek' => $project->id]), $dashboard->viewData('activityUrl'));
        $this->assertSame($dashboardActivity['id'], $pageActivity['id']);
        $this->assertSame($dashboardActivity['label'], $pageActivity['label']);
        $this->assertSame($dashboardActivity['actor'], $pageActivity['actor']);
        $this->assertSame($dashboardActivity['item_code'], $pageActivity['item_code']);
        $this->assertSame($dashboardActivity['url'], $pageActivity['url']);
    }

    public function test_project_criterion_actor_and_date_tampering_are_rejected(): void
    {
        $project = $this->project('TAMPER');
        $criterion = $this->criterion($project, 'A', 1, 'Diizinkan');
        $outsideProject = $this->project('OUTSIDE');
        $outsideCriterion = $this->criterion($outsideProject, 'B', 1, 'Di Luar');
        $requirement = $this->requirement($criterion, 'Butir Aman');
        $pic = $this->user('PIC Kriteria', 'PIC Aman');
        $outsider = $this->user('PIC Kriteria', 'PIC Luar');
        $this->assignment($criterion, $pic, 'pic', true);
        $this->assignment($outsideCriterion, $outsider, 'pic', true);
        $document = $this->document($requirement, $pic, 'safe.pdf');
        $this->activity($project, $document, $pic, 'document.uploaded', now());
        $this->actingAs($pic);

        Livewire::withQueryParams(['proyek' => $outsideProject->id])->test(Index::class)->assertForbidden();
        Livewire::withQueryParams(['proyek' => $project->id, 'kriteria' => $outsideCriterion->id])->test(Index::class)->assertForbidden();
        Livewire::withQueryParams(['proyek' => $project->id, 'pengguna' => $outsider->id])->test(Index::class)->assertForbidden();
        Livewire::withQueryParams(['proyek' => $project->id, 'dari' => '2026-99-99'])->test(Index::class)->assertForbidden();
        Livewire::withQueryParams(['proyek' => $project->id, 'dari' => '2026-10-14', 'sampai' => '2026-10-01'])->test(Index::class)->assertForbidden();
    }

    private function project(string $suffix): AccreditationProject
    {
        $program = StudyProgram::create([
            'code' => 'PS-'.$suffix,
            'name' => 'Program Studi '.$suffix,
            'is_active' => true,
        ]);

        return AccreditationProject::create([
            'code' => 'PROJECT-'.$suffix,
            'name' => 'Akreditasi '.$suffix,
            'study_program_id' => $program->id,
            'final_deadline' => '2026-10-30',
            'status' => 'active',
        ]);
    }

    private function criterion(AccreditationProject $project, string $code, int $position, string $name): AccreditationCriterion
    {
        return AccreditationCriterion::create([
            'project_id' => $project->id,
            'code' => $code,
            'name' => $name,
            'color_header' => '#173E70',
            'color_body' => '#E7EEF8',
            'position' => $position,
        ]);
    }

    private function requirement(
        AccreditationCriterion $criterion,
        string $title,
        string $status = 'draft',
        int $number = 1,
    ): AccreditationDocumentRequirement {
        $item = AccreditationItem::create([
            'criterion_id' => $criterion->id,
            'number' => $number,
            'position' => $number,
            'title' => $title,
        ]);

        return AccreditationDocumentRequirement::create([
            'item_id' => $item->id,
            'number' => 1,
            'title' => 'Rincian '.$title,
            'is_required' => true,
            'workflow_status' => $status,
            'current_review_revision' => 1,
        ]);
    }

    private function user(string $role, string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('ValidPassword!123'),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function assignment(AccreditationCriterion $criterion, User $user, string $role, bool $active): void
    {
        AccreditationCriterionAssignment::create([
            'criterion_id' => $criterion->id,
            'user_id' => $user->id,
            'assignment_role' => $role,
            'is_active' => $active,
        ]);
    }

    private function membership(AccreditationProject $project, User $user, string $role, bool $active): void
    {
        AccreditationProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'membership_role' => $role,
            'is_active' => $active,
        ]);
    }

    private function document(AccreditationDocumentRequirement $requirement, User $uploader, string $name): AccreditationDocument
    {
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

    private function submission(
        AccreditationDocumentRequirement $requirement,
        User $submitter,
        int $revision,
        string $status = 'verified',
    ): AccreditationReviewSubmission {
        return AccreditationReviewSubmission::create([
            'requirement_id' => $requirement->id,
            'submitted_by' => $submitter->id,
            'revision_number' => $revision,
            'status' => $status,
            'submitted_at' => now()->subDays(2),
            'decided_at' => now()->subDay(),
            'decided_by' => $submitter->id,
        ]);
    }

    private function activity(
        AccreditationProject $project,
        AccreditationDocument|AccreditationReviewSubmission $subject,
        User $actor,
        string $event,
        Carbon $createdAt,
        ?string $secret = null,
    ): void {
        AccreditationActivityLog::create([
            'project_id' => $project->id,
            'actor_id' => $actor->id,
            'event' => $event,
            'subject_type' => $subject::class,
            'subject_id' => $subject->id,
            'new_values' => $secret ? ['sha256' => $secret, 'review_note' => 'raw-note'] : null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'sensitive-agent',
            'created_at' => $createdAt,
        ]);
    }
}
