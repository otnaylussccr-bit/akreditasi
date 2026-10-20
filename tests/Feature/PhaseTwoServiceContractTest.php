<?php

namespace Tests\Feature;

use App\Livewire\Administration\Users\Index as UserManagementIndex;
use App\Livewire\LedAssessment\Dashboard as LedAssessmentDashboard;
use App\Livewire\LedAssessment\Index as LedAssessmentIndex;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationDocument;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationInstrument;
use App\Models\AccreditationItem;
use App\Models\AccreditationLedAssessment;
use App\Models\AccreditationLedAssessmentCycle;
use App\Models\AccreditationLedAssessorAssignment;
use App\Models\AccreditationLedIndicator;
use App\Models\AccreditationLedIndicatorItemMapping;
use App\Models\AccreditationLedInstrumentVersion;
use App\Models\AccreditationLedRubric;
use App\Models\AccreditationProject;
use App\Models\AccreditationProjectMember;
use App\Models\AccreditationReviewSubmission;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\LedAssessmentAuthorizationService;
use App\Services\LedAssessmentCycleService;
use App\Services\LedAssessmentDashboardService;
use App\Services\LedAssessmentEvidenceService;
use App\Services\LedAssessmentWorkflowService;
use App\Services\LedAssessorAssignmentService;
use App\Services\LedInstrumentVersionService;
use App\Services\UserAccessManagementService;
use Database\Seeders\AccreditationRolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PhaseTwoServiceContractTest extends TestCase
{
    protected string $testLocale = 'id';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_instrument_publication_requires_source_and_manifest_hash_validation(): void
    {
        $admin = $this->user('Super Admin');
        $instrument = AccreditationInstrument::create(['code' => 'LED-INSTRUMENT', 'name' => 'LED Terakreditasi']);
        $bytes = 'verified-official-source';
        Storage::disk('local')->put('instruments/source.pdf', $bytes);
        $version = AccreditationLedInstrumentVersion::create([
            'instrument_id' => $instrument->id,
            'version_code' => '2026.1',
            'assessment_pathway' => 'terakreditasi',
            'education_level' => 'S1',
            'source_title' => 'Sumber Terverifikasi',
            'source_reference' => 'REF-2026',
            'source_filename' => 'source.pdf',
            'source_disk' => 'local',
            'source_storage_path' => 'instruments/source.pdf',
            'source_pdf_sha256' => hash('sha256', $bytes),
            'rubric_manifest_sha256' => str_repeat('0', 64),
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
        $indicator = AccreditationLedIndicator::create([
            'instrument_version_id' => $version->id,
            'indicator_number' => 1,
            'criterion_code' => 'A',
            'title' => 'Indikator bersumber',
            'position' => 1,
            'is_assessable' => true,
        ]);
        AccreditationLedRubric::create([
            'indicator_id' => $indicator->id,
            'category_code' => 'baik',
            'category_label' => 'Baik',
            'internal_score' => 3,
            'description' => 'Rubrik dari sumber terverifikasi.',
            'position' => 1,
        ]);
        $service = app(LedInstrumentVersionService::class);
        $this->actingAs($admin);

        try {
            $service->publish($version, $admin);
            $this->fail('Manifest hash mismatch must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('manifest', $exception->errors());
        }

        $version->update(['rubric_manifest_sha256' => $service->manifestHash($version->fresh())]);
        $published = $service->publish($version->fresh(), $admin);

        $this->assertSame('published', $published->status);
        $this->assertSame($admin->id, $published->published_by);
        $this->assertDatabaseHas('administrative_audit_logs', ['event' => 'instrument_version.published', 'subject_id' => $version->id]);
    }

    public function test_cycle_initialization_uses_assessable_instrument_configuration_instead_of_hardcoded_indicator_count(): void
    {
        $manager = $this->user('Project Manager');
        $program = StudyProgram::create(['code' => Str::random(8), 'name' => 'Configured Program', 'is_active' => true]);
        $instrument = AccreditationInstrument::create(['code' => Str::random(8), 'name' => 'Configured Instrument']);
        $project = AccreditationProject::create([
            'code' => Str::random(10), 'name' => 'Configured Project', 'study_program_id' => $program->id,
            'instrument_id' => $instrument->id, 'final_deadline' => '2026-10-30', 'status' => 'active',
        ]);
        AccreditationProjectMember::create(['project_id' => $project->id, 'user_id' => $manager->id, 'membership_role' => 'project_manager', 'is_active' => true]);
        $version = AccreditationLedInstrumentVersion::create([
            'instrument_id' => $instrument->id, 'version_code' => 'CONFIG-'.Str::random(4),
            'assessment_pathway' => 'terakreditasi', 'education_level' => 'S1', 'source_title' => 'Configured',
            'source_reference' => 'CONFIG', 'source_filename' => 'configured.pdf', 'source_disk' => 'local',
            'source_storage_path' => 'configured.pdf', 'source_pdf_sha256' => str_repeat('a', 64),
            'rubric_manifest_sha256' => str_repeat('b', 64), 'status' => 'published',
            'published_by' => $manager->id, 'published_at' => now(), 'created_by' => $manager->id,
        ]);
        foreach ([true, true, false] as $position => $assessable) {
            AccreditationLedIndicator::create([
                'instrument_version_id' => $version->id, 'indicator_number' => $position + 1,
                'criterion_code' => 'A', 'title' => 'Configured '.($position + 1),
                'position' => $position + 1, 'is_assessable' => $assessable,
            ]);
        }
        $this->actingAs($manager);

        $cycle = app(LedAssessmentCycleService::class)->create($project, $version, 'Configured Cycle', $manager);

        $this->assertCount(2, $cycle->assessments);
        $this->assertSame([1, 2], $cycle->assessments->pluck('indicator.indicator_number')->all());
        $this->assertDatabaseHas('accreditation_activity_logs', ['event' => 'assessment_cycle.created', 'subject_id' => $cycle->id]);
    }

    public function test_assessment_approval_is_independent_idempotent_atomic_and_dashboard_uses_only_approved_pointer(): void
    {
        $domain = $this->domain();
        $workflow = app(LedAssessmentWorkflowService::class);
        $dashboard = app(LedAssessmentDashboardService::class);
        $this->actingAs($domain['assessor']);
        $draft = $workflow->saveDraft($domain['assessment'], $domain['assessor'], [
            'rubric_id' => $domain['rubric']->id,
            'justification' => 'Penilaian berdasarkan bukti terverifikasi.',
            'gap_analysis' => 'Masih ada kesenjangan.',
            'recommendation' => 'Lakukan tindak lanjut.',
        ]);

        $this->assertSame(0, $dashboard->summary($domain['cycle'], $domain['manager'])['approved']);
        $submitted = $workflow->submit($draft, $domain['assessor']);
        $this->actingAs($domain['reviewer']);
        $inReview = $workflow->startReview($submitted, $domain['reviewer']);
        $approved = $workflow->approve($inReview, $domain['reviewer'], 'Disetujui reviewer independen.');
        $workflow->approve($approved, $domain['reviewer'], 'Permintaan idempotent.');

        $this->assertSame('approved', $approved->status);
        $this->assertSame($approved->id, $domain['assessment']->fresh()->current_approved_revision_id);
        $this->assertSame(1, $dashboard->summary($domain['cycle'], $domain['manager'])['approved']);
        $this->assertSame(3.0, $dashboard->summary($domain['cycle'], $domain['manager'])['average']);
        $this->assertDatabaseCount('accreditation_led_review_events', 4);
        $this->assertDatabaseHas('accreditation_activity_logs', ['event' => 'assessment.approved', 'subject_id' => $approved->id]);
    }

    public function test_led_score_value_presentation_removes_only_insignificant_decimal_places(): void
    {
        foreach ([
            '4.00' => '4',
            '3.00' => '3',
            '2.00' => '2',
            '1.00' => '1',
            '2.50' => '2,5',
            '2.25' => '2,25',
            null => '–',
        ] as $score => $expected) {
            $rendered = $this->blade('<x-led-score-value :score="$score" />', ['score' => $score]);

            $this->assertSame($expected, trim(strip_tags((string) $rendered)));
        }
    }

    public function test_led_score_rendering_uses_semantic_value_mapping_with_neutral_fallback_and_approved_only_dashboard_result(): void
    {
        $domain = $this->domain('Viewer', 'Viewer');
        $rubrics = collect([
            [4, 'Baik Sekali', 'Kondisi sangat baik.'],
            [2, 'Cukup', 'Kondisi cukup.'],
            [1, 'Kurang', 'Kondisi kurang.'],
            [2.5, 'Skala Lain', 'Fallback untuk instrumen dengan skala berbeda.'],
        ])->map(fn (array $rubric, int $index): AccreditationLedRubric => AccreditationLedRubric::create([
            'indicator_id' => $domain['indicator']->id,
            'category_code' => 'semantic-'.$index,
            'category_label' => $rubric[1],
            'internal_score' => $rubric[0],
            'description' => $rubric[2],
            'position' => $index + 2,
        ]));
        $scoreFour = $rubrics->firstWhere('internal_score', '4.00');
        $workflow = app(LedAssessmentWorkflowService::class);

        $this->actingAs($domain['assessor']);
        $draft = $workflow->saveDraft($domain['assessment'], $domain['assessor'], [
            'rubric_id' => $scoreFour->id,
            'justification' => 'Memverifikasi rendering semantik tanpa mengubah nilai.',
        ]);
        $domain['cycle']->update(['status' => 'draft']);

        Livewire::withQueryParams(['proyek' => $domain['project']->id])
            ->test(LedAssessmentIndex::class)
            ->assertSeeHtml('led-score led-score-tone score-tone-4')
            ->assertSeeHtml('score-tone-4 selected read-only')
            ->assertSeeHtml('class="led-rubric-header"')
            ->assertSeeHtml('data-score-tone="3"')
            ->assertSeeHtml('data-score-tone="2"')
            ->assertSeeHtml('data-score-tone="1"')
            ->assertSeeHtml('data-score-tone="neutral"')
            ->assertSee('Baik Sekali')
            ->assertSee('Baik')
            ->assertSee('Cukup')
            ->assertSee('Kurang')
            ->assertSee('2,5')
            ->assertDontSee('4.00')
            ->assertDontSee('2.50');

        $domain['cycle']->update(['status' => 'active']);

        Livewire::withQueryParams(['proyek' => $domain['project']->id])
            ->test(LedAssessmentDashboard::class)
            ->assertDontSeeHtml('data-approved-score="true"');

        $submitted = $workflow->submit($draft, $domain['assessor']);
        $this->actingAs($domain['reviewer']);
        $approved = $workflow->approve($workflow->startReview($submitted, $domain['reviewer']), $domain['reviewer']);

        Livewire::withQueryParams(['proyek' => $domain['project']->id])
            ->test(LedAssessmentDashboard::class)
            ->assertSeeHtml('led-approved-score led-score-tone score-tone-4')
            ->assertSeeHtml('data-approved-score="true"');

        $this->assertSame('4.00', $approved->rubric->internal_score);
        $this->assertSame('Baik Sekali', $approved->rubric->category_label);

        $stylesheet = file_get_contents(resource_path('css/app.css'));

        foreach ([
            4 => ['#FFF4D6', '#C28A16', '#795000'],
            3 => ['#E8F7EE', '#269764', '#176A42'],
            2 => ['#EAF3FF', '#4385D1', '#1D5796'],
            1 => ['#FDEBEC', '#D44E58', '#9F2733'],
        ] as $score => [$background, $border, $text]) {
            $this->assertStringContainsString("--led-score-{$score}-bg: {$background};", $stylesheet);
            $this->assertStringContainsString("--led-score-{$score}-border: {$border};", $stylesheet);
            $this->assertStringContainsString("--led-score-{$score}-text: {$text};", $stylesheet);
            $this->assertStringContainsString(".score-tone-{$score} { --led-score-bg: var(--led-score-{$score}-bg);", $stylesheet);
        }

        $this->assertStringContainsString('background-color: var(--led-score-bg);', $stylesheet);
        $this->assertStringContainsString('border-color: var(--led-score-border);', $stylesheet);
        $this->assertStringContainsString('background: var(--led-score-badge);', $stylesheet);
    }

    public function test_revision_is_immutable_during_review_and_audit_failure_rolls_back_approval_pointer_and_event(): void
    {
        $domain = $this->domain();
        $workflow = app(LedAssessmentWorkflowService::class);
        $this->actingAs($domain['assessor']);
        $draft = $workflow->saveDraft($domain['assessment'], $domain['assessor'], [
            'rubric_id' => $domain['rubric']->id,
            'justification' => 'Nilai sebelum review.',
        ]);
        $submitted = $workflow->submit($draft, $domain['assessor']);

        try {
            $workflow->saveDraft($domain['assessment']->fresh(), $domain['assessor'], [
                'rubric_id' => $domain['rubric']->id,
                'justification' => 'Perubahan tidak sah.',
            ]);
            $this->fail('Submitted revision must be immutable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assessment', $exception->errors());
            $this->assertSame('Nilai sebelum review.', $submitted->fresh()->justification);
        }

        $this->actingAs($domain['reviewer']);
        $inReview = $workflow->startReview($submitted, $domain['reviewer']);
        Schema::drop('accreditation_activity_logs');

        try {
            $workflow->approve($inReview, $domain['reviewer']);
            $this->fail('Approval must fail when its audit write fails.');
        } catch (\Throwable) {
            $this->assertSame('in_review', $inReview->fresh()->status);
            $this->assertNull($domain['assessment']->fresh()->current_approved_revision_id);
            $this->assertDatabaseMissing('accreditation_led_review_events', ['revision_id' => $inReview->id, 'action' => 'approved']);
        }
    }

    public function test_evidence_linking_derives_and_validates_verified_repository_provenance(): void
    {
        $domain = $this->domain();
        $this->actingAs($domain['assessor']);
        $revision = app(LedAssessmentWorkflowService::class)->saveDraft($domain['assessment'], $domain['assessor'], [
            'rubric_id' => $domain['rubric']->id,
            'justification' => 'Draft dengan bukti.',
        ]);
        [$document, $submission] = $this->verifiedDocument($domain, 'verified');
        AccreditationLedIndicatorItemMapping::create([
            'cycle_id' => $domain['cycle']->id,
            'project_id' => $domain['project']->id,
            'instrument_version_id' => $domain['version']->id,
            'indicator_id' => $domain['indicator']->id,
            'criterion_id' => $document->requirement->item->criterion_id,
            'item_id' => $document->requirement->item_id,
            'mapping_role' => 'primary',
            'created_by' => $domain['manager']->id,
            'created_at' => now(),
        ]);

        $evidence = app(LedAssessmentEvidenceService::class)->linkVerifiedDocument(
            $revision,
            $submission,
            $document,
            $domain['assessor'],
            'primary',
        );

        $this->assertSame($document->revision_number, $evidence->document_revision_number);
        $this->assertSame($document->sha256, $evidence->document_sha256);
        $this->assertSame($submission->id, $evidence->verification_submission_id);
        $this->assertDatabaseHas('accreditation_activity_logs', ['event' => 'assessment.evidence_linked']);

        [$otherDocument, $pending] = $this->verifiedDocument($domain, 'pending_review', 'pending.pdf');
        try {
            app(LedAssessmentEvidenceService::class)->linkVerifiedDocument($revision, $pending, $otherDocument, $domain['assessor']);
            $this->fail('Unverified provenance must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence', $exception->errors());
        }

        [$tamperedDocument, $tamperedSubmission] = $this->verifiedDocument($domain, 'verified', 'tampered.pdf');
        AccreditationLedIndicatorItemMapping::create([
            'cycle_id' => $domain['cycle']->id,
            'project_id' => $domain['project']->id,
            'instrument_version_id' => $domain['version']->id,
            'indicator_id' => $domain['indicator']->id,
            'criterion_id' => $tamperedDocument->requirement->item->criterion_id,
            'item_id' => $tamperedDocument->requirement->item_id,
            'mapping_role' => 'supporting',
            'created_by' => $domain['manager']->id,
            'created_at' => now(),
        ]);
        Storage::disk('local')->put($tamperedDocument->storage_path, 'tampered-after-verification');

        try {
            app(LedAssessmentEvidenceService::class)->linkVerifiedDocument($revision, $tamperedSubmission, $tamperedDocument, $domain['assessor']);
            $this->fail('Stored-byte hash mismatch must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence', $exception->errors());
        }
    }

    public function test_assignment_revocation_requires_actor_reason_and_append_only_event(): void
    {
        $domain = $this->domain();
        $service = app(LedAssessorAssignmentService::class);
        $support = $this->user('Viewer');
        $this->actingAs($domain['manager']);

        try {
            $service->assign($domain['cycle'], $domain['indicator'], $support, 'assessor_support', $domain['manager'], '  ');
            $this->fail('Blank assignment reason must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }

        $created = $service->assign($domain['cycle'], $domain['indicator'], $support, 'assessor_support', $domain['manager'], 'Membantu pengumpulan bukti indikator.');
        $this->assertDatabaseHas('accreditation_led_cycle_events', [
            'action' => 'assignment_created',
            'reason' => 'Membantu pengumpulan bukti indikator.',
        ]);
        $service->revoke($created, $domain['manager'], 'Dukungan assignment selesai.');

        try {
            $service->revoke($domain['assessorAssignment'], $domain['manager'], '  ');
            $this->fail('Blank revocation reason must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('revocation', $exception->errors());
        }

        $revoked = $service->revoke($domain['assessorAssignment'], $domain['manager'], 'Rotasi penilai resmi.');
        $this->assertSame($domain['manager']->id, $revoked->revoked_by);
        $this->assertSame('Rotasi penilai resmi.', $revoked->revocation_reason);
        $this->assertDatabaseHas('accreditation_led_cycle_events', ['action' => 'assignment_revoked', 'actor_id' => $domain['manager']->id]);
    }

    public function test_primary_assignment_can_be_replaced_atomically_in_draft_and_active_while_preserving_draft_creator(): void
    {
        $domain = $this->domain('Viewer', 'Viewer');
        $draftReplacement = $this->user('Viewer');
        $activeReplacement = $this->user('Viewer');
        $assignments = app(LedAssessorAssignmentService::class);
        $workflow = app(LedAssessmentWorkflowService::class);
        $this->actingAs($domain['manager']);
        $domain['cycle']->update(['status' => 'draft']);

        $draftPrimary = $assignments->replace(
            $domain['assessorAssignment'],
            $draftReplacement,
            $domain['manager'],
            'Rotasi Penilai Utama sebelum aktivasi.',
        );

        $this->assertSame('assessor_primary', $draftPrimary->assignment_role);
        $this->assertDatabaseHas('accreditation_led_assessor_assignments', [
            'id' => $domain['assessorAssignment']->id,
            'revocation_reason' => 'Rotasi Penilai Utama sebelum aktivasi.',
        ]);
        $this->assertDatabaseHas('accreditation_led_cycle_events', [
            'action' => 'assignment_replaced',
            'reason' => 'Rotasi Penilai Utama sebelum aktivasi.',
        ]);

        $domain['cycle']->update(['status' => 'active']);
        $this->actingAs($draftReplacement);
        $draft = $workflow->saveDraft($domain['assessment']->fresh(), $draftReplacement, [
            'rubric_id' => $domain['rubric']->id,
            'justification' => 'Draft dibuat sebelum rotasi aktif.',
        ]);

        $this->actingAs($domain['manager']);
        $activePrimary = $assignments->replace(
            $draftPrimary,
            $activeReplacement,
            $domain['manager'],
            'Rotasi Penilai Utama saat cycle aktif.',
        );
        $this->actingAs($activeReplacement);
        $continued = $workflow->saveDraft($domain['assessment']->fresh(), $activeReplacement, [
            'rubric_id' => $domain['rubric']->id,
            'justification' => 'Draft dilanjutkan Penilai Utama pengganti.',
        ]);
        $submitted = $workflow->submit($continued, $activeReplacement);

        $this->assertSame($draft->id, $continued->id);
        $this->assertSame($draftReplacement->id, $continued->created_by);
        $this->assertSame($activeReplacement->id, $submitted->submitted_by);
        $this->assertSame($activeReplacement->id, $activePrimary->user_id);
    }

    public function test_personnel_rotation_does_not_mutate_submitted_in_review_or_approved_revision_history(): void
    {
        $domain = $this->domain('Viewer', 'Viewer');
        $newPrimary = $this->user('Viewer');
        $newReviewer = $this->user('Viewer');
        $approvedPrimary = $this->user('Viewer');
        $approvedReviewer = $this->user('Viewer');
        $assignments = app(LedAssessorAssignmentService::class);
        $workflow = app(LedAssessmentWorkflowService::class);

        $this->actingAs($domain['assessor']);
        $draft = $workflow->saveDraft($domain['assessment'], $domain['assessor'], [
            'rubric_id' => $domain['rubric']->id,
            'justification' => 'Konten historis yang tidak boleh berubah.',
        ]);
        $submitted = $workflow->submit($draft, $domain['assessor']);
        $submittedKeys = ['id', 'status', 'rubric_id', 'justification', 'created_by', 'submitted_by', 'submitted_at'];
        $submittedSnapshot = collect($submitted->toArray())->only($submittedKeys)->all();

        $this->actingAs($domain['manager']);
        $primary = $assignments->replace($domain['assessorAssignment'], $newPrimary, $domain['manager'], 'Rotasi setelah submit.');
        $this->assertSame($submittedSnapshot, collect($submitted->fresh()->toArray())->only($submittedKeys)->all());

        $this->actingAs($domain['reviewer']);
        $inReview = $workflow->startReview($submitted->fresh(), $domain['reviewer']);
        $inReviewKeys = ['id', 'status', 'rubric_id', 'justification', 'created_by', 'submitted_by', 'submitted_at'];
        $inReviewSnapshot = collect($inReview->toArray())->only($inReviewKeys)->all();
        $reviewerAssignment = $domain['cycle']->assignments()
            ->where('indicator_id', $domain['indicator']->id)
            ->where('user_id', $domain['reviewer']->id)
            ->where('assignment_role', 'reviewer')
            ->whereNull('revoked_at')
            ->sole();

        $this->actingAs($domain['manager']);
        $reviewer = $assignments->replace($reviewerAssignment, $newReviewer, $domain['manager'], 'Rotasi saat review berjalan.');
        $this->assertSame($inReviewSnapshot, collect($inReview->fresh()->toArray())->only($inReviewKeys)->all());

        $this->actingAs($newReviewer);
        $approved = $workflow->approve($inReview->fresh(), $newReviewer, 'Keputusan reviewer pengganti.');
        $approvedKeys = [
            'id', 'status', 'rubric_id', 'justification', 'created_by', 'submitted_by', 'submitted_at', 'decided_by', 'decided_at',
        ];
        $approvedSnapshot = collect($approved->toArray())->only($approvedKeys)->all();
        $approvedPointer = $domain['assessment']->fresh()->current_approved_revision_id;
        $approvedScore = (float) $approved->rubric->internal_score;
        $eventCount = $approved->events()->count();

        $this->actingAs($domain['manager']);
        $assignments->replace($primary, $approvedPrimary, $domain['manager'], 'Rotasi setelah approval.');
        $assignments->replace($reviewer, $approvedReviewer, $domain['manager'], 'Rotasi reviewer setelah approval.');

        $this->assertSame($approvedSnapshot, collect($approved->fresh()->toArray())->only($approvedKeys)->all());
        $this->assertSame($approvedPointer, $domain['assessment']->fresh()->current_approved_revision_id);
        $this->assertSame($approvedScore, (float) $approved->fresh('rubric')->rubric->internal_score);
        $this->assertSame($eventCount, $approved->events()->count());
    }

    public function test_submission_requires_reviewer_independent_from_creator_with_effective_project_view(): void
    {
        $domain = $this->domain('Viewer', 'Viewer');
        $replacement = $this->user('Viewer');
        $independentReviewer = $this->user('Viewer');
        $assignments = app(LedAssessorAssignmentService::class);
        $workflow = app(LedAssessmentWorkflowService::class);

        $this->actingAs($domain['assessor']);
        $draft = $workflow->saveDraft($domain['assessment'], $domain['assessor'], [
            'rubric_id' => $domain['rubric']->id,
            'justification' => 'Draft dengan creator yang akan dirotasi.',
        ]);

        $this->actingAs($domain['manager']);
        $assignments->replace($domain['assessorAssignment'], $replacement, $domain['manager'], 'Rotasi creator draft.');
        $reviewerAssignment = $domain['cycle']->assignments()
            ->where('user_id', $domain['reviewer']->id)
            ->where('assignment_role', 'reviewer')
            ->whereNull('revoked_at')
            ->sole();
        $assignments->revoke($reviewerAssignment, $domain['manager'], 'Reviewer lama tidak tersedia.');
        $assignments->assign($domain['cycle'], $domain['indicator'], $domain['assessor'], 'reviewer', $domain['manager'], 'Creator dialihkan sebagai reviewer untuk negative test.');

        $this->actingAs($replacement);
        try {
            $workflow->submit($draft->fresh(), $replacement);
            $this->fail('Revision creator must not satisfy independent reviewer readiness.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assessment', $exception->errors());
            $this->assertSame('draft', $draft->fresh()->status);
        }

        $this->actingAs($domain['manager']);
        $assignments->assign($domain['cycle'], $domain['indicator'], $independentReviewer, 'reviewer', $domain['manager'], 'Menambahkan reviewer independen.');
        $this->actingAs($replacement);
        $submitted = $workflow->submit($draft->fresh(), $replacement);
        $this->assertSame('submitted', $submitted->status);
    }

    public function test_role_capability_and_account_changes_preserve_assignment_history_but_revoke_effective_access(): void
    {
        $domain = $this->domain('Viewer', 'Viewer');
        $admin = $this->user('Super Admin');
        $access = app(UserAccessManagementService::class);
        $assignmentId = $domain['assessorAssignment']->id;
        $this->actingAs($admin);

        $access->syncUserRoles($admin, $domain['assessor'], []);

        $this->assertDatabaseHas('accreditation_led_assessor_assignments', ['id' => $assignmentId, 'revoked_at' => null]);
        $this->assertFalse($domain['assessor']->fresh()->can('project.view'));
        $this->assertFalse(app(LedAssessmentAuthorizationService::class)->canViewCycle($domain['assessor']->fresh(), $domain['cycle']));
        $this->assertSame(1, app(LedAssessmentCycleService::class)->readiness($domain['cycle'])['missing_primary']);

        $viewerRoleId = Role::query()->where('name', 'Viewer')->value('id');
        $access->syncUserRoles($admin, $domain['assessor'], [$viewerRoleId]);
        $access->setUserActive($admin, $domain['assessor'], false);

        $this->assertDatabaseHas('accreditation_led_assessor_assignments', ['id' => $assignmentId, 'revoked_at' => null]);
        $this->assertFalse(app(LedAssessmentAuthorizationService::class)->canViewCycle($domain['assessor']->fresh(), $domain['cycle']));
    }

    public function test_terminal_cycles_reject_assignment_add_replace_and_revoke(): void
    {
        foreach (['closed', 'finalized'] as $status) {
            $domain = $this->domain('Viewer', 'Viewer');
            $replacement = $this->user('Viewer');
            $domain['cycle']->update(['status' => $status]);
            $assignments = app(LedAssessorAssignmentService::class);
            $this->actingAs($domain['manager']);

            foreach (['assign', 'replace', 'revoke'] as $operation) {
                try {
                    match ($operation) {
                        'assign' => $assignments->assign($domain['cycle']->fresh(), $domain['indicator'], $replacement, 'assessor_support', $domain['manager'], 'Perubahan terminal harus ditolak.'),
                        'replace' => $assignments->replace($domain['assessorAssignment'], $replacement, $domain['manager'], 'Rotasi terminal harus ditolak.'),
                        'revoke' => $assignments->revoke($domain['assessorAssignment'], $domain['manager'], 'Revocation terminal harus ditolak.'),
                    };
                    $this->fail("{$operation} must be rejected for {$status} cycle.");
                } catch (AuthorizationException) {
                    $this->assertDatabaseHas('accreditation_led_assessor_assignments', [
                        'id' => $domain['assessorAssignment']->id,
                        'revoked_at' => null,
                    ]);
                }
            }
        }
    }

    public function test_assignment_changes_require_assignment_manage_capability(): void
    {
        $domain = $this->domain('Viewer', 'Viewer');
        $candidate = $this->user('Viewer');
        $managerRole = Role::query()->where('name', 'Project Manager')->firstOrFail();
        $managerRole->revokePermissionTo('assignment.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $domain['manager']->unsetRelation('roles')->unsetRelation('permissions');
        $this->actingAs($domain['manager']);

        $this->assertTrue($domain['manager']->can('project.manage'));
        $this->assertFalse($domain['manager']->can('assignment.manage'));

        try {
            app(LedAssessorAssignmentService::class)->assign(
                $domain['cycle'],
                $domain['indicator'],
                $candidate,
                'assessor_support',
                $domain['manager'],
                'Menguji capability assignment.manage.',
            );
            $this->fail('Assignment mutation must require assignment.manage.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('accreditation_led_assessor_assignments', [
                'cycle_id' => $domain['cycle']->id,
                'indicator_id' => $domain['indicator']->id,
                'user_id' => $candidate->id,
            ]);
        }
    }

    public function test_assessment_index_defaults_to_the_first_assessment_in_the_users_effective_scope(): void
    {
        $domain = $this->domain('Viewer', 'Viewer');
        $laterAssessor = $this->user('Viewer');
        $admin = $this->user('Super Admin');
        $secondIndicator = AccreditationLedIndicator::create([
            'instrument_version_id' => $domain['version']->id,
            'indicator_number' => 2,
            'criterion_code' => 'B',
            'title' => 'Indikator kedua yang ditugaskan',
            'position' => 2,
            'is_assessable' => true,
        ]);
        AccreditationLedRubric::create([
            'indicator_id' => $secondIndicator->id,
            'category_code' => 'baik',
            'category_label' => 'Baik',
            'internal_score' => 3,
            'description' => 'Rubrik indikator kedua.',
            'position' => 1,
        ]);
        $secondAssessment = AccreditationLedAssessment::create([
            'cycle_id' => $domain['cycle']->id,
            'project_id' => $domain['project']->id,
            'instrument_version_id' => $domain['version']->id,
            'indicator_id' => $secondIndicator->id,
        ]);

        $this->actingAs($domain['manager']);
        app(LedAssessorAssignmentService::class)->assign(
            $domain['cycle'],
            $secondIndicator,
            $laterAssessor,
            'assessor_primary',
            $domain['manager'],
            'Menugaskan pengguna hanya ke indikator kedua.',
        );

        $this->actingAs($laterAssessor);
        Livewire::withQueryParams([
            'proyek' => $domain['project']->id,
            'siklus' => $domain['cycle']->id,
        ])->test(LedAssessmentIndex::class)
            ->assertSet('selectedAssessmentId', $secondAssessment->id)
            ->assertSee($secondIndicator->title);
        Livewire::withQueryParams([
            'proyek' => $domain['project']->id,
            'siklus' => $domain['cycle']->id,
            'indikator' => $secondAssessment->id,
        ])->test(LedAssessmentIndex::class)
            ->assertSet('selectedAssessmentId', $secondAssessment->id);
        Livewire::withQueryParams([
            'proyek' => $domain['project']->id,
            'siklus' => $domain['cycle']->id,
            'indikator' => $domain['assessment']->id,
        ])->test(LedAssessmentIndex::class)->assertForbidden();

        foreach ([$domain['manager'], $admin] as $oversightUser) {
            $this->actingAs($oversightUser);
            Livewire::withQueryParams([
                'proyek' => $domain['project']->id,
                'siklus' => $domain['cycle']->id,
            ])->test(LedAssessmentIndex::class)
                ->assertSet('selectedAssessmentId', $domain['assessment']->id);
        }
    }

    public function test_unassigned_user_cannot_open_an_explicit_led_cycle(): void
    {
        $domain = $this->domain('Viewer', 'Viewer');
        $unassigned = $this->user('Viewer');
        AccreditationProjectMember::create([
            'project_id' => $domain['project']->id,
            'user_id' => $unassigned->id,
            'membership_role' => 'viewer',
            'is_active' => true,
        ]);
        $this->actingAs($unassigned);

        Livewire::withQueryParams([
            'proyek' => $domain['project']->id,
            'siklus' => $domain['cycle']->id,
        ])->test(LedAssessmentIndex::class)->assertForbidden();
    }

    public function test_assignment_rechecks_stale_target_user_eligibility_inside_the_transaction(): void
    {
        foreach (['inactive', 'permission_removed'] as $change) {
            $domain = $this->domain('Viewer', 'Viewer');
            $candidate = $this->user('Viewer')->load('roles.permissions', 'permissions');

            if ($change === 'inactive') {
                User::query()->whereKey($candidate->id)->update(['is_active' => false]);
            } else {
                $candidate->roles()->detach();
            }

            $this->assertTrue($candidate->is_active);
            $this->assertTrue($candidate->can('project.view'));
            $this->actingAs($domain['manager']);

            try {
                app(LedAssessorAssignmentService::class)->assign(
                    $domain['cycle'],
                    $domain['indicator'],
                    $candidate,
                    'assessor_support',
                    $domain['manager'],
                    'Menguji eligibility dari state terkunci.',
                );
                $this->fail("Stale target eligibility must be rejected after {$change}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('assignment', $exception->errors());
                $this->assertDatabaseMissing('accreditation_led_assessor_assignments', [
                    'cycle_id' => $domain['cycle']->id,
                    'indicator_id' => $domain['indicator']->id,
                    'user_id' => $candidate->id,
                ]);
            }
        }
    }

    public function test_assignment_rechecks_stale_actor_authorization_inside_the_transaction(): void
    {
        $domain = $this->domain('Viewer', 'Viewer');
        $candidate = $this->user('Viewer');
        $staleManager = $domain['manager']->load('roles.permissions', 'permissions');
        User::query()->whereKey($staleManager->id)->update(['is_active' => false]);
        $this->assertTrue($staleManager->is_active);
        $this->actingAs($staleManager);

        try {
            app(LedAssessorAssignmentService::class)->assign(
                $domain['cycle'],
                $domain['indicator'],
                $candidate,
                'assessor_support',
                $staleManager,
                'Menguji authorization actor dari state terkunci.',
            );
            $this->fail('Stale actor authorization must be rejected inside the transaction.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('accreditation_led_assessor_assignments', [
                'cycle_id' => $domain['cycle']->id,
                'indicator_id' => $domain['indicator']->id,
                'user_id' => $candidate->id,
            ]);
        }
    }

    public function test_inactive_manager_is_rejected_by_led_management_authorization_and_entry_points(): void
    {
        $domain = $this->domain('Viewer', 'Viewer');
        $candidate = $this->user('Viewer');
        $domain['manager']->update(['is_active' => false]);
        $inactiveManager = $domain['manager']->fresh('roles.permissions', 'permissions');
        $authorization = app(LedAssessmentAuthorizationService::class);

        $this->assertFalse($authorization->canManageCycle($inactiveManager, $domain['cycle']));
        $this->assertFalse($authorization->canManageAssignments($inactiveManager, $domain['cycle']));
        $this->actingAs($inactiveManager);

        try {
            app(LedAssessorAssignmentService::class)->assign(
                $domain['cycle'],
                $domain['indicator'],
                $candidate,
                'assessor_support',
                $inactiveManager,
                'Inactive manager must not mutate LED setup.',
            );
            $this->fail('An inactive manager must not create an LED assignment.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('accreditation_led_assessor_assignments', [
                'cycle_id' => $domain['cycle']->id,
                'indicator_id' => $domain['indicator']->id,
                'user_id' => $candidate->id,
            ]);
        }
    }

    public function test_assessment_authorization_is_assignment_scoped_and_reviewer_independence_fails_closed(): void
    {
        $domain = $this->domain();
        $outsider = $this->user('PIC Kriteria');
        $workflow = app(LedAssessmentWorkflowService::class);
        $this->actingAs($outsider);

        try {
            $workflow->saveDraft($domain['assessment'], $outsider, [
                'rubric_id' => $domain['rubric']->id,
                'justification' => 'Tidak boleh tersimpan.',
            ]);
            $this->fail('Unassigned assessor must be rejected.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('accreditation_led_assessment_revisions', 0);
        }

        $this->actingAs($domain['assessor']);
        $draft = $workflow->saveDraft($domain['assessment'], $domain['assessor'], [
            'rubric_id' => $domain['rubric']->id,
            'justification' => 'Draft milik penilai.',
        ]);
        $submitted = $workflow->submit($draft, $domain['assessor']);
        $domain['assessor']->assignRole('Reviewer');
        AccreditationLedAssessorAssignment::create([
            'cycle_id' => $domain['cycle']->id,
            'instrument_version_id' => $domain['version']->id,
            'indicator_id' => $domain['indicator']->id,
            'user_id' => $domain['assessor']->id,
            'assignment_role' => 'reviewer',
            'assigned_by' => $domain['manager']->id,
            'assigned_at' => now(),
        ]);

        try {
            $workflow->startReview($submitted, $domain['assessor']);
            $this->fail('Revision creator must never review their own submission.');
        } catch (AuthorizationException) {
            $this->assertSame('submitted', $submitted->fresh()->status);
        }
    }

    public function test_led_workflow_uses_assignments_without_granting_phase_one_document_roles(): void
    {
        $domain = $this->domain('Viewer', 'Viewer');
        $support = $this->user('Viewer');
        $workflow = app(LedAssessmentWorkflowService::class);

        $this->actingAs($domain['manager']);
        app(LedAssessorAssignmentService::class)->assign(
            $domain['cycle'],
            $domain['indicator'],
            $support,
            'assessor_support',
            $domain['manager'],
            'Menambahkan Penilai Pendukung LED.',
        );

        foreach ([$domain['assessor'], $support, $domain['reviewer']] as $assignedUser) {
            $this->assertTrue($assignedUser->hasExactRoles('Viewer'));
            $this->assertFalse($assignedUser->can('document.upload'));
            $this->assertFalse($assignedUser->can('document.submit'));
            $this->assertFalse($assignedUser->can('document.review'));
            $this->assertFalse($assignedUser->can('document.verify'));
        }

        $this->actingAs($support)
            ->get(route('led-assessment.index', ['proyek' => $domain['project']->id]))
            ->assertOk()
            ->assertSee('Penilaian LED');
        $this->get(route('documents.index'))->assertForbidden();
        $draft = $workflow->saveDraft($domain['assessment'], $support, [
            'rubric_id' => $domain['rubric']->id,
            'justification' => 'Draft dikerjakan Penilai Pendukung tanpa role Phase 1.',
        ]);

        try {
            $workflow->submit($draft, $support);
            $this->fail('Penilai Pendukung must not submit an LED assessment.');
        } catch (AuthorizationException) {
            $this->assertSame('draft', $draft->fresh()->status);
        }

        $this->actingAs($domain['assessor']);
        $submitted = $workflow->submit($draft->fresh(), $domain['assessor']);
        $this->assertSame($domain['assessor']->id, $submitted->submitted_by);

        $this->actingAs($domain['reviewer']);
        $approved = $workflow->approve(
            $workflow->startReview($submitted, $domain['reviewer']),
            $domain['reviewer'],
            'Reviewer LED independen dari role dokumen.',
        );
        $this->assertSame('approved', $approved->status);
    }

    public function test_revoked_assignments_immediately_remove_assessor_and_reviewer_capabilities(): void
    {
        $domain = $this->domain('Viewer', 'Viewer');
        $workflow = app(LedAssessmentWorkflowService::class);
        $assignments = app(LedAssessorAssignmentService::class);

        $this->actingAs($domain['assessor']);
        $draft = $workflow->saveDraft($domain['assessment'], $domain['assessor'], [
            'rubric_id' => $domain['rubric']->id,
            'justification' => 'Draft sebelum assignment dicabut.',
        ]);

        $this->actingAs($domain['manager']);
        $assignments->revoke($domain['assessorAssignment'], $domain['manager'], 'Rotasi Penilai Utama.');

        $this->actingAs($domain['assessor']);
        $this->get(route('led-assessment.index', ['proyek' => $domain['project']->id]))->assertForbidden();
        try {
            $workflow->submit($draft->fresh(), $domain['assessor']);
            $this->fail('A revoked primary assessor must not submit.');
        } catch (AuthorizationException) {
            $this->assertSame('draft', $draft->fresh()->status);
        }

        $this->actingAs($domain['manager']);
        $assignments->assign($domain['cycle'], $domain['indicator'], $domain['assessor'], 'assessor_primary', $domain['manager'], 'Mengaktifkan kembali Penilai Utama setelah rotasi.');
        $reviewerAssignment = $domain['cycle']->assignments()
            ->where('indicator_id', $domain['indicator']->id)
            ->where('user_id', $domain['reviewer']->id)
            ->where('assignment_role', 'reviewer')
            ->whereNull('revoked_at')
            ->sole();

        $this->actingAs($domain['assessor']);
        $submitted = $workflow->submit($draft->fresh(), $domain['assessor']);

        $this->actingAs($domain['manager']);
        $assignments->revoke($reviewerAssignment, $domain['manager'], 'Rotasi Reviewer LED.');

        $this->actingAs($domain['reviewer']);
        try {
            $workflow->startReview($submitted, $domain['reviewer']);
            $this->fail('A revoked reviewer must not start review.');
        } catch (AuthorizationException) {
            $this->assertSame('submitted', $submitted->fresh()->status);
        }
    }

    public function test_led_assignments_do_not_cross_cycle_indicator_or_project_boundaries(): void
    {
        $domain = $this->domain('Viewer', 'Viewer');
        $workflow = app(LedAssessmentWorkflowService::class);
        $secondIndicator = AccreditationLedIndicator::create([
            'instrument_version_id' => $domain['version']->id,
            'indicator_number' => 2,
            'criterion_code' => 'B',
            'title' => 'Indikator di luar assignment',
            'position' => 2,
            'is_assessable' => true,
        ]);
        $secondRubric = AccreditationLedRubric::create([
            'indicator_id' => $secondIndicator->id,
            'category_code' => 'cukup',
            'category_label' => 'Cukup',
            'internal_score' => 2,
            'description' => 'Rubrik indikator kedua.',
            'position' => 1,
        ]);
        $secondAssessment = AccreditationLedAssessment::create([
            'cycle_id' => $domain['cycle']->id,
            'project_id' => $domain['project']->id,
            'instrument_version_id' => $domain['version']->id,
            'indicator_id' => $secondIndicator->id,
        ]);
        $secondCycle = AccreditationLedAssessmentCycle::create([
            'project_id' => $domain['project']->id,
            'instrument_id' => $domain['project']->instrument_id,
            'instrument_version_id' => $domain['version']->id,
            'cycle_number' => 2,
            'cycle_name' => 'Siklus Tanpa Assignment',
            'status' => 'active',
            'created_by' => $domain['manager']->id,
        ]);
        $secondCycleAssessment = AccreditationLedAssessment::create([
            'cycle_id' => $secondCycle->id,
            'project_id' => $domain['project']->id,
            'instrument_version_id' => $domain['version']->id,
            'indicator_id' => $domain['indicator']->id,
        ]);
        $outside = $this->domain('Viewer', 'Viewer');
        $this->actingAs($domain['assessor']);

        foreach ([
            [$secondAssessment, $secondRubric],
            [$secondCycleAssessment, $domain['rubric']],
            [$outside['assessment'], $outside['rubric']],
        ] as [$assessment, $rubric]) {
            try {
                $workflow->saveDraft($assessment, $domain['assessor'], [
                    'rubric_id' => $rubric->id,
                    'justification' => 'Upaya lintas scope harus ditolak.',
                ]);
                $this->fail('An assignment must not authorize another project, cycle, or indicator.');
            } catch (AuthorizationException) {
                $this->assertNull($assessment->fresh()->current_working_revision_id);
            }
        }

        Livewire::withQueryParams(['proyek' => $domain['project']->id, 'siklus' => $secondCycle->id])
            ->test(LedAssessmentIndex::class)
            ->assertForbidden();
        Livewire::withQueryParams([
            'proyek' => $domain['project']->id,
            'siklus' => $domain['cycle']->id,
            'indikator' => $secondAssessment->id,
        ])->test(LedAssessmentIndex::class)->assertForbidden();
        Livewire::withQueryParams([
            'proyek' => $domain['project']->id,
            'siklus' => $domain['cycle']->id,
            'indikator' => $outside['assessment']->id,
        ])->test(LedAssessmentIndex::class)->assertForbidden();

        $scopedSummary = app(LedAssessmentDashboardService::class)->summary($domain['cycle'], $domain['assessor']);
        $this->assertSame(1, $scopedSummary['total']);
        Livewire::withQueryParams(['proyek' => $domain['project']->id, 'siklus' => $secondCycle->id])
            ->test(LedAssessmentDashboard::class)
            ->assertForbidden();
    }

    public function test_user_management_shows_active_and_revoked_led_history_separately_from_phase_one_scope(): void
    {
        $domain = $this->domain('Viewer', 'Viewer');
        $admin = $this->user('Super Admin');
        $assignments = app(LedAssessorAssignmentService::class);

        $this->actingAs($domain['manager']);
        $assignments->revoke($domain['assessorAssignment'], $domain['manager'], 'Perubahan tim penilai.');
        $assignments->assign($domain['cycle'], $domain['indicator'], $domain['assessor'], 'assessor_support', $domain['manager'], 'Mencatat histori Penilai Pendukung.');
        $domain['cycle']->update(['status' => 'finalized']);

        $this->actingAs($admin);
        Livewire::test(UserManagementIndex::class)
            ->set('search', $domain['assessor']->email)
            ->assertSee('Global Role')
            ->assertSee('Fase 1 · Keanggotaan Proyek')
            ->assertSee('Fase 1 · Penugasan Kriteria')
            ->assertSee('Phase 2 · Histori Assignment LED')
            ->assertSee('Penilai Utama')
            ->assertSee('Penilai Pendukung')
            ->assertSee('Aktif')
            ->assertSee('Dicabut')
            ->assertSee('Perubahan tim penilai.')
            ->assertSee($domain['manager']->name)
            ->assertSee($domain['project']->code)
            ->assertSee($domain['cycle']->cycle_name)
            ->assertSee($domain['indicator']->title)
            ->assertSee('Kelola Penugasan LED')
            ->assertSee(route('administration.led-setup.index', [
                'proyek' => $domain['project']->id,
                'siklus' => $domain['cycle']->id,
                'indikator' => $domain['indicator']->id,
            ]));
    }

    public function test_led_livewire_pages_render_scoped_instrument_data_and_reject_project_tampering(): void
    {
        $domain = $this->domain();
        $this->actingAs($domain['assessor']);

        Livewire::withQueryParams(['proyek' => $domain['project']->id])
            ->test(LedAssessmentIndex::class)
            ->assertSee('Penilaian LED')
            ->assertSee('Pelaksanaan SPMI')
            ->assertSee($domain['version']->version_code);

        $outsideProgram = StudyProgram::create(['code' => Str::random(8), 'name' => 'Outside', 'is_active' => true]);
        $outside = AccreditationProject::create([
            'code' => Str::random(10), 'name' => 'Outside Project', 'study_program_id' => $outsideProgram->id,
            'instrument_id' => $domain['project']->instrument_id, 'final_deadline' => '2026-11-30', 'status' => 'active',
        ]);

        Livewire::withQueryParams(['proyek' => $domain['project']->id])
            ->test(LedAssessmentIndex::class)
            ->call('selectProject', $outside->id)
            ->assertForbidden();

        Livewire::withQueryParams(['proyek' => $domain['project']->id])
            ->test(LedAssessmentDashboard::class)
            ->assertSee('Hanya acuan yang disetujui')
            ->assertSee('Belum approved');
    }

    public function test_draft_and_closed_cycles_are_read_only_while_active_cycle_keeps_assignment_authorization(): void
    {
        $domain = $this->domain();
        $this->actingAs($domain['assessor']);
        $domain['cycle']->update(['status' => 'draft']);

        try {
            app(LedAssessmentWorkflowService::class)->saveDraft($domain['assessment']->fresh(), $domain['assessor'], [
                'rubric_id' => $domain['rubric']->id,
                'justification' => 'Tidak boleh tersimpan saat Draft.',
            ]);
            $this->fail('A Draft cycle must be read-only.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('accreditation_led_assessment_revisions', 0);
        }

        Livewire::withQueryParams(['proyek' => $domain['project']->id, 'siklus' => $domain['cycle']->id])
            ->test(LedAssessmentIndex::class)
            ->assertSee('Siklus masih Draft.')
            ->assertSee('Aktifkan melalui Konfigurasi LED sebelum melakukan penilaian.')
            ->assertDontSee('Submit Penilaian');

        $domain['cycle']->update(['status' => 'active']);
        $revision = app(LedAssessmentWorkflowService::class)->saveDraft($domain['assessment']->fresh(), $domain['assessor'], [
            'rubric_id' => $domain['rubric']->id,
            'justification' => 'Penilaian sah pada cycle aktif.',
        ]);
        $this->assertSame('draft', $revision->status);

        $domain['cycle']->update(['status' => 'closed']);
        try {
            app(LedAssessmentWorkflowService::class)->submit($revision->fresh(), $domain['assessor']);
            $this->fail('A Closed cycle must reject submission.');
        } catch (AuthorizationException) {
            $this->assertSame('draft', $revision->fresh()->status);
        }
    }

    private function domain(string $assessorRole = 'PIC Kriteria', string $reviewerRole = 'Reviewer'): array
    {
        $manager = $this->user('Project Manager');
        $assessor = $this->user($assessorRole);
        $reviewer = $this->user($reviewerRole);
        $program = StudyProgram::create(['code' => Str::random(8), 'name' => 'Program LED', 'is_active' => true]);
        $instrument = AccreditationInstrument::create(['code' => Str::random(8), 'name' => 'Instrumen LED']);
        $project = AccreditationProject::create([
            'code' => Str::random(10), 'name' => 'Project LED', 'study_program_id' => $program->id,
            'instrument_id' => $instrument->id, 'final_deadline' => '2026-10-30', 'status' => 'active',
        ]);
        AccreditationProjectMember::create(['project_id' => $project->id, 'user_id' => $manager->id, 'membership_role' => 'project_manager', 'is_active' => true]);
        $version = AccreditationLedInstrumentVersion::create([
            'instrument_id' => $instrument->id, 'version_code' => Str::random(7),
            'assessment_pathway' => 'terakreditasi', 'education_level' => 'S1',
            'source_title' => 'Verified source', 'source_reference' => 'REF', 'source_filename' => 'source.pdf',
            'source_disk' => 'local', 'source_storage_path' => 'source.pdf',
            'source_pdf_sha256' => str_repeat('a', 64), 'rubric_manifest_sha256' => str_repeat('b', 64),
            'status' => 'published', 'published_by' => $manager->id, 'published_at' => now(), 'created_by' => $manager->id,
        ]);
        $indicator = AccreditationLedIndicator::create([
            'instrument_version_id' => $version->id, 'indicator_number' => 1, 'criterion_code' => 'A',
            'title' => 'Pelaksanaan SPMI', 'position' => 1, 'is_assessable' => true,
        ]);
        $rubric = AccreditationLedRubric::create([
            'indicator_id' => $indicator->id, 'category_code' => 'baik', 'category_label' => 'Baik',
            'internal_score' => 3, 'description' => 'Kondisi kategori baik.', 'position' => 1,
        ]);
        $cycle = AccreditationLedAssessmentCycle::create([
            'project_id' => $project->id, 'instrument_id' => $instrument->id, 'instrument_version_id' => $version->id,
            'cycle_number' => 1, 'cycle_name' => 'Siklus 2026', 'status' => 'active', 'created_by' => $manager->id,
        ]);
        $assessment = AccreditationLedAssessment::create([
            'cycle_id' => $cycle->id, 'project_id' => $project->id,
            'instrument_version_id' => $version->id, 'indicator_id' => $indicator->id,
        ]);
        $this->actingAs($manager);
        $assignmentService = app(LedAssessorAssignmentService::class);
        $assessorAssignment = $assignmentService->assign($cycle, $indicator, $assessor, 'assessor_primary', $manager, 'Menetapkan Penilai Utama awal.');
        $assignmentService->assign($cycle, $indicator, $reviewer, 'reviewer', $manager, 'Menetapkan Reviewer independen awal.');

        return compact('manager', 'assessor', 'reviewer', 'project', 'version', 'indicator', 'rubric', 'cycle', 'assessment', 'assessorAssignment');
    }

    private function verifiedDocument(array $domain, string $status, string $name = 'verified.pdf'): array
    {
        $criterion = AccreditationCriterion::create([
            'project_id' => $domain['project']->id, 'code' => Str::random(4), 'name' => 'Kriteria Evidence',
            'color_header' => '#173E70', 'color_body' => '#E7EEF8', 'position' => AccreditationCriterion::where('project_id', $domain['project']->id)->count() + 1,
        ]);
        $item = AccreditationItem::create(['criterion_id' => $criterion->id, 'number' => 1, 'position' => 1, 'title' => 'Item Evidence']);
        $requirement = AccreditationDocumentRequirement::create([
            'item_id' => $item->id, 'number' => 1, 'title' => 'Bukti LED', 'is_required' => true,
            'workflow_status' => $status === 'verified' ? 'verified' : 'pending_review', 'current_review_revision' => 1,
        ]);
        $bytes = Str::uuid().$name;
        $path = 'evidence/'.$name;
        Storage::disk('local')->put($path, $bytes);
        $document = AccreditationDocument::create([
            'requirement_id' => $requirement->id, 'uploaded_by' => $domain['assessor']->id,
            'original_name' => $name, 'disk' => 'local', 'storage_path' => $path, 'storage_uuid' => (string) Str::uuid(),
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'revision_number' => 1, 'is_active' => true, 'uploaded_at' => now(),
        ]);
        $submission = AccreditationReviewSubmission::create([
            'requirement_id' => $requirement->id, 'submitted_by' => $domain['assessor']->id,
            'revision_number' => 1, 'status' => $status, 'submitted_at' => now(),
            'decided_at' => $status === 'verified' ? now() : null,
            'decided_by' => $status === 'verified' ? $domain['reviewer']->id : null,
        ]);
        $submission->documents()->attach($document->id);

        return [$document->load('requirement.item.criterion'), $submission];
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => $role.' '.Str::random(4), 'email' => Str::uuid().'@example.test',
            'password' => Hash::make('ValidPassword!123'), 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
