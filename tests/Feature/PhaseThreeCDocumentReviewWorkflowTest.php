<?php

namespace Tests\Feature;

use App\Livewire\Documents\Upload;
use App\Livewire\Documents\VerificationIndex;
use App\Livewire\Documents\VerificationShow;
use App\Livewire\FollowUpLed\Index as FollowUpLedIndex;
use App\Models\AccreditationActivityLog;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationCriterionAssignment;
use App\Models\AccreditationDocument;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationItem;
use App\Models\AccreditationProject;
use App\Models\AccreditationReviewAttachment;
use App\Models\AccreditationReviewSubmission;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\AccreditationDocumentUploadService;
use App\Services\AccreditationReviewWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccreditationRolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class PhaseThreeCDocumentReviewWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_draft_submission_creates_exact_active_snapshot_status_events_and_audit(): void
    {
        [$project, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $reviewer = $this->activeReviewer($criterion);
        $first = $this->document($requirement, $pic, 'first.pdf', true);
        $second = $this->document($requirement, $pic, 'second.pdf', true);
        $historical = $this->document($requirement, $pic, 'historical.pdf', false);

        $submission = $this->submit($requirement, $pic);

        $this->assertSame('pending_review', $submission->status);
        $this->assertSame(1, $submission->revision_number);
        $this->assertSame([$first->id, $second->id], $submission->documents->pluck('id')->sort()->values()->all());
        $this->assertNotContains($historical->id, $submission->documents->modelKeys());
        $this->assertSame('pending_review', $requirement->fresh()->workflow_status);
        $this->assertSame(1, $requirement->fresh()->current_review_revision);
        $this->assertDatabaseHas('accreditation_review_events', [
            'submission_id' => $submission->id,
            'actor_id' => $pic->id,
            'action' => 'submit',
        ]);
        $this->assertDatabaseHas('accreditation_activity_logs', [
            'project_id' => $project->id,
            'actor_id' => $pic->id,
            'event' => 'document.submitted',
            'subject_id' => $submission->id,
        ]);
        $this->assertTrue(Gate::forUser($reviewer)->allows('view', $submission));
    }

    public function test_empty_draft_and_submission_without_active_reviewer_are_rejected(): void
    {
        [, $criterion, , $emptyRequirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $this->activeReviewer($criterion);
        $this->actingAs($pic);

        try {
            app(AccreditationReviewWorkflowService::class)->submit($emptyRequirement, $pic);
            $this->fail('Draft kosong seharusnya tidak dapat disubmit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('submission', $exception->errors());
        }

        [, $criterionWithoutReviewer, , $requirementWithoutReviewer] = $this->context('NO-REVIEWER');
        $this->assign($criterionWithoutReviewer, $pic, 'pic');
        $this->document($requirementWithoutReviewer, $pic);

        try {
            app(AccreditationReviewWorkflowService::class)->submit($requirementWithoutReviewer, $pic);
            $this->fail('Submission tanpa Reviewer aktif seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertSame('Belum ada Reviewer aktif dan independen pada kriteria ini.', $exception->errors()['submission'][0]);
        }

        $this->assertDatabaseCount('accreditation_review_submissions', 0);
    }

    public function test_unauthorized_pic_cannot_submit_another_criterion(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->activeReviewer($criterion);
        $this->document($requirement, $pic);
        $this->actingAs($pic);

        $this->expectException(AuthorizationException::class);
        app(AccreditationReviewWorkflowService::class)->submit($requirement, $pic);
    }

    public function test_pic_can_submit_from_upload_page_but_not_while_temporary_files_are_queued(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $this->activeReviewer($criterion);
        $this->document($requirement, $pic);
        $this->actingAs($pic);

        Livewire::test(Upload::class, ['requirement' => $requirement])
            ->assertSee('Kirim ke Verifikator')
            ->set('files', [UploadedFile::fake()->createWithContent('queued.pdf', $this->pdfContents('queued'))])
            ->call('submitForReview')
            ->assertHasErrors(['submission']);
        $this->assertDatabaseCount('accreditation_review_submissions', 0);

        Livewire::test(Upload::class, ['requirement' => $requirement])
            ->call('submitForReview')
            ->assertHasNoErrors()
            ->assertSee('Revisi 1 berhasil dikirim ke verifikator.')
            ->assertSee('Menunggu Verifikasi');
        $this->assertDatabaseCount('accreditation_review_submissions', 1);
    }

    public function test_pending_review_locks_upload_and_duplicate_submission(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $this->activeReviewer($criterion);
        $this->document($requirement, $pic);
        $this->submit($requirement, $pic);

        $this->assertFalse(Gate::forUser($pic)->allows('upload', $requirement->fresh()));

        try {
            app(AccreditationReviewWorkflowService::class)->submit($requirement->fresh(), $pic);
            $this->fail('Duplicate submission seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('submission', $exception->errors());
        }

        $this->assertDatabaseCount('accreditation_review_submissions', 1);
    }

    public function test_active_reviewer_can_open_scoped_queue_and_submission(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $reviewer = $this->activeReviewer($criterion);
        $this->document($requirement, $pic);
        $submission = $this->submit($requirement, $pic);

        $this->actingAs($reviewer)
            ->get(route('verifications.index'))
            ->assertOk()
            ->assertSee($requirement->title)
            ->assertSee(route('verifications.show', $submission), false);
        $this->get(route('verifications.show', $submission))
            ->assertOk()
            ->assertSee('Terverifikasi')
            ->assertSee('Perlu Revisi')
            ->assertDontSee('Tolak');
        Livewire::test(VerificationIndex::class)->assertSee($requirement->title);
        Livewire::test(VerificationShow::class, ['submission' => $submission])->assertSee($requirement->title);
    }

    public function test_reviewer_outside_assignment_cannot_open_submission(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $this->activeReviewer($criterion);
        $outsider = $this->userWithRole('Reviewer');
        $this->document($requirement, $pic);
        $submission = $this->submit($requirement, $pic);

        $this->actingAs($outsider)
            ->get(route('verifications.show', $submission))
            ->assertForbidden();
    }

    public function test_submitter_and_snapshot_uploader_cannot_review(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $reviewer = $this->activeReviewer($criterion);
        $document = $this->document($requirement, $pic);
        $submission = $this->submit($requirement, $pic);

        $submission->update(['submitted_by' => $reviewer->id]);
        $this->assertFalse(Gate::forUser($reviewer)->allows('verify', $submission->fresh()));

        $submission->update(['submitted_by' => $pic->id]);
        $document->update(['uploaded_by' => $reviewer->id]);
        $this->assertFalse(Gate::forUser($reviewer)->allows('verify', $submission->fresh()));
        $this->assertFalse(Gate::forUser($reviewer)->allows('requestRevision', $submission->fresh()));
    }

    public function test_verified_decision_updates_submission_requirement_event_and_audit(): void
    {
        [$project, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $reviewer = $this->activeReviewer($criterion);
        $this->document($requirement, $pic);
        $submission = $this->submit($requirement, $pic);

        $decided = $this->decide($submission, $reviewer, 'verified', null);

        $this->assertSame('verified', $decided->status);
        $this->assertSame($reviewer->id, $decided->decided_by);
        $this->assertNotNull($decided->decided_at);
        $this->assertSame('verified', $requirement->fresh()->workflow_status);
        $this->assertDatabaseHas('accreditation_review_events', [
            'submission_id' => $submission->id,
            'actor_id' => $reviewer->id,
            'action' => 'verified',
        ]);
        $this->assertDatabaseHas('accreditation_activity_logs', [
            'project_id' => $project->id,
            'actor_id' => $reviewer->id,
            'event' => 'document.verified',
            'subject_id' => $submission->id,
        ]);
        $this->assertFalse(Gate::forUser($pic)->allows('upload', $requirement->fresh()));
    }

    public function test_submission_review_and_activity_share_utc_instants_and_display_wib(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-16 23:30:00', 'UTC'));
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $reviewer = $this->activeReviewer($criterion);
        $this->document($requirement, $pic);

        $submission = $this->submit($requirement, $pic);
        $submitEvent = $submission->events()->where('action', 'submit')->sole();
        $submitActivity = AccreditationActivityLog::query()->where('event', 'document.submitted')->sole();

        $this->assertTrue($submission->submitted_at->equalTo($submitEvent->created_at));
        $this->assertTrue($submission->submitted_at->equalTo($submitActivity->created_at));

        $this->actingAs($reviewer);
        Livewire::test(VerificationShow::class, ['submission' => $submission])
            ->assertSee('17 Oct 2026')
            ->assertSee('06:30 WIB');

        $this->travel(1)->hour();
        $decided = $this->decide($submission, $reviewer, 'verified', null);
        $decisionEvent = $decided->events()->where('action', 'verified')->sole();
        $decisionActivity = AccreditationActivityLog::query()->where('event', 'document.verified')->sole();

        $this->assertTrue($decided->decided_at->equalTo($decisionEvent->created_at));
        $this->assertTrue($decided->decided_at->equalTo($decisionActivity->created_at));

        Livewire::test(VerificationShow::class, ['submission' => $decided])
            ->assertSee('17 Oct 2026, 07:30 WIB');
    }

    public function test_requirement_workflows_remain_independent_and_follow_up_uses_mixed_progress(): void
    {
        [, $criterion, $item, $firstRequirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $reviewer = $this->activeReviewer($criterion);
        $requirements = collect([$firstRequirement]);

        foreach (range(2, 10) as $number) {
            $requirements->push(AccreditationDocumentRequirement::create([
                'item_id' => $item->id,
                'number' => $number,
                'title' => 'Rincian '.$number,
                'is_required' => true,
                'workflow_status' => 'not_started',
                'current_review_revision' => 0,
            ]));
        }

        $this->document($firstRequirement, $pic, 'rincian-satu.pdf');
        $firstSubmission = $this->submit($firstRequirement, $pic);
        $this->decide($firstSubmission, $reviewer, 'verified', null);
        $fourthRequirement = $requirements->firstWhere('number', 4);

        $this->actingAs($pic);
        app(AccreditationDocumentUploadService::class)->uploadBatch(
            $fourthRequirement,
            $pic,
            [UploadedFile::fake()->createWithContent('rincian-empat.pdf', $this->pdfContents('rincian-empat'))],
        );

        $this->assertSame('verified', $firstRequirement->fresh()->workflow_status);
        $this->assertSame('draft', $fourthRequirement->fresh()->workflow_status);

        $fourthSubmission = $this->submit($fourthRequirement, $pic);

        $this->assertSame('verified', $firstRequirement->fresh()->workflow_status);
        $this->assertSame('pending_review', $fourthRequirement->fresh()->workflow_status);
        $this->assertSame('verified', $firstSubmission->fresh()->status);

        $this->actingAs($reviewer);
        $component = Livewire::test(FollowUpLedIndex::class);
        $displayed = $component->viewData('items')->sole();

        $this->assertSame('in_progress', $displayed->aggregate_status);
        $this->assertSame(1, $displayed->verified_required_count);
        $this->assertSame(10, $displayed->required_count);
        $this->assertSame(10, $displayed->verified_progress);
        $component
            ->assertSee('Rincian Dokumen Operasional')
            ->assertSee('Terverifikasi')
            ->assertSee('Menunggu Verifikasi')
            ->assertSee('Belum Mulai')
            ->assertSee('1 / 10')
            ->assertSee('10%');

        $this->decide($fourthSubmission, $reviewer, 'verified', null);
        AccreditationDocumentRequirement::query()
            ->where('item_id', $item->id)
            ->update(['workflow_status' => 'verified']);

        $completed = $component->call('$refresh')->viewData('items')->sole();
        $this->assertSame('verified', $completed->aggregate_status);
        $this->assertSame(100, $completed->verified_progress);
        $this->assertSame('verified', $firstSubmission->fresh()->status);
    }

    public function test_need_revision_requires_non_whitespace_note(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $reviewer = $this->activeReviewer($criterion);
        $this->document($requirement, $pic);
        $submission = $this->submit($requirement, $pic);
        $this->actingAs($reviewer);

        foreach ([null, '', '   '] as $note) {
            try {
                app(AccreditationReviewWorkflowService::class)->decide($submission, $reviewer, 'need_revision', $note);
                $this->fail('Need Revision tanpa catatan seharusnya ditolak.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('reviewNote', $exception->errors());
            }
        }

        try {
            app(AccreditationReviewWorkflowService::class)->decide(
                $submission,
                $reviewer,
                'need_revision',
                str_repeat('a', 10001),
            );
            $this->fail('Catatan yang melebihi batas seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reviewNote', $exception->errors());
        }

        $this->assertSame('pending_review', $submission->fresh()->status);
        $this->assertSame('pending_review', $requirement->fresh()->workflow_status);
    }

    public function test_need_revision_note_is_visible_to_pic(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $reviewer = $this->activeReviewer($criterion);
        $this->document($requirement, $pic);
        $submission = $this->submit($requirement, $pic);
        $note = 'Tambahkan halaman pengesahan dan bukti sosialisasi yang dapat dibaca.';

        $decided = $this->decide($submission, $reviewer, 'need_revision', $note);

        $this->assertSame('need_revision', $decided->status);
        $this->assertSame('need_revision', $requirement->fresh()->workflow_status);
        $this->assertSame($note, $decided->review_note);
        $this->assertDatabaseHas('accreditation_review_events', [
            'submission_id' => $submission->id,
            'action' => 'need_revision',
            'note' => $note,
        ]);

        $this->actingAs($pic);
        Livewire::test(Upload::class, ['requirement' => $requirement->fresh()])
            ->assertSee($note)
            ->assertSee('Perlu Revisi');
    }

    public function test_need_revision_with_multiple_correction_attachments_is_atomic_and_visible(): void
    {
        [$project, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $reviewer = $this->activeReviewer($criterion);
        $document = $this->document($requirement, $pic, 'original-evidence.pdf');
        $submission = $this->submit($requirement, $pic);
        $originalContents = Storage::disk('local')->get($document->storage_path);
        $originalChecksum = $document->sha256;
        $first = UploadedFile::fake()->createWithContent('marked-page.pdf', $this->pdfContents('marked-page'));
        $removed = UploadedFile::fake()->createWithContent('remove-before-submit.pdf', $this->pdfContents('remove'));
        $second = UploadedFile::fake()->image('annotated-image.png', 640, 480);

        $this->actingAs($reviewer);
        Livewire::test(VerificationShow::class, ['submission' => $submission])
            ->set('decision', 'need_revision')
            ->set('reviewNote', 'Perbaiki bagian yang ditandai pada dua lampiran.')
            ->set('correctionAttachments', [$first, $removed, $second])
            ->call('removeCorrectionAttachment', 1)
            ->assertCount('correctionAttachments', 2)
            ->call('confirmDecision')
            ->assertHasNoErrors()
            ->assertSet('confirmingDecision', true)
            ->call('submitDecision')
            ->assertHasNoErrors()
            ->assertSee('Permintaan revisi berhasil dikirim kepada PIC.');

        $attachments = AccreditationReviewAttachment::query()->orderBy('id')->get();
        $event = $submission->events()->where('action', 'need_revision')->sole();
        $this->assertCount(2, $attachments);
        $this->assertSame([$event->id], $attachments->pluck('review_event_id')->unique()->values()->all());
        $this->assertSame(['annotated-image.png', 'marked-page.pdf'], $attachments->pluck('original_filename')->sort()->values()->all());
        foreach ($attachments as $attachment) {
            Storage::disk('local')->assertExists($attachment->storage_path);
            Storage::disk('public')->assertMissing($attachment->storage_path);
            $this->assertStringStartsWith('accreditation-review-attachments/', $attachment->storage_path);
            $this->assertSame(hash('sha256', Storage::disk('local')->get($attachment->storage_path)), $attachment->sha256);
        }

        $this->assertSame($originalContents, Storage::disk('local')->get($document->storage_path));
        $this->assertSame($originalChecksum, $document->fresh()->sha256);
        $activity = AccreditationActivityLog::query()
            ->where('event', 'document.revision_requested')
            ->where('subject_id', $submission->id)
            ->sole();
        $this->assertCount(2, $activity->new_values['correction_attachments']);
        $this->assertSame($project->id, $activity->project_id);

        $this->actingAs($pic);
        Livewire::test(Upload::class, ['requirement' => $requirement->fresh()])
            ->assertSee('marked-page.pdf')
            ->assertSee('annotated-image.png')
            ->assertSee(route('verifications.attachments.download', [$submission, $attachments->first()]), false);
    }

    public function test_need_revision_attachment_still_requires_note_and_verified_rejects_attachment(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $reviewer = $this->activeReviewer($criterion);
        $this->document($requirement, $pic);
        $submission = $this->submit($requirement, $pic);
        $attachment = UploadedFile::fake()->createWithContent('correction.pdf', $this->pdfContents('correction'));
        $this->actingAs($reviewer);

        foreach ([['need_revision', '   ', 'reviewNote'], ['verified', null, 'correctionAttachments']] as [$decision, $note, $error]) {
            try {
                app(AccreditationReviewWorkflowService::class)->decide(
                    $submission->fresh(),
                    $reviewer,
                    $decision,
                    $note,
                    [$attachment],
                );
                $this->fail('Kombinasi keputusan dan lampiran tidak valid seharusnya ditolak.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($error, $exception->errors());
            }
        }

        try {
            app(AccreditationReviewWorkflowService::class)->decide(
                $submission->fresh(),
                $reviewer,
                'need_revision',
                'Jangan menerima executable.',
                [UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream')],
            );
            $this->fail('Executable seharusnya ditolak sebagai lampiran koreksi.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('correctionAttachments.0', $exception->errors());
        }

        $this->assertSame('pending_review', $submission->fresh()->status);
        $this->assertDatabaseCount('accreditation_review_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('accreditation-review-attachments'));
    }

    public function test_attachment_access_is_private_parent_scoped_and_limited_to_related_pic_and_reviewer(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $reviewer = $this->activeReviewer($criterion);
        $document = $this->document($requirement, $pic);
        $submission = $this->submit($requirement, $pic);
        $attachmentFile = UploadedFile::fake()->createWithContent('review-mark.pdf', $this->pdfContents('review-mark'));
        $this->decide($submission, $reviewer, 'need_revision', 'Lihat lampiran.', [$attachmentFile]);
        $attachment = AccreditationReviewAttachment::query()->sole();

        $this->actingAs($reviewer)
            ->get(route('verifications.attachments.open', [$submission, $attachment]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->get(route('verifications.attachments.download', [$submission, $attachment]))
            ->assertOk();
        $this->get(route('verifications.documents.open', [$submission, $document]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->get(route('verifications.documents.download', [$submission, $document]))
            ->assertOk();

        $this->actingAs($pic)
            ->get(route('verifications.attachments.download', [$submission, $attachment]))
            ->assertOk();

        [, $otherCriterion, , $otherRequirement] = $this->context('OTHER-PROJECT');
        $otherPic = $this->userWithRole('PIC Kriteria');
        $this->assign($otherCriterion, $otherPic, 'pic');
        $otherReviewer = $this->activeReviewer($otherCriterion);
        $this->document($otherRequirement, $otherPic);
        $otherSubmission = $this->submit($otherRequirement, $otherPic);

        $this->actingAs($otherPic)
            ->get(route('verifications.attachments.download', [$submission, $attachment]))
            ->assertForbidden();
        $this->actingAs($otherReviewer)
            ->get(route('verifications.attachments.download', [$submission, $attachment]))
            ->assertForbidden();
        $this->actingAs($reviewer)
            ->get(route('verifications.attachments.download', [$otherSubmission, $attachment]))
            ->assertNotFound();
        $this->get(route('verifications.documents.open', [$submission, $this->document($requirement, $pic, 'outside-snapshot.pdf')]))
            ->assertNotFound();

        auth()->logout();
        $this->get(route('verifications.attachments.download', [$submission, $attachment]))
            ->assertRedirect(route('login'));
    }

    public function test_private_endpoints_reject_tampered_original_and_attachment_bytes(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $reviewer = $this->activeReviewer($criterion);
        $document = $this->document($requirement, $pic);
        $submission = $this->submit($requirement, $pic);
        $this->decide(
            $submission,
            $reviewer,
            'need_revision',
            'Periksa integritas file.',
            [UploadedFile::fake()->createWithContent('integrity.pdf', $this->pdfContents('integrity'))],
        );
        $attachment = AccreditationReviewAttachment::query()->sole();
        Storage::disk('local')->put($document->storage_path, 'tampered-original');
        Storage::disk('local')->put($attachment->storage_path, 'tampered-attachment');

        $this->actingAs($reviewer)
            ->get(route('verifications.documents.open', [$submission, $document]))
            ->assertNotFound();
        $this->get(route('verifications.attachments.open', [$submission, $attachment]))
            ->assertNotFound();
        $this->assertSame($document->sha256, $document->fresh()->sha256);
        $this->assertSame($attachment->sha256, $attachment->fresh()->sha256);

        try {
            $attachment->update(['original_filename' => 'mutated.pdf']);
            $this->fail('Metadata lampiran historis seharusnya immutable melalui model.');
        } catch (\LogicException $exception) {
            $this->assertSame('Lampiran koreksi bersifat immutable.', $exception->getMessage());
        }
    }

    public function test_failed_decision_rolls_back_database_and_removes_stored_attachments(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $reviewer = $this->activeReviewer($criterion);
        $this->document($requirement, $pic);
        $submission = $this->submit($requirement, $pic);
        $attachment = UploadedFile::fake()->createWithContent('rollback.pdf', $this->pdfContents('rollback'));
        AccreditationActivityLog::creating(fn () => throw new RuntimeException('Forced audit failure.'));
        $this->actingAs($reviewer);

        try {
            app(AccreditationReviewWorkflowService::class)->decide(
                $submission,
                $reviewer,
                'need_revision',
                'Keputusan harus rollback.',
                [$attachment],
            );
            $this->fail('Kegagalan audit harus membatalkan keputusan.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced audit failure.', $exception->getMessage());
        }

        $this->assertSame('pending_review', $submission->fresh()->status);
        $this->assertSame('pending_review', $requirement->fresh()->workflow_status);
        $this->assertDatabaseCount('accreditation_review_attachments', 0);
        $this->assertSame(1, $submission->events()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('accreditation-review-attachments'));
    }

    public function test_history_hides_submission_the_current_reviewer_cannot_view(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $firstReviewer = $this->activeReviewer($criterion);
        $secondReviewer = $this->activeReviewer($criterion);
        $this->document($requirement, $pic);
        $firstSubmission = $this->submit($requirement, $pic);
        $this->decide($firstSubmission, $firstReviewer, 'need_revision', 'PRIVATE-HISTORY-NOTE');
        $this->actingAs($pic);
        app(AccreditationDocumentUploadService::class)->uploadBatch(
            $requirement->fresh(),
            $pic,
            [UploadedFile::fake()->createWithContent('revision.pdf', $this->pdfContents('revision'))],
        );
        $secondSubmission = app(AccreditationReviewWorkflowService::class)->submit($requirement->fresh(), $pic);
        $firstSubmission->update(['submitted_by' => $secondReviewer->id]);

        $this->actingAs($secondReviewer)
            ->get(route('verifications.show', $secondSubmission))
            ->assertOk()
            ->assertDontSee('PRIVATE-HISTORY-NOTE');
    }

    public function test_pic_can_upload_revision_and_resubmit_without_mutating_old_snapshot(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $reviewer = $this->activeReviewer($criterion);
        $oldDocument = $this->document($requirement, $pic, 'revision-one.pdf');
        $firstSubmission = $this->submit($requirement, $pic);
        $this->decide($firstSubmission, $reviewer, 'need_revision', 'Tambahkan bukti revisi terbaru.');

        $this->actingAs($pic);
        Livewire::test(Upload::class, ['requirement' => $requirement->fresh()])
            ->assertSee('Upload revisi diperlukan')
            ->assertDontSee('Kirim ke Verifikator');
        try {
            app(AccreditationReviewWorkflowService::class)->submit($requirement->fresh(), $pic);
            $this->fail('Resubmit tanpa file revisi baru seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Unggah minimal satu file revisi baru sebelum mengirim ulang.',
                $exception->errors()['submission'][0],
            );
        }

        $newDocuments = app(AccreditationDocumentUploadService::class)->uploadBatch(
            $requirement->fresh(),
            $pic,
            [UploadedFile::fake()->createWithContent('revision-two.pdf', $this->pdfContents('revision-two'))],
        );
        Livewire::test(Upload::class, ['requirement' => $requirement->fresh()])
            ->assertSee('Kirim ke Verifikator');
        $secondSubmission = app(AccreditationReviewWorkflowService::class)->submit($requirement->fresh(), $pic);

        $this->assertSame(2, $newDocuments->sole()->revision_number);
        $this->assertTrue($oldDocument->fresh()->is_active);
        $this->assertSame(2, $secondSubmission->revision_number);
        $this->assertSame(
            [$oldDocument->id, $newDocuments->sole()->id],
            $secondSubmission->documents->pluck('id')->sort()->values()->all(),
        );
        $this->assertSame([$oldDocument->id], $firstSubmission->fresh()->documents->modelKeys());
        $this->assertSame('need_revision', $firstSubmission->fresh()->status);
        $this->assertSame('pending_review', $requirement->fresh()->workflow_status);
        $this->assertSame(2, $requirement->fresh()->current_review_revision);
        $this->assertDatabaseCount('accreditation_submission_documents', 3);
    }

    public function test_historical_snapshot_file_remains_available_to_authorized_reviewer(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $reviewer = $this->activeReviewer($criterion);
        $document = $this->document($requirement, $pic);
        $submission = $this->submit($requirement, $pic);
        $document->update(['is_active' => false, 'superseded_at' => now()]);

        $this->actingAs($reviewer)
            ->get(route('documents.preview', $document))
            ->assertOk()
            ->assertSee('Historis');
        $this->get(route('verifications.show', $submission))
            ->assertOk()
            ->assertSee($document->original_name);
    }

    public function test_rejected_choice_is_absent_and_stale_second_decision_is_rejected(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $reviewer = $this->activeReviewer($criterion);
        $this->document($requirement, $pic);
        $submission = $this->submit($requirement, $pic);

        $blade = File::get(resource_path('views/livewire/documents/verification-show.blade.php'));
        $this->assertStringNotContainsString('value="rejected"', $blade);
        $this->assertStringNotContainsString('>Tolak<', $blade);

        $this->actingAs($reviewer);
        try {
            app(AccreditationReviewWorkflowService::class)->decide($submission, $reviewer, 'rejected', 'Tidak digunakan.');
            $this->fail('Status rejected seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
        }

        $this->decide($submission, $reviewer, 'verified', 'Sesuai.');
        $this->actingAs($reviewer);

        try {
            app(AccreditationReviewWorkflowService::class)->decide($submission, $reviewer, 'need_revision', 'Keputusan kedua.');
            $this->fail('Keputusan kedua pada submission stale seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
        }

        $this->assertDatabaseCount('accreditation_review_events', 2);
        $this->assertSame('verified', $submission->fresh()->status);
    }

    public function test_super_admin_can_monitor_but_cannot_decide_or_bypass_independence(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $admin = $this->userWithRole('Super Admin');
        $reviewer = $this->activeReviewer($criterion);
        $this->document($requirement, $admin, 'emergency.pdf');
        $submission = $this->submit($requirement, $admin);

        $this->assertTrue(Gate::forUser($admin)->allows('view', $submission));
        $this->assertFalse(Gate::forUser($admin)->allows('verify', $submission));
        $this->assertFalse(Gate::forUser($admin)->allows('requestRevision', $submission));
        $this->assertTrue(Gate::forUser($reviewer)->allows('verify', $submission));

        $this->actingAs($admin)
            ->get(route('verifications.show', $submission))
            ->assertOk()
            ->assertSee('Mode Pemantauan')
            ->assertDontSee('Kirim Hasil Verifikasi');
    }

    public function test_dual_role_super_admin_never_counts_as_reviewer_or_decides(): void
    {
        [, $criterion, , $requirement] = $this->context();
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assign($criterion, $pic, 'pic');
        $adminReviewer = $this->userWithRole('Super Admin');
        $adminReviewer->assignRole('Reviewer');
        $this->assign($criterion, $adminReviewer, 'reviewer');
        $this->document($requirement, $pic);
        $this->actingAs($pic);

        try {
            app(AccreditationReviewWorkflowService::class)->submit($requirement, $pic);
            $this->fail('Super Admin dual-role tidak boleh dihitung sebagai Reviewer independen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('submission', $exception->errors());
        }

        $reviewer = $this->activeReviewer($criterion);
        $submission = $this->submit($requirement, $pic);
        $this->assertFalse(Gate::forUser($adminReviewer)->allows('review', $submission));
        $this->assertFalse(Gate::forUser($adminReviewer)->allows('verify', $submission));
        $this->assertFalse(Gate::forUser($adminReviewer)->allows('requestRevision', $submission));
        $this->assertTrue(Gate::forUser($reviewer)->allows('verify', $submission));
    }

    private function submit(
        AccreditationDocumentRequirement $requirement,
        User $user,
    ): AccreditationReviewSubmission {
        $this->actingAs($user);

        return app(AccreditationReviewWorkflowService::class)->submit($requirement->fresh(), $user);
    }

    private function decide(
        AccreditationReviewSubmission $submission,
        User $reviewer,
        string $decision,
        ?string $note,
        array $attachments = [],
    ): AccreditationReviewSubmission {
        $this->actingAs($reviewer);

        return app(AccreditationReviewWorkflowService::class)->decide(
            $submission->fresh(),
            $reviewer,
            $decision,
            $note,
            $attachments,
        );
    }

    private function context(?string $projectCode = null): array
    {
        $suffix = strtoupper(Str::random(8));
        $program = StudyProgram::create([
            'code' => 'PRODI-'.$suffix,
            'name' => 'Bioteknologi '.$suffix,
            'is_active' => true,
        ]);
        $project = AccreditationProject::create([
            'code' => $projectCode ?? 'PROJECT-'.$suffix,
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
            'number' => 2,
            'position' => 2,
            'title' => 'Statuta Perguruan Tinggi',
            'target_deadline' => now()->addMonths(3)->toDateString(),
        ]);
        $requirement = AccreditationDocumentRequirement::create([
            'item_id' => $item->id,
            'number' => 1,
            'title' => 'Dokumen Statuta Perguruan Tinggi',
            'is_required' => true,
            'workflow_status' => 'draft',
            'current_review_revision' => 0,
        ]);

        return [$project, $criterion, $item, $requirement];
    }

    private function activeReviewer(AccreditationCriterion $criterion): User
    {
        $reviewer = $this->userWithRole('Reviewer');
        $this->assign($criterion, $reviewer, 'reviewer');

        return $reviewer;
    }

    private function assign(AccreditationCriterion $criterion, User $user, string $role): void
    {
        AccreditationCriterionAssignment::create([
            'criterion_id' => $criterion->id,
            'user_id' => $user->id,
            'assignment_role' => $role,
            'is_active' => true,
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

    private function document(
        AccreditationDocumentRequirement $requirement,
        User $uploader,
        string $name = 'evidence.pdf',
        bool $active = true,
    ): AccreditationDocument {
        $uuid = (string) Str::uuid();
        $path = "accreditation/{$requirement->id}/{$uuid}.pdf";
        $contents = $this->pdfContents($name);
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
            'is_active' => $active,
            'uploaded_at' => now(),
            'superseded_at' => $active ? null : now(),
        ]);
    }

    private function pdfContents(string $marker): string
    {
        return "%PDF-1.4\n"
            ."1 0 obj\n<< /Type /Catalog /Marker ({$marker}) >>\nendobj\n"
            ."xref\n0 2\n0000000000 65535 f \n0000000009 00000 n \n"
            ."trailer\n<< /Size 2 /Root 1 0 R >>\nstartxref\n64\n%%EOF\n";
    }
}
