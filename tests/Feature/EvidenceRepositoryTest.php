<?php

namespace Tests\Feature;

use App\Livewire\Repository\Index;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationCriterionAssignment;
use App\Models\AccreditationDocument;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationItem;
use App\Models\AccreditationProject;
use App\Models\AccreditationProjectMember;
use App\Models\AccreditationReviewAttachment;
use App\Models\AccreditationReviewEvent;
use App\Models\AccreditationReviewSubmission;
use App\Models\StudyProgram;
use App\Models\User;
use Database\Seeders\AccreditationRolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class EvidenceRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_guest_is_redirected_and_authorized_navigation_targets_repository(): void
    {
        $this->get(route('repository.index'))->assertRedirect(route('login'));

        [$project, $criterion] = $this->structure('NAVIGATION');
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, 'pic');

        $this->actingAs($pic)
            ->get(route('repository.index'))
            ->assertOk()
            ->assertSee('class="nav-link active" href="'.route('repository.index', ['proyek' => $project->id]).'"', false)
            ->assertSee('Repository Bukti')
            ->assertSee($project->name);
    }

    public function test_original_documents_appear_once_versions_are_exact_and_correction_attachments_are_excluded(): void
    {
        [$project, $criterion, $item, $requirement] = $this->structure('VERSIONS', 'need_revision');
        $pic = $this->userWithRole('PIC Kriteria');
        $reviewer = $this->userWithRole('Reviewer');
        $first = $this->document($requirement, $pic, 'bukti-versi-satu.pdf', 1, true, '2026-01-01 09:00:00');
        $second = $this->document($requirement, $pic, 'bukti-versi-dua.pdf', 2, false, '2026-01-02 09:00:00');
        $firstSubmission = $this->submission($requirement, $pic, 1, 'verified', [$first]);
        $secondSubmission = $this->submission($requirement, $pic, 2, 'need_revision', [$first, $second]);
        $event = AccreditationReviewEvent::create([
            'submission_id' => $secondSubmission->id,
            'actor_id' => $reviewer->id,
            'action' => 'need_revision',
            'note' => 'Perbaiki bukti.',
            'created_at' => now(),
        ]);
        AccreditationReviewAttachment::create([
            'review_event_id' => $event->id,
            'storage_uuid' => (string) Str::uuid(),
            'storage_path' => 'private/reviewer-feedback.pdf',
            'original_filename' => 'LAMPIRAN-KOREKSI-RAHASIA.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 512,
            'sha256' => str_repeat('f', 64),
            'uploaded_at' => now(),
        ]);
        $admin = $this->userWithRole('Super Admin');
        $before = [
            'requirement_status' => $requirement->workflow_status,
            'first_sha' => $first->sha256,
            'first_path' => $first->storage_path,
            'second_active' => $second->is_active,
        ];
        $this->actingAs($admin);

        $component = Livewire::test(Index::class)
            ->assertSee('bukti-versi-satu.pdf')
            ->assertSee('bukti-versi-dua.pdf')
            ->assertDontSee('LAMPIRAN-KOREKSI-RAHASIA.pdf')
            ->call('selectDocument', $first->id)
            ->assertSee('Status rincian saat ini')
            ->assertSee('Snapshot r1: Terverifikasi')
            ->assertSee('Snapshot r2: Perlu Revisi')
            ->assertSee('Satu file original terikat pada satu rincian');

        $documents = $component->viewData('documents')->getCollection();
        $this->assertCount(2, $documents);
        $this->assertCount(2, $documents->pluck('id')->unique());
        $this->assertSame($first->id, $component->viewData('selectedDocument')->id);
        $this->assertSame('need_revision', $component->viewData('selectedDocument')->requirement->workflow_status);
        $this->assertSame([$second->id, $first->id], $component->viewData('versions')->pluck('id')->all());
        $this->assertSame([$firstSubmission->id, $secondSubmission->id], $first->reviewSubmissions()->orderBy('accreditation_review_submissions.id')->pluck('accreditation_review_submissions.id')->all());

        $this->assertSame($before['requirement_status'], $requirement->fresh()->workflow_status);
        $this->assertSame($before['first_sha'], $first->fresh()->sha256);
        $this->assertSame($before['first_path'], $first->fresh()->storage_path);
        $this->assertSame($before['second_active'], $second->fresh()->is_active);
        $this->assertSame($project->id, $criterion->project_id);
        $this->assertSame($item->id, $requirement->item_id);
    }

    public function test_each_role_only_receives_authorized_document_scope_and_upload_action(): void
    {
        [$project, $criterionA, , $requirementA] = $this->structure('ROLES-A');
        $criterionB = $this->criterion($project, 'B', 2, 'Tata Kelola');
        [, $requirementB] = $this->workItem($criterionB, 2, 'Rincian B', 'verified');
        [$outsideProject, $criterionC, , $requirementC] = $this->structure('ROLES-B');
        $uploader = $this->userWithRole('PIC Kriteria');
        $documentA = $this->document($requirementA, $uploader, 'FILE-A.pdf');
        $documentB = $this->document($requirementB, $uploader, 'FILE-B.pdf');
        $documentC = $this->document($requirementC, $uploader, 'FILE-C.pdf');
        $pic = $this->userWithRole('PIC Kriteria');
        $reviewer = $this->userWithRole('Reviewer');
        $manager = $this->userWithRole('Project Manager');
        $viewer = $this->userWithRole('Viewer');
        $admin = $this->userWithRole('Super Admin');
        $this->assignment($criterionA, $pic, 'pic');
        $this->assignment($criterionB, $reviewer, 'reviewer');
        $this->membership($project, $manager, 'project_manager');
        $this->membership($project, $viewer, 'viewer');

        $this->actingAs($pic);
        $picRepository = Livewire::test(Index::class)->assertSee('FILE-A.pdf')->assertDontSee('FILE-B.pdf')->assertDontSee('FILE-C.pdf')->assertSee('Upload Dokumen');
        $this->assertSame([$documentA->id], $picRepository->viewData('documents')->pluck('id')->all());

        $this->actingAs($reviewer);
        $reviewerRepository = Livewire::test(Index::class)->assertSee('FILE-B.pdf')->assertDontSee('FILE-A.pdf')->assertDontSee('FILE-C.pdf')->assertDontSee('Upload Dokumen');
        $this->assertSame([$documentB->id], $reviewerRepository->viewData('documents')->pluck('id')->all());

        $this->actingAs($manager);
        $managerRepository = Livewire::test(Index::class)->assertSee('FILE-A.pdf')->assertSee('FILE-B.pdf')->assertDontSee('FILE-C.pdf')->assertDontSee('Upload Dokumen');
        $this->assertEqualsCanonicalizing([$documentA->id, $documentB->id], $managerRepository->viewData('documents')->pluck('id')->all());

        $this->actingAs($viewer);
        $viewerRepository = Livewire::test(Index::class)->assertSee('FILE-A.pdf')->assertSee('FILE-B.pdf')->assertDontSee('FILE-C.pdf')->assertDontSee('Upload Dokumen');
        $this->assertEqualsCanonicalizing([$documentA->id, $documentB->id], $viewerRepository->viewData('documents')->pluck('id')->all());

        $this->actingAs($admin);
        $adminRepository = Livewire::withQueryParams(['proyek' => $outsideProject->id])->test(Index::class)->assertSee('FILE-C.pdf')->assertSee('Upload Dokumen');
        $this->assertSame([$documentC->id], $adminRepository->viewData('documents')->pluck('id')->all());

        $this->actingAs($pic);
        Livewire::withQueryParams(['proyek' => $outsideProject->id])->test(Index::class)->assertForbidden();
        $this->get(route('documents.download', $documentC))->assertForbidden();
        $this->assertSame($criterionC->id, $requirementC->item->criterion_id);
    }

    public function test_search_filters_sorting_and_server_side_pagination_use_document_metadata(): void
    {
        [$project, $criterionA, , $requirementA] = $this->structure('FILTERS', 'draft');
        $criterionB = $this->criterion($project, 'B', 2, 'Kriteria B');
        [, $requirementB] = $this->workItem($criterionB, 2, 'Analisis Mahasiswa', 'verified');
        $firstUploader = $this->userWithRole('PIC Kriteria');
        $secondUploader = $this->userWithRole('PIC Kriteria');

        foreach (range(1, 11) as $number) {
            $this->document(
                $requirementA,
                $firstUploader,
                sprintf('dokumen-%02d.pdf', $number),
                1,
                true,
                sprintf('2026-01-%02d 09:00:00', $number),
            );
        }

        $xlsx = $this->document($requirementB, $secondUploader, 'TARGET-ANALISIS.xlsx', 3, true, '2026-02-01 09:00:00', 'xlsx');
        $admin = $this->userWithRole('Super Admin');
        $this->actingAs($admin);

        $component = Livewire::test(Index::class);
        $this->assertSame(12, $component->viewData('documents')->total());
        $this->assertCount(10, $component->viewData('documents')->items());

        $component->call('setPage', 2);
        $this->assertCount(2, $component->viewData('documents')->items());

        $component->set('search', 'TARGET-ANALISIS');
        $this->assertSame([$xlsx->id], $component->viewData('documents')->pluck('id')->all());

        $component->set('search', 'Analisis Mahasiswa');
        $this->assertSame([$xlsx->id], $component->viewData('documents')->pluck('id')->all());

        $component->set('search', '')->set('criterionId', $criterionB->id);
        $this->assertSame(1, $component->viewData('documents')->total());

        $component->set('criterionId', null)->set('workflowStatus', 'verified');
        $this->assertSame([$xlsx->id], $component->viewData('documents')->pluck('id')->all());

        $component->set('workflowStatus', '')->set('extension', 'xlsx');
        $this->assertSame([$xlsx->id], $component->viewData('documents')->pluck('id')->all());

        $component->set('extension', '')->set('uploaderId', $secondUploader->id);
        $this->assertSame([$xlsx->id], $component->viewData('documents')->pluck('id')->all());

        $component->set('uploaderId', null)->set('sort', 'name_asc');
        $this->assertSame('TARGET-ANALISIS.xlsx', $component->viewData('documents')->first()->original_name);

        $component->call('resetFilters');
        $this->assertSame(12, $component->viewData('documents')->total());
        $this->assertSame($project->id, $component->viewData('project')->id);
    }

    public function test_virtual_folder_counts_and_selection_never_include_inaccessible_criteria(): void
    {
        [$project, $criterionA, $itemA, $requirementA] = $this->structure('FOLDERS');
        $secondRequirement = AccreditationDocumentRequirement::create([
            'item_id' => $itemA->id,
            'number' => 2,
            'title' => 'Rincian Kedua A',
            'is_required' => true,
            'workflow_status' => 'draft',
            'current_review_revision' => 0,
        ]);
        $criterionB = $this->criterion($project, 'B', 2, 'Kriteria Rahasia');
        [, $requirementB] = $this->workItem($criterionB, 2, 'Rincian Rahasia', 'draft');
        $uploader = $this->userWithRole('PIC Kriteria');
        $this->document($requirementA, $uploader, 'A-ONE.pdf');
        $this->document($requirementA, $uploader, 'A-TWO.pdf');
        $this->document($secondRequirement, $uploader, 'A-THREE.pdf');
        $this->document($requirementB, $uploader, 'B-SECRET.pdf');
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterionA, $pic, 'pic');
        $this->actingAs($pic);

        $component = Livewire::test(Index::class)
            ->assertSee('A-ONE.pdf')
            ->assertSee('A-TWO.pdf')
            ->assertSee('A-THREE.pdf')
            ->assertDontSee('B-SECRET.pdf')
            ->assertDontSee('Kriteria Rahasia');

        $treeCriterion = $component->viewData('criteria')->first();
        $this->assertSame(3, $component->viewData('folderCount'));
        $this->assertSame(3, $treeCriterion->repository_documents_count);
        $this->assertSame(3, $treeCriterion->items->first()->repository_documents_count);
        $this->assertSame(2, $treeCriterion->items->first()->requirements->first()->repository_documents_count);
        $this->assertSame(1, $treeCriterion->items->first()->requirements->last()->repository_documents_count);

        $component->call('selectFolder', 'requirement', $secondRequirement->id);
        $this->assertSame(['A-THREE.pdf'], $component->viewData('documents')->pluck('original_name')->all());

        $component->call('selectFolder', 'criterion', $criterionB->id)->assertForbidden();
    }

    public function test_private_actions_use_authorized_endpoints_and_office_files_fall_back_to_download(): void
    {
        [$project, , , $requirement] = $this->structure('ENDPOINTS', 'verified');
        $uploader = $this->userWithRole('PIC Kriteria');
        $pdf = $this->document($requirement, $uploader, 'native-preview.pdf', 1, true, '2026-01-01 09:00:00');
        $office = $this->document($requirement, $uploader, 'office-download.docx', 1, true, '2026-01-02 09:00:00', 'docx');
        $viewer = $this->userWithRole('Viewer');
        $this->membership($project, $viewer, 'viewer');
        Queue::fake();
        $this->actingAs($viewer);

        Livewire::test(Index::class)
            ->assertSee(route('documents.preview', ['document' => $pdf, 'asal' => 'repository']), false)
            ->assertSee(route('documents.download', $office), false)
            ->assertDontSee(route('documents.preview', ['document' => $office, 'asal' => 'repository']), false);
        Queue::assertNothingPushed();

        $this->get(route('documents.preview', ['document' => $pdf, 'asal' => 'repository']))
            ->assertOk()
            ->assertSee(route('repository.index', ['proyek' => $project->id, 'dokumen' => $pdf->id]))
            ->assertSee(route('documents.preview.content', $pdf), false);
        $this->get(route('documents.preview.content', $pdf))->assertOk();
        $this->get(route('documents.download', $office))->assertOk()->assertDownload('office-download.docx');
        $this->get(route('documents.preview.content', $office))->assertNotFound();

        $outsider = $this->userWithRole('Viewer');
        $this->actingAs($outsider);
        $this->get(route('documents.preview', $pdf))->assertForbidden();
        $this->get(route('documents.download', $office))->assertForbidden();
    }

    public function test_document_selection_outside_assigned_criterion_is_rejected(): void
    {
        [$project, $criterionA, , $requirementA] = $this->structure('TAMPER');
        $criterionB = $this->criterion($project, 'B', 2, 'Kriteria Terlarang');
        [, $requirementB] = $this->workItem($criterionB, 2, 'Rincian Terlarang', 'draft');
        $uploader = $this->userWithRole('PIC Kriteria');
        $visible = $this->document($requirementA, $uploader, 'VISIBLE.pdf');
        $hidden = $this->document($requirementB, $uploader, 'HIDDEN.pdf');
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterionA, $pic, 'pic');
        $this->actingAs($pic);

        Livewire::test(Index::class)
            ->assertSee('VISIBLE.pdf')
            ->assertDontSee('HIDDEN.pdf')
            ->call('selectDocument', $hidden->id)
            ->assertForbidden();

        $this->assertSame($visible->sha256, $visible->fresh()->sha256);
    }

    public function test_forged_repository_filter_is_rejected(): void
    {
        [, $criterion] = $this->structure('FORGED-FILTER');
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, 'pic');
        $this->actingAs($pic);

        Livewire::test(Index::class)
            ->set('workflowStatus', 'forged_status')
            ->assertForbidden();
    }

    public function test_revoked_assignment_blocks_an_existing_repository_session(): void
    {
        [, $criterion, , $requirement] = $this->structure('REVOKED');
        $uploader = $this->userWithRole('PIC Kriteria');
        $document = $this->document($requirement, $uploader, 'REVOKED.pdf');
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, 'pic');
        $this->actingAs($pic);

        $component = Livewire::test(Index::class)->assertSee('REVOKED.pdf');
        AccreditationCriterionAssignment::query()
            ->where('criterion_id', $criterion->id)
            ->where('user_id', $pic->id)
            ->update(['is_active' => false]);
        $component->call('$refresh')->assertForbidden();

        $this->assertSame($document->sha256, $document->fresh()->sha256);
    }

    private function structure(string $suffix, string $status = 'draft'): array
    {
        $program = StudyProgram::create([
            'code' => 'PS-'.$suffix,
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
        $criterion = $this->criterion($project, 'A', 1, 'Visi, Misi, Tujuan dan Strategi');
        [$item, $requirement] = $this->workItem($criterion, 2, 'Dokumen Tata Kelola', $status);

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
        string $status,
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
            'workflow_status' => $status,
            'current_review_revision' => $status === 'need_revision' ? 2 : 1,
        ]);

        return [$item, $requirement];
    }

    private function document(
        AccreditationDocumentRequirement $requirement,
        User $uploader,
        string $name,
        int $revision = 1,
        bool $active = true,
        string $uploadedAt = '2026-01-01 09:00:00',
        string $extension = 'pdf',
    ): AccreditationDocument {
        $uuid = (string) Str::uuid();
        $contents = $uuid.'-'.$name;
        $path = "accreditation/repository/{$uuid}.{$extension}";
        Storage::disk('local')->put($path, $contents);

        return AccreditationDocument::create([
            'requirement_id' => $requirement->id,
            'uploaded_by' => $uploader->id,
            'original_name' => $name,
            'disk' => 'local',
            'storage_path' => $path,
            'storage_uuid' => $uuid,
            'mime_type' => match ($extension) {
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                default => 'application/pdf',
            },
            'extension' => $extension,
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'revision_number' => $revision,
            'is_active' => $active,
            'uploaded_at' => $uploadedAt,
            'superseded_at' => $active ? null : $uploadedAt,
        ]);
    }

    private function submission(
        AccreditationDocumentRequirement $requirement,
        User $submitter,
        int $revision,
        string $status,
        array $documents,
    ): AccreditationReviewSubmission {
        $submission = AccreditationReviewSubmission::create([
            'requirement_id' => $requirement->id,
            'submitted_by' => $submitter->id,
            'revision_number' => $revision,
            'status' => $status,
            'submitted_at' => now(),
            'decided_at' => $status === 'pending_review' ? null : now(),
            'decided_by' => null,
            'review_note' => null,
        ]);
        $submission->documents()->attach(collect($documents)->pluck('id'));

        return $submission;
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

    private function assignment(AccreditationCriterion $criterion, User $user, string $role): void
    {
        AccreditationCriterionAssignment::create([
            'criterion_id' => $criterion->id,
            'user_id' => $user->id,
            'assignment_role' => $role,
            'is_active' => true,
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
}
