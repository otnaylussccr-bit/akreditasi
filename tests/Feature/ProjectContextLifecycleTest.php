<?php

namespace Tests\Feature;

use App\Models\AccreditationActivityLog;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationCriterionAssignment;
use App\Models\AccreditationDocument;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationItem;
use App\Models\AccreditationProject;
use App\Models\AccreditationProjectMember;
use App\Models\AccreditationReviewEvent;
use App\Models\AccreditationReviewSubmission;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\AccreditationDocumentUploadService;
use App\Services\AccreditationProjectContextService;
use App\Services\AccreditationProjectLifecycleService;
use App\Services\AccreditationReviewWorkflowService;
use Database\Seeders\AccreditationRolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectContextLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_explicit_authorized_project_is_persisted_across_operational_modules(): void
    {
        $program = $this->program('CONTINUITY');
        [$firstProject, , , $firstRequirement] = $this->structure(
            $program,
            'CONTEXT-FIRST',
            'active',
            '2026-10-20',
        );
        [$selectedProject, , , $selectedRequirement] = $this->structure(
            $program,
            'CONTEXT-SELECTED',
            'active',
            '2026-11-20',
        );
        $admin = $this->userWithRole('Super Admin');
        $this->document($firstRequirement, $admin, 'foreign-project.pdf');
        $this->document($selectedRequirement, $admin, 'selected-project.pdf');

        $this->actingAs($admin)
            ->get(route('dashboard', ['proyek' => $selectedProject->id]))
            ->assertOk()
            ->assertSee($selectedProject->code)
            ->assertSessionHas(AccreditationProjectContextService::SESSION_KEY, $selectedProject->id);

        $this->get(route('follow-up-led.index'))
            ->assertOk()
            ->assertSee($selectedRequirement->title)
            ->assertDontSee($firstRequirement->title)
            ->assertSessionHas(AccreditationProjectContextService::SESSION_KEY, $selectedProject->id);
        $this->get(route('repository.index'))
            ->assertOk()
            ->assertSee('selected-project.pdf')
            ->assertDontSee('foreign-project.pdf');
        $this->get(route('documents.upload', $selectedRequirement))
            ->assertOk()
            ->assertSessionHas(AccreditationProjectContextService::SESSION_KEY, $selectedProject->id);

        $this->assertNotSame($firstProject->id, session(AccreditationProjectContextService::SESSION_KEY));
    }

    public function test_unauthorized_explicit_project_is_forbidden_and_not_persisted(): void
    {
        $program = $this->program('EXPLICIT-SCOPE');
        [$authorizedProject, $authorizedCriterion] = $this->structure($program, 'AUTHORIZED');
        [$outsideProject] = $this->structure($program, 'OUTSIDE');
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($authorizedCriterion, $pic, 'pic');

        $this->actingAs($pic)
            ->withSession([AccreditationProjectContextService::SESSION_KEY => $authorizedProject->id])
            ->get(route('dashboard', ['proyek' => $outsideProject->id]))
            ->assertForbidden()
            ->assertSessionHas(AccreditationProjectContextService::SESSION_KEY, $authorizedProject->id);
    }

    public function test_revoked_session_project_is_ignored_in_favor_of_an_authorized_active_project(): void
    {
        $program = $this->program('STALE');
        [$revokedProject, $revokedCriterion] = $this->structure($program, 'REVOKED');
        [$fallbackProject, $fallbackCriterion] = $this->structure($program, 'FALLBACK');
        $pic = $this->userWithRole('PIC Kriteria');
        $revokedAssignment = $this->assignment($revokedCriterion, $pic, 'pic');
        $this->assignment($fallbackCriterion, $pic, 'pic');
        $revokedAssignment->update(['is_active' => false]);

        $this->actingAs($pic)
            ->withSession([AccreditationProjectContextService::SESSION_KEY => $revokedProject->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($fallbackProject->code)
            ->assertSessionHas(AccreditationProjectContextService::SESSION_KEY, $fallbackProject->id);
    }

    public function test_default_prefers_active_then_uses_deterministic_historical_fallback(): void
    {
        $program = $this->program('DEFAULT');
        [$completed] = $this->structure($program, 'COMPLETED', 'completed', '2026-12-31');
        [$archived] = $this->structure($program, 'ARCHIVED', 'archived', '2027-01-31');
        [$active] = $this->structure($program, 'ACTIVE', 'active', '2026-11-30');
        [$earlierActive] = $this->structure($program, 'ACTIVE-EARLIER', 'active', '2026-10-30');
        $admin = $this->userWithRole('Super Admin');

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSessionHas(AccreditationProjectContextService::SESSION_KEY, $earlierActive->id);

        $active->delete();
        $earlierActive->delete();
        $this->withSession([AccreditationProjectContextService::SESSION_KEY => $earlierActive->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($archived->code)
            ->assertSessionHas(AccreditationProjectContextService::SESSION_KEY, $archived->id);

        $this->assertNotSame($completed->id, session(AccreditationProjectContextService::SESSION_KEY));
    }

    public function test_resource_specific_review_page_synchronizes_its_authorized_project(): void
    {
        $program = $this->program('RESOURCE');
        [$firstProject] = $this->structure($program, 'RESOURCE-FIRST');
        [$targetProject, $criterion, , $requirement] = $this->structure($program, 'RESOURCE-TARGET');
        $pic = $this->userWithRole('PIC Kriteria');
        $reviewer = $this->userWithRole('Reviewer');
        $this->assignment($criterion, $reviewer, 'reviewer');
        $document = $this->document($requirement, $pic);
        $submission = $this->submission($requirement, $pic, $document);

        $this->actingAs($reviewer)
            ->withSession([AccreditationProjectContextService::SESSION_KEY => $firstProject->id])
            ->get(route('verifications.show', $submission))
            ->assertOk()
            ->assertSessionHas(AccreditationProjectContextService::SESSION_KEY, $targetProject->id);
    }

    public function test_non_active_and_unknown_projects_fail_closed_for_every_workflow_mutation(): void
    {
        foreach (['draft', 'completed', 'archived', 'unexpected'] as $status) {
            [$project, $criterion, , $uploadRequirement] = $this->structure(
                $this->program('LIFE-'.$status),
                'LIFE-'.strtoupper($status),
                $status,
            );
            $submitRequirement = $this->requirement($uploadRequirement->item, 2, 'draft');
            $decisionRequirement = $this->requirement($uploadRequirement->item, 3, 'pending_review', 1);
            $pic = $this->userWithRole('PIC Kriteria');
            $reviewer = $this->userWithRole('Reviewer');
            $this->assignment($criterion, $pic, 'pic');
            $this->assignment($criterion, $reviewer, 'reviewer');
            $submitDocument = $this->document($submitRequirement, $pic, 'submit-'.$status.'.pdf');
            $decisionDocument = $this->document($decisionRequirement, $pic, 'decision-'.$status.'.pdf');
            $submission = $this->submission($decisionRequirement, $pic, $decisionDocument);
            $before = $this->workflowCounts();

            $this->assertFalse(app(AccreditationProjectLifecycleService::class)->allowsWorkflowMutation($project));
            $this->assertFalse(Gate::forUser($pic)->allows('upload', $uploadRequirement));
            $this->assertFalse(Gate::forUser($pic)->allows('submit', $criterion));
            $this->assertFalse(Gate::forUser($reviewer)->allows('verify', $submission));
            $this->assertFalse(Gate::forUser($reviewer)->allows('requestRevision', $submission));

            $this->actingAs($pic);
            $this->assertAuthorizationDenied(fn () => app(AccreditationDocumentUploadService::class)->uploadBatch(
                $uploadRequirement,
                $pic,
                [UploadedFile::fake()->createWithContent('blocked.pdf', $this->pdfContents('blocked'))],
            ));
            $this->assertAuthorizationDenied(fn () => app(AccreditationReviewWorkflowService::class)
                ->submit($submitRequirement, $pic));

            $this->actingAs($reviewer);
            $this->assertAuthorizationDenied(fn () => app(AccreditationReviewWorkflowService::class)
                ->decide($submission, $reviewer, 'verified', null));

            $this->assertSame($before, $this->workflowCounts());
            $this->assertSame('pending_review', $submission->fresh()->status);
            $this->assertSame('pending_review', $decisionRequirement->fresh()->workflow_status);
            Storage::disk('local')->assertMissing('blocked.pdf');

            $this->actingAs($pic)
                ->get(route('documents.upload', $uploadRequirement))
                ->assertOk()
                ->assertSee('Project dalam mode baca-saja')
                ->assertDontSee('wire:submit="saveDocuments"', false);

            $this->actingAs($reviewer)
                ->get(route('verifications.show', $submission))
                ->assertOk()
                ->assertSee('Project dalam mode baca-saja')
                ->assertSee('Mode Pemantauan')
                ->assertDontSee('Tinjau dan Konfirmasi');
        }
    }

    public function test_historical_project_pages_and_private_document_access_remain_readable(): void
    {
        [$project, , , $requirement] = $this->structure(
            $this->program('HISTORY'),
            'HISTORY',
            'archived',
        );
        $manager = $this->userWithRole('Project Manager');
        $this->membership($project, $manager, 'project_manager');
        $document = $this->document($requirement, $manager, 'historical.pdf');

        $this->actingAs($manager);
        $this->get(route('dashboard', ['proyek' => $project->id]))->assertOk();
        $this->get(route('follow-up-led.index', ['proyek' => $project->id]))->assertOk();
        $this->get(route('repository.index', ['proyek' => $project->id]))
            ->assertOk()
            ->assertSee('historical.pdf')
            ->assertDontSee('+ Upload Dokumen');
        $this->get(route('documents.preview', $document))
            ->assertOk()
            ->assertSee('historical.pdf');
        $this->get(route('documents.download', $document))->assertOk();
    }

    private function assertAuthorizationDenied(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Mutasi workflow seharusnya ditolak oleh lifecycle project.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function workflowCounts(): array
    {
        return [
            AccreditationDocument::count(),
            AccreditationReviewSubmission::count(),
            AccreditationReviewEvent::count(),
            AccreditationActivityLog::count(),
        ];
    }

    private function program(string $suffix): StudyProgram
    {
        return StudyProgram::create([
            'code' => 'PRODI-'.$suffix,
            'name' => 'Program Studi '.$suffix,
            'is_active' => true,
        ]);
    }

    private function structure(
        StudyProgram $program,
        string $code,
        string $status = 'active',
        string $deadline = '2026-10-30',
    ): array {
        $project = AccreditationProject::create([
            'code' => $code,
            'name' => 'Akreditasi '.$code,
            'study_program_id' => $program->id,
            'final_deadline' => $deadline,
            'status' => $status,
        ]);
        $criterion = AccreditationCriterion::create([
            'project_id' => $project->id,
            'code' => 'A',
            'name' => 'Kriteria '.$code,
            'color_header' => '#173E70',
            'color_body' => '#E7EEF8',
            'position' => 1,
        ]);
        $item = AccreditationItem::create([
            'criterion_id' => $criterion->id,
            'number' => 2,
            'position' => 2,
            'title' => 'Butir '.$code,
            'target_deadline' => $deadline,
        ]);
        $requirement = $this->requirement($item, 1, 'draft');

        return [$project, $criterion, $item, $requirement];
    }

    private function requirement(
        AccreditationItem $item,
        int $number,
        string $status,
        int $revision = 0,
    ): AccreditationDocumentRequirement {
        return AccreditationDocumentRequirement::create([
            'item_id' => $item->id,
            'number' => $number,
            'title' => 'Rincian '.$item->title.' '.$number,
            'is_required' => true,
            'workflow_status' => $status,
            'current_review_revision' => $revision,
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

    private function assignment(
        AccreditationCriterion $criterion,
        User $user,
        string $role,
    ): AccreditationCriterionAssignment {
        return AccreditationCriterionAssignment::create([
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

    private function document(
        AccreditationDocumentRequirement $requirement,
        User $uploader,
        string $name = 'evidence.pdf',
    ): AccreditationDocument {
        $contents = $this->pdfContents($name);
        $uuid = (string) Str::uuid();
        $path = "accreditation/{$requirement->id}/{$uuid}.pdf";
        Storage::disk('local')->put($path, $contents, ['visibility' => 'private']);

        return AccreditationDocument::create([
            'requirement_id' => $requirement->id,
            'uploaded_by' => $uploader->id,
            'original_name' => $name,
            'disk' => 'local',
            'storage_path' => $path,
            'storage_uuid' => $uuid,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'revision_number' => 1,
            'is_active' => true,
            'uploaded_at' => now(),
        ]);
    }

    private function submission(
        AccreditationDocumentRequirement $requirement,
        User $submitter,
        AccreditationDocument $document,
    ): AccreditationReviewSubmission {
        $submission = AccreditationReviewSubmission::create([
            'requirement_id' => $requirement->id,
            'submitted_by' => $submitter->id,
            'revision_number' => 1,
            'status' => 'pending_review',
            'submitted_at' => now(),
        ]);
        $submission->documents()->attach($document);

        return $submission;
    }

    private function pdfContents(string $marker): string
    {
        return "%PDF-1.4\n"
            ."1 0 obj\n<< /Type /Catalog /Marker ({$marker}) >>\nendobj\n"
            ."xref\n0 2\n0000000000 65535 f \n0000000009 00000 n \n"
            ."trailer\n<< /Size 2 /Root 1 0 R >>\nstartxref\n64\n%%EOF\n";
    }
}
