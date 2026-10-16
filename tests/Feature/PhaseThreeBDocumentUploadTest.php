<?php

namespace Tests\Feature;

use App\Livewire\Documents\Upload;
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
use App\Services\AccreditationDocumentUploadService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccreditationRolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class PhaseThreeBDocumentUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_guest_cannot_open_upload_or_document_endpoints(): void
    {
        [, , , $requirement] = $this->context();
        $document = $this->storedDocument($requirement, $this->userWithRole('PIC Kriteria'));

        $this->get(route('documents.upload', $requirement))->assertRedirect(route('login'));
        $this->get(route('documents.preview', $document))->assertRedirect(route('login'));
        $this->get(route('documents.preview.content', $document))->assertRedirect(route('login'));
        $this->get(route('documents.download', $document))->assertRedirect(route('login'));
    }

    public function test_active_pic_can_upload_with_private_metadata_checksum_status_and_audit_log(): void
    {
        [$project, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, true);
        $contents = $this->pdfContents('secure-statuta');

        $this->actingAs($pic);
        Livewire::test(Upload::class, ['requirement' => $requirement])
            ->set('files', [$this->pdf('Statuta_2024.pdf', $contents)])
            ->call('saveDocuments')
            ->assertHasNoErrors()
            ->assertSee('1 file berhasil disimpan secara privat.');

        $document = AccreditationDocument::sole();
        $requirement->refresh();

        $this->assertSame($requirement->id, $document->requirement_id);
        $this->assertSame($pic->id, $document->uploaded_by);
        $this->assertSame('Statuta_2024.pdf', $document->original_name);
        $this->assertSame('local', $document->disk);
        $this->assertSame('pdf', $document->extension);
        $this->assertSame(hash('sha256', $contents), $document->sha256);
        $this->assertSame(1, $document->revision_number);
        $this->assertTrue($document->is_active);
        $this->assertNotNull($document->uploaded_at);
        $this->assertSame('draft', $requirement->workflow_status);
        $this->assertStringStartsWith(
            'accreditation/study-programs/bioteknologi-',
            $document->storage_path,
        );
        $this->assertStringContainsString("--id-{$project->study_program_id}/projects/{$project->code}--id-{$project->id}/criteria/{$criterion->code}/items/{$criterion->code}.2--id-{$requirement->item_id}/requirements/R1--id-{$requirement->id}/revisions/v1/", $document->storage_path);
        $this->assertStringContainsString($document->storage_uuid, $document->storage_path);
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
        $this->assertFalse(config('filesystems.disks.local.serve'));
        Storage::disk('public')->assertMissing($document->storage_path);
        $this->assertDatabaseHas('accreditation_activity_logs', [
            'project_id' => $project->id,
            'actor_id' => $pic->id,
            'event' => 'document.uploaded',
            'subject_type' => AccreditationDocument::class,
            'subject_id' => $document->id,
        ]);
    }

    public function test_new_pic_upload_keeps_utc_storage_and_displays_the_actual_wib_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'UTC'));
        [, $criterion, , $requirement] = $this->context('TIMEZONE');
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, true);
        $this->actingAs($pic);

        Livewire::test(Upload::class, ['requirement' => $requirement])
            ->set('files', [$this->pdf('wib-upload.pdf')])
            ->call('saveDocuments')
            ->assertHasNoErrors()
            ->assertSee('16 Oct 2026, 16:00 WIB');

        $document = AccreditationDocument::sole();
        $activity = AccreditationActivityLog::sole();

        $this->assertSame('2026-10-16 09:00:00 +00:00', $document->uploaded_at->format('Y-m-d H:i:s P'));
        $this->assertTrue($document->uploaded_at->equalTo($activity->created_at));
    }

    public function test_pic_cannot_upload_to_another_criterion_or_project(): void
    {
        [, $assignedCriterion] = $this->context('ASSIGNED');
        [, , , $outsideRequirement] = $this->context('OUTSIDE');
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($assignedCriterion, $pic, true);

        $this->actingAs($pic)
            ->get(route('documents.upload', $outsideRequirement))
            ->assertForbidden();

        $this->assertDatabaseCount('accreditation_documents', 0);
    }

    public function test_inactive_pic_assignment_is_rejected_and_revocation_blocks_existing_component(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $assignment = $this->assignment($criterion, $pic, true);
        $this->actingAs($pic);
        $component = Livewire::test(Upload::class, ['requirement' => $requirement])
            ->set('files', [$this->pdf('blocked.pdf')]);

        $assignment->update(['is_active' => false]);

        $component
            ->call('saveDocuments')
            ->assertForbidden();

        $this->get(route('documents.upload', $requirement))->assertForbidden();
        $this->assertDatabaseCount('accreditation_documents', 0);
    }

    public function test_super_admin_can_emergency_upload_without_pic_assignment(): void
    {
        [$project, , , $requirement] = $this->context();
        $admin = $this->userWithRole('Super Admin');
        $contents = $this->pdfContents('emergency-upload');

        $this->actingAs($admin);
        Livewire::test(Upload::class, ['requirement' => $requirement])
            ->set('files', [$this->pdf('Emergency.pdf', $contents)])
            ->call('saveDocuments')
            ->assertHasNoErrors();

        $document = AccreditationDocument::sole();
        $activity = AccreditationActivityLog::sole();

        $this->assertSame($admin->id, $document->uploaded_by);
        $this->assertSame(hash('sha256', $contents), $document->sha256);
        $this->assertSame('draft', $requirement->fresh()->workflow_status);
        $this->assertSame('document.emergency_uploaded', $activity->event);
        $this->assertSame($admin->id, $activity->actor_id);
        $this->assertSame('super_admin_emergency', $activity->new_values['upload_mode']);
        $this->assertFalse($admin->can('document.verify'));
        $this->assertDatabaseMissing('accreditation_criterion_assignments', [
            'criterion_id' => $requirement->item->criterion_id,
            'user_id' => $admin->id,
        ]);
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertSame($project->id, $activity->project_id);

        $this->get(route('documents.index'))
            ->assertOk()
            ->assertSee('1</strong> file', false)
            ->assertSee(route('documents.preview', $document), false);
    }

    public function test_reviewer_and_viewer_without_active_pic_assignment_cannot_upload(): void
    {
        [$project, $criterion, , $requirement] = $this->context();
        $reviewer = $this->userWithRole('Reviewer');
        $this->assignment($criterion, $reviewer, true, 'reviewer');
        $viewer = $this->userWithRole('Viewer');
        $this->membership($project, $viewer, 'viewer');

        foreach ([$reviewer, $viewer] as $user) {
            $this->actingAs($user)
                ->get(route('documents.upload', $requirement))
                ->assertForbidden();
        }
    }

    public function test_multiple_roles_still_require_active_pic_assignment(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $user = $this->userWithRole('Reviewer');
        $user->assignRole('PIC Kriteria');
        $this->assignment($criterion, $user, false);

        $this->actingAs($user)
            ->get(route('documents.upload', $requirement))
            ->assertForbidden();
    }

    public function test_biotechnology_header_led_is_rejected_by_backend(): void
    {
        [, $criterion, , $requirement] = $this->context('APU-BIOTEK-2026', 1);
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, true);
        $admin = $this->userWithRole('Super Admin');

        foreach ([$pic, $admin] as $user) {
            $this->actingAs($user)
                ->get(route('documents.upload', $requirement))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('accreditation_documents', 0);
    }

    public function test_multiple_files_create_independent_records_for_one_requirement(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, true);
        $this->actingAs($pic);

        $component = Livewire::test(Upload::class, ['requirement' => $requirement])
            ->set('files', [
                $this->pdf('Statuta_2024.pdf', $this->pdfContents('first')),
                $this->pdf('SK_Penetapan_Statuta.pdf', $this->pdfContents('second')),
                $this->pdf('Lampiran_Statuta.pdf', $this->pdfContents('third')),
                $this->pdf('Bukti_Sosialisasi.pdf', $this->pdfContents('fourth')),
            ])
            ->call('saveDocuments')
            ->assertHasNoErrors()
            ->assertSet('files', [])
            ->assertSee('4 file berhasil disimpan secara privat.')
            ->assertSee('Statuta_2024.pdf')
            ->assertSee('Bukti_Sosialisasi.pdf');

        $documents = AccreditationDocument::all();
        $this->assertCount(4, $documents);
        $this->assertSame([$requirement->id], $documents->pluck('requirement_id')->unique()->values()->all());
        $this->assertCount(4, $documents->pluck('storage_uuid')->unique());
        $this->assertCount(4, $documents->pluck('storage_path')->unique());
        $this->assertSame([1], $documents->pluck('revision_number')->unique()->values()->all());
        $this->assertDatabaseCount('accreditation_activity_logs', 4);
        $this->assertSame('draft', $requirement->fresh()->workflow_status);
        $this->assertSame([
            'total' => 4,
            'active' => 4,
            'historical' => 0,
        ], $component->viewData('documentStats'));
    }

    public function test_four_files_are_ready_in_one_temporary_state_before_submit(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, true);
        $this->actingAs($pic);
        $names = ['one.pdf', 'two.pdf', 'three.pdf', 'four.pdf'];

        $component = Livewire::test(Upload::class, ['requirement' => $requirement])
            ->set('files', array_map(fn (string $name) => $this->pdf($name), $names))
            ->assertHasNoErrors()
            ->assertSet('files', fn (array $files): bool => count($files) === 4
                && collect($files)->every(fn ($file): bool => $file instanceof TemporaryUploadedFile))
            ->assertSee('4 / 10 file');

        foreach ($names as $name) {
            $component->assertSee($name);
        }

        $this->assertDatabaseCount('accreditation_documents', 0);
        $this->assertSame('not_started', $requirement->fresh()->workflow_status);
    }

    public function test_temporary_file_can_be_removed_and_remaining_queue_stays_valid(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, true);
        $this->actingAs($pic);

        Livewire::test(Upload::class, ['requirement' => $requirement])
            ->set('files', [
                $this->pdf('keep-first.pdf'),
                $this->pdf('remove-me.pdf'),
                $this->pdf('keep-last.pdf'),
            ])
            ->assertHasNoErrors()
            ->call('removeFile', 1)
            ->assertHasNoErrors()
            ->assertSet('files', fn (array $files): bool => count($files) === 2
                && $files[0]->getClientOriginalName() === 'keep-first.pdf'
                && $files[1]->getClientOriginalName() === 'keep-last.pdf')
            ->assertSee('2 / 10 file')
            ->assertDontSee('remove-me.pdf');

        $this->assertDatabaseCount('accreditation_documents', 0);
    }

    public function test_spoofed_and_disallowed_formats_are_rejected(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, true);
        $this->actingAs($pic);

        Livewire::test(Upload::class, ['requirement' => $requirement])
            ->set('files', [UploadedFile::fake()->createWithContent('malware.pdf', "%PDF-1.4\n<script>alert(1)</script>")])
            ->call('saveDocuments')
            ->assertHasErrors(['files.0']);

        Livewire::test(Upload::class, ['requirement' => $requirement])
            ->set('files', [UploadedFile::fake()->createWithContent('program.php', '<?php exit;')])
            ->call('saveDocuments')
            ->assertHasErrors(['files.0']);

        $this->assertDatabaseCount('accreditation_documents', 0);
    }

    public function test_files_over_fifty_megabytes_and_batches_over_ten_are_rejected(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, true);
        $this->actingAs($pic);

        Livewire::test(Upload::class, ['requirement' => $requirement])
            ->set('files', [UploadedFile::fake()->create('too-large.pdf', 51201, 'application/pdf')])
            ->assertHasErrors(['files.0']);

        $files = [];
        foreach (range(1, 11) as $number) {
            $files[] = $this->pdf("document-{$number}.pdf");
        }

        Livewire::test(Upload::class, ['requirement' => $requirement])
            ->set('files', $files)
            ->assertHasErrors(['files'])
            ->assertSet('files', fn (array $queuedFiles): bool => count($queuedFiles) === 11)
            ->assertSee('Maksimal 10 file dalam satu batch upload.');

        $this->assertDatabaseCount('accreditation_documents', 0);
    }

    public function test_upload_view_uses_native_livewire_multiple_binding_without_manual_queueing(): void
    {
        $blade = File::get(resource_path('views/livewire/documents/upload.blade.php'));

        $this->assertStringContainsString('wire:model="files"', $blade);
        $this->assertStringContainsString('wire:submit="saveDocuments"', $blade);
        $this->assertStringContainsString('wire:target="saveDocuments"', $blade);
        $this->assertStringContainsString('x-on:livewire-upload-start="temporaryUploading = true"', $blade);
        $this->assertStringContainsString('x-on:livewire-upload-error="temporaryUploading = false"', $blade);
        $this->assertStringContainsString('x-bind:disabled="temporaryUploading', $blade);
        $this->assertStringNotContainsString('$wire.uploadMultiple', $blade);
        $this->assertStringNotContainsString('selected.forEach', $blade);
        $this->assertStringNotContainsString('wire:submit="upload"', $blade);
        $this->assertStringNotContainsString('wire:target="upload"', $blade);
        $this->assertTrue(method_exists(Upload::class, 'saveDocuments'));
        $this->assertFalse(method_exists(Upload::class, 'upload'));
        $this->assertSame(['required', 'file', 'max:51200'], config('livewire.temporary_file_upload.rules'));
    }

    public function test_upload_never_overwrites_an_existing_file_with_the_same_original_name(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, true);
        $this->actingAs($pic);

        foreach ([$this->pdfContents('old'), $this->pdfContents('new')] as $contents) {
            Livewire::test(Upload::class, ['requirement' => $requirement->fresh()])
                ->set('files', [$this->pdf('same-name.pdf', $contents)])
                ->call('saveDocuments')
                ->assertHasNoErrors();
        }

        $documents = AccreditationDocument::orderBy('id')->get();
        $this->assertCount(2, $documents);
        $this->assertNotSame($documents[0]->storage_path, $documents[1]->storage_path);
        Storage::disk('local')->assertExists($documents[0]->storage_path);
        Storage::disk('local')->assertExists($documents[1]->storage_path);
        $this->assertNotSame($documents[0]->sha256, $documents[1]->sha256);
    }

    public function test_pending_review_and_verified_are_locked_while_need_revision_is_preserved(): void
    {
        foreach (['pending_review', 'verified'] as $status) {
            [, $criterion, , $requirement] = $this->context(strtoupper($status), 2, $status, 1);
            $pic = $this->userWithRole('PIC Kriteria');
            $this->assignment($criterion, $pic, true);
            $this->actingAs($pic);

            $this->get(route('documents.upload', $requirement))
                ->assertOk()
                ->assertSee('Upload dikunci');

            Livewire::test(Upload::class, ['requirement' => $requirement])
                ->set('files', [$this->pdf("{$status}.pdf")])
                ->call('saveDocuments')
                ->assertForbidden();

            $this->assertSame($status, $requirement->fresh()->workflow_status);
        }

        [, $criterion, , $revisionRequirement] = $this->context('REVISION', 2, 'need_revision', 2);
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, true);
        $this->actingAs($pic);

        Livewire::test(Upload::class, ['requirement' => $revisionRequirement])
            ->set('files', [$this->pdf('revision-evidence.pdf')])
            ->call('saveDocuments')
            ->assertHasNoErrors();

        $this->assertSame('need_revision', $revisionRequirement->fresh()->workflow_status);
        $this->assertSame(3, AccreditationDocument::latest('id')->first()->revision_number);
        $this->assertSame(2, $revisionRequirement->fresh()->current_review_revision);
    }

    public function test_pending_review_and_verified_remain_locked_for_super_admin(): void
    {
        foreach (['pending_review', 'verified'] as $status) {
            [, , , $requirement] = $this->context('ADMIN-'.strtoupper($status), 2, $status, 1);
            $admin = $this->userWithRole('Super Admin');
            $this->actingAs($admin);

            $this->get(route('documents.upload', $requirement))
                ->assertOk()
                ->assertSee('Upload dikunci');

            Livewire::test(Upload::class, ['requirement' => $requirement])
                ->set('files', [$this->pdf("admin-{$status}.pdf")])
                ->call('saveDocuments')
                ->assertForbidden();

            $this->assertSame($status, $requirement->fresh()->workflow_status);
        }

        $this->assertDatabaseCount('accreditation_documents', 0);
    }

    public function test_emergency_uploader_cannot_verify_own_document_even_as_assigned_reviewer(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $admin = $this->userWithRole('Super Admin');
        $admin->assignRole('Reviewer');
        $this->assignment($criterion, $admin, true, 'reviewer');
        $submitter = $this->userWithRole('PIC Kriteria');
        $this->actingAs($admin);

        Livewire::test(Upload::class, ['requirement' => $requirement])
            ->set('files', [$this->pdf('emergency-self-review.pdf')])
            ->call('saveDocuments')
            ->assertHasNoErrors();

        $document = AccreditationDocument::sole();
        $requirement->update(['workflow_status' => 'pending_review']);
        $submission = AccreditationReviewSubmission::create([
            'requirement_id' => $requirement->id,
            'submitted_by' => $submitter->id,
            'revision_number' => 1,
            'status' => 'pending_review',
            'submitted_at' => now(),
        ]);
        $submission->documents()->attach($document->id);

        $this->assertFalse($admin->can('verify', $submission));
        $this->assertSame('pending_review', $submission->fresh()->status);
        $this->assertNull($submission->decided_by);
    }

    public function test_authorized_users_can_preview_and_download_but_outsiders_cannot(): void
    {
        [$project, , , $requirement] = $this->context();
        $uploader = $this->userWithRole('PIC Kriteria');
        $document = $this->storedDocument($requirement, $uploader);
        $manager = $this->userWithRole('Project Manager');
        $this->membership($project, $manager, 'project_manager');
        $outsider = $this->userWithRole('Viewer');

        $this->actingAs($manager)
            ->get(route('documents.preview', $document))
            ->assertOk()
            ->assertViewIs('documents.preview')
            ->assertSee($document->original_name)
            ->assertSee(route('documents.preview.content', $document), false)
            ->assertSee(route('documents.download', $document), false)
            ->assertDontSee($document->storage_path);

        $contentResponse = $this->get(route('documents.preview.content', $document));
        $contentResponse
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('content-security-policy', "sandbox; default-src 'none'");
        $this->assertStringStartsWith('inline;', $contentResponse->headers->get('content-disposition'));

        $this->get(route('documents.download', $document))
            ->assertOk()
            ->assertDownload('evidence.pdf');

        $this->actingAs($outsider)
            ->get(route('documents.preview', $document))
            ->assertForbidden();
        $this->actingAs($outsider)
            ->get(route('documents.preview.content', $document))
            ->assertForbidden();
        $this->actingAs($outsider)
            ->get(route('documents.download', $document))
            ->assertForbidden();

        $this->get('/storage/'.$document->storage_path)->assertNotFound();
    }

    public function test_jpeg_and_png_preview_content_is_streamed_inline_with_canonical_content_type(): void
    {
        [$project, , , $requirement] = $this->context();
        $uploader = $this->userWithRole('PIC Kriteria');
        $manager = $this->userWithRole('Project Manager');
        $this->membership($project, $manager, 'project_manager');
        $this->actingAs($manager);

        foreach ([
            ['photo.jpeg', 'jpeg', 'image/jpeg'],
            ['diagram.png', 'png', 'image/png'],
        ] as [$name, $extension, $mimeType]) {
            $document = $this->storedDocument(
                $requirement,
                $uploader,
                originalName: $name,
                mimeType: $mimeType,
                extension: $extension,
                contents: $extension.'-content',
            );

            $this->get(route('documents.preview', $document))
                ->assertOk()
                ->assertSee(route('documents.preview.content', $document), false)
                ->assertDontSee($document->storage_path);

            $response = $this->get(route('documents.preview.content', $document));
            $response->assertOk()->assertHeader('content-type', $mimeType);
            $this->assertStringStartsWith('inline;', $response->headers->get('content-disposition'));
        }
    }

    public function test_office_documents_open_metadata_view_without_automatic_download(): void
    {
        [$project, $criterion, $item, $requirement] = $this->context();
        $uploader = $this->userWithRole('PIC Kriteria');
        $manager = $this->userWithRole('Project Manager');
        $this->membership($project, $manager, 'project_manager');
        $this->actingAs($manager);

        foreach ([
            ['supporting-document.docx', 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['supporting-data.xlsx', 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        ] as [$name, $extension, $mimeType]) {
            $document = $this->storedDocument(
                $requirement,
                $uploader,
                originalName: $name,
                mimeType: $mimeType,
                extension: $extension,
                contents: $extension.'-content',
            );

            $response = $this->get(route('documents.preview', $document));
            $response
                ->assertOk()
                ->assertViewIs('documents.preview')
                ->assertSee('Preview isi belum tersedia')
                ->assertSee($name)
                ->assertSee($uploader->name)
                ->assertSee($project->code)
                ->assertSee('Kriteria '.$criterion->code)
                ->assertSee($criterion->code.'.'.$item->number)
                ->assertSee('Rincian '.$requirement->number)
                ->assertSee(route('documents.download', $document), false)
                ->assertDontSee(route('documents.preview.content', $document), false)
                ->assertDontSee($document->storage_path);
            $this->assertNull($response->headers->get('content-disposition'));

            $this->get(route('documents.preview.content', $document))->assertNotFound();
        }
    }

    public function test_authorized_user_can_open_historical_document_in_preview(): void
    {
        [$project, , , $requirement] = $this->context();
        $uploader = $this->userWithRole('PIC Kriteria');
        $manager = $this->userWithRole('Project Manager');
        $this->membership($project, $manager, 'project_manager');
        $document = $this->storedDocument(
            $requirement,
            $uploader,
            isActive: false,
            revisionNumber: 2,
        );

        $this->actingAs($manager)
            ->get(route('documents.preview', $document))
            ->assertOk()
            ->assertSee('Historis')
            ->assertSee('v2')
            ->assertSee(route('documents.preview.content', $document), false);

        $this->get(route('documents.preview.content', $document))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_missing_physical_file_returns_not_found_without_exposing_path(): void
    {
        [$project, , , $requirement] = $this->context();
        $uploader = $this->userWithRole('PIC Kriteria');
        $document = $this->storedDocument($requirement, $uploader, false);
        $manager = $this->userWithRole('Project Manager');
        $this->membership($project, $manager, 'project_manager');

        $previewResponse = $this->actingAs($manager)->get(route('documents.preview', $document));
        $previewResponse
            ->assertOk()
            ->assertSee('File tidak tersedia')
            ->assertDontSee($document->storage_path);

        $contentResponse = $this->get(route('documents.preview.content', $document));
        $contentResponse->assertNotFound();
        $contentResponse->assertDontSee($document->storage_path);

        $response = $this->get(route('documents.download', $document));

        $response->assertNotFound();
        $response->assertDontSee($document->storage_path);
    }

    public function test_database_failure_rolls_back_metadata_and_removes_stored_files(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, true);
        $this->actingAs($pic);
        Schema::drop('accreditation_activity_logs');

        try {
            app(AccreditationDocumentUploadService::class)->uploadBatch(
                $requirement,
                $pic,
                [$this->pdf('will-roll-back.pdf')],
            );
            $this->fail('Upload seharusnya gagal ketika audit log tidak dapat ditulis.');
        } catch (QueryException) {
            $this->assertDatabaseCount('accreditation_documents', 0);
            $this->assertSame([], Storage::disk('local')->allFiles('accreditation'));
            $this->assertSame('not_started', $requirement->fresh()->workflow_status);
        }
    }

    public function test_sidebar_uses_only_the_official_logo_without_overlapping_copy(): void
    {
        $layout = File::get(resource_path('views/components/layouts/app.blade.php'));
        $css = File::get(resource_path('css/app.css'));

        $this->assertStringContainsString('images/apu/Logo Univ White.png', $layout);
        $this->assertStringContainsString('images/apu/Tagline White.png', $layout);
        $this->assertStringNotContainsString('sidebar-brand-copy', $layout);
        $this->assertStringContainsString('.sidebar-official-logo { display: block;', $css);
        $this->assertStringContainsString('object-fit: contain', $css);
    }

    public function test_upload_page_renders_ui_02_information_statistics_and_existing_history(): void
    {
        [$project, $criterion, $item, $requirement] = $this->context();
        $item->update(['target_deadline' => '2026-03-15']);
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic, true);
        $activeDocument = $this->storedDocument($requirement, $pic);
        $historicalDocument = $this->storedDocument($requirement, $pic);
        $historicalDocument->update(['is_active' => false, 'superseded_at' => now()]);
        $this->actingAs($pic);

        $this->get(route('documents.upload', $requirement))
            ->assertOk()
            ->assertSee('Detail Butir & Upload Dokumen', false)
            ->assertSee('Input Dokumen')
            ->assertSee('Follow Up LED')
            ->assertSee($project->final_deadline->format('d M Y'))
            ->assertSee('15 Mar 2026')
            ->assertSee($criterion->name)
            ->assertSee($item->title)
            ->assertSee($requirement->title)
            ->assertSee($pic->name)
            ->assertSee('Total File')
            ->assertSee('File Aktif')
            ->assertSee('File Historis')
            ->assertSee('Riwayat Dokumen')
            ->assertSee($activeDocument->original_name)
            ->assertSee($historicalDocument->original_name)
            ->assertSee('Belum ada catatan verifikator')
            ->assertSeeHtml('class="nav-link active"');

        $component = Livewire::test(Upload::class, ['requirement' => $requirement]);

        $this->assertSame([
            'total' => 2,
            'active' => 1,
            'historical' => 1,
        ], $component->viewData('documentStats'));
        $component
            ->assertSee('evidence.pdf')
            ->assertSee('Aktif')
            ->assertSee('Historis');
    }

    public function test_verifier_note_is_only_rendered_from_existing_review_data(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $reviewer = $this->userWithRole('Reviewer');
        $this->assignment($criterion, $pic, true);
        AccreditationReviewSubmission::create([
            'requirement_id' => $requirement->id,
            'submitted_by' => $pic->id,
            'revision_number' => 1,
            'status' => 'need_revision',
            'submitted_at' => now()->subDay(),
            'decided_at' => now(),
            'decided_by' => $reviewer->id,
            'review_note' => 'Lengkapi lampiran keputusan dan halaman pengesahan.',
        ]);
        $this->actingAs($pic);

        Livewire::test(Upload::class, ['requirement' => $requirement])
            ->assertSee('Catatan Verifikator')
            ->assertSee($reviewer->name)
            ->assertSee('Lengkapi lampiran keputusan dan halaman pengesahan.')
            ->assertDontSee('Belum ada catatan verifikator');
    }

    public function test_review_event_note_is_rendered_when_submission_note_is_empty(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $reviewer = $this->userWithRole('Reviewer');
        $this->assignment($criterion, $pic, true);
        $submission = AccreditationReviewSubmission::create([
            'requirement_id' => $requirement->id,
            'submitted_by' => $pic->id,
            'revision_number' => 1,
            'status' => 'need_revision',
            'submitted_at' => now()->subDay(),
            'decided_at' => now(),
            'decided_by' => $reviewer->id,
            'review_note' => null,
        ]);
        DB::table('accreditation_review_events')->insert([
            'submission_id' => $submission->id,
            'actor_id' => $reviewer->id,
            'action' => 'need_revision',
            'note' => 'Perbaiki format halaman pengesahan.',
            'created_at' => now(),
        ]);
        $this->actingAs($pic);

        Livewire::test(Upload::class, ['requirement' => $requirement])
            ->assertSee($reviewer->name)
            ->assertSee('Perbaiki format halaman pengesahan.')
            ->assertDontSee('Belum ada catatan verifikator');
    }

    private function context(
        ?string $projectCode = null,
        int $itemNumber = 2,
        string $workflowStatus = 'not_started',
        int $currentRevision = 0,
    ): array {
        $suffix = strtoupper(Str::random(8));
        $projectCode ??= 'PROJECT-'.$suffix;
        $program = StudyProgram::create([
            'code' => 'PRODI-'.$suffix,
            'name' => 'Bioteknologi '.$suffix,
            'is_active' => true,
        ]);
        $project = AccreditationProject::create([
            'code' => $projectCode,
            'name' => 'Akreditasi '.$suffix,
            'study_program_id' => $program->id,
            'final_deadline' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $criterion = AccreditationCriterion::create([
            'project_id' => $project->id,
            'code' => 'A',
            'name' => 'Visi, Misi, Tujuan dan Strategi',
            'color_header' => '#173E70',
            'color_body' => '#E7EEF8',
            'position' => 1,
        ]);
        $item = AccreditationItem::create([
            'criterion_id' => $criterion->id,
            'number' => $itemNumber,
            'position' => $itemNumber,
            'title' => 'Statuta Perguruan Tinggi',
            'target_deadline' => now()->addMonths(3)->toDateString(),
        ]);
        $requirement = AccreditationDocumentRequirement::create([
            'item_id' => $item->id,
            'number' => 1,
            'title' => 'Dokumen Statuta Perguruan Tinggi',
            'is_required' => true,
            'workflow_status' => $workflowStatus,
            'current_review_revision' => $currentRevision,
        ]);

        return [$project, $criterion, $item, $requirement];
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
        bool $active,
        string $role = 'pic',
    ): AccreditationCriterionAssignment {
        return AccreditationCriterionAssignment::create([
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

    private function pdf(string $name, ?string $contents = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents ?? $this->pdfContents('valid-document'));
    }

    private function pdfContents(string $marker): string
    {
        return "%PDF-1.4\n"
            ."1 0 obj\n<< /Type /Catalog /Marker ({$marker}) >>\nendobj\n"
            ."xref\n0 2\n0000000000 65535 f \n0000000009 00000 n \n"
            ."trailer\n<< /Size 2 /Root 1 0 R >>\nstartxref\n64\n%%EOF\n";
    }

    private function storedDocument(
        AccreditationDocumentRequirement $requirement,
        User $uploader,
        bool $storeFile = true,
        string $originalName = 'evidence.pdf',
        string $mimeType = 'application/pdf',
        string $extension = 'pdf',
        ?string $contents = null,
        bool $isActive = true,
        int $revisionNumber = 1,
    ): AccreditationDocument {
        $uuid = (string) Str::uuid();
        $path = "accreditation/{$requirement->id}/{$uuid}.{$extension}";
        $contents ??= $this->pdfContents('stored-evidence');

        if ($storeFile) {
            Storage::disk('local')->put($path, $contents, ['visibility' => 'private']);
        }

        return AccreditationDocument::create([
            'requirement_id' => $requirement->id,
            'uploaded_by' => $uploader->id,
            'original_name' => $originalName,
            'disk' => 'local',
            'storage_path' => $path,
            'storage_uuid' => $uuid,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'revision_number' => $revisionNumber,
            'is_active' => $isActive,
            'uploaded_at' => now(),
        ]);
    }
}
