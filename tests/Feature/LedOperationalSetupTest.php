<?php

namespace Tests\Feature;

use App\Livewire\Administration\LedSetup\Index as LedSetupIndex;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationCriterionAssignment;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationInstrument;
use App\Models\AccreditationItem;
use App\Models\AccreditationLedIndicator;
use App\Models\AccreditationLedInstrumentVersion;
use App\Models\AccreditationLedRubric;
use App\Models\AccreditationProject;
use App\Models\AccreditationProjectMember;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\LedAssessmentCycleService;
use App\Services\LedAssessmentWorkflowService;
use App\Services\LedAssessorAssignmentService;
use App\Services\LedIndicatorItemMappingService;
use App\Services\LedInstrumentImportService;
use App\Services\LedInstrumentVersionService;
use App\Services\LedOperationalSetupBulkService;
use App\Services\LedOrganizationSnapshotService;
use Database\Seeders\AccreditationRolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class LedOperationalSetupTest extends TestCase
{
    protected string $testLocale = 'id';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_verified_import_previews_then_persists_draft_and_publishes_only_with_matching_hashes(): void
    {
        $admin = $this->user('Super Admin');
        $project = $this->project();
        $source = $this->sourcePdf();
        $manifest = $this->manifestFile();
        $service = app(LedInstrumentImportService::class);
        $this->actingAs($admin);

        try {
            $service->preview($project->instrument, $source, UploadedFile::fake()->createWithContent('invalid.json', '{'), $admin);
            $this->fail('Malformed JSON must fail before persistence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('manifestFile', $exception->errors());
            $this->assertDatabaseCount('accreditation_led_instrument_versions', 0);
        }

        $preview = $service->preview($project->instrument, $source, $manifest, $admin);
        $this->assertSame(2, $preview['indicator_count']);
        $this->assertSame(1, $preview['assessable_count']);
        $this->assertSame(2, $preview['rubric_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preview['source_pdf_sha256']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preview['rubric_manifest_sha256']);
        $this->assertDatabaseCount('accreditation_led_instrument_versions', 0);

        $version = $service->import($project->instrument, $source, $manifest, $admin);
        $this->assertSame('draft', $version->status);
        $this->assertNull($version->published_at);
        $this->assertCount(2, $version->indicators);
        Storage::disk('local')->assertExists($version->source_storage_path);
        $this->assertSame($preview['rubric_manifest_sha256'], $version->rubric_manifest_sha256);

        $published = app(LedInstrumentVersionService::class)->publish($version, $admin);
        $this->assertSame('published', $published->status);
        $this->assertSame($admin->id, $published->published_by);
        $this->assertDatabaseHas('administrative_audit_logs', ['event' => 'instrument_version.imported', 'subject_id' => $version->id]);
        $this->assertDatabaseHas('administrative_audit_logs', ['event' => 'instrument_version.published', 'subject_id' => $version->id]);
    }

    public function test_cycle_activation_requires_independent_assignments_but_not_mapping_and_stores_canonical_organization_snapshot(): void
    {
        $manager = $this->user('Project Manager');
        $assessor = $this->user('PIC Kriteria');
        $reviewer = $this->user('Reviewer');
        $replacement = $this->user('PIC Kriteria');
        $project = $this->project($manager);
        [$version, $indicator] = $this->publishedVersion($project, $manager);
        $this->actingAs($manager);
        $cycleService = app(LedAssessmentCycleService::class);
        $cycle = $cycleService->create($project, $version, 'Cycle End-to-End', $manager);
        $assignments = app(LedAssessorAssignmentService::class);
        $this->createOrganizationAssignment($project, $manager);

        $this->assertFalse($cycleService->readiness($cycle)['ready']);
        $this->assertSame([], $cycleService->readiness($cycle)['indicators'][0]['primary_assignees']);
        $this->assertSame([], $cycleService->readiness($cycle)['indicators'][0]['independent_reviewer_assignees']);
        try {
            $cycleService->activate($cycle, $manager);
            $this->fail('Activation without a Lead Assessor must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cycle', $exception->errors());
        }

        $assignments->assign($cycle, $indicator, $assessor, 'assessor_primary', $manager, 'Menetapkan Penilai Utama untuk aktivasi.');

        $this->assertFalse($cycleService->readiness($cycle)['ready']);
        try {
            $cycleService->activate($cycle, $manager);
            $this->fail('Activation without an independent Reviewer must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cycle', $exception->errors());
        }

        $assessor->assignRole('Reviewer');
        try {
            $assignments->assign($cycle, $indicator, $assessor->fresh('roles'), 'reviewer', $manager, 'Negative test independensi reviewer.');
            $this->fail('The same user cannot be assessor and reviewer for one indicator.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }

        $assignments->assign($cycle, $indicator, $reviewer, 'reviewer', $manager, 'Menetapkan Reviewer untuk aktivasi.');

        $readiness = $cycleService->readiness($cycle);
        $this->assertTrue($readiness['ready']);
        $this->assertSame(1, $readiness['configured']);
        $this->assertSame(1, $readiness['missing_mapping']);
        $activated = $cycleService->activate($cycle, $manager);
        $activeReadiness = $cycleService->readiness($activated);
        $this->assertSame([$assessor->name], $activeReadiness['indicators'][0]['primary_assignees']);
        $this->assertSame([$reviewer->name], $activeReadiness['indicators'][0]['independent_reviewer_assignees']);

        $lead = $cycle->assignments()->where('indicator_id', $indicator->id)->where('assignment_role', 'assessor_primary')->whereNull('revoked_at')->sole();
        $assignments->replace($lead, $replacement, $manager, 'Mengganti Penilai Utama setelah aktivasi.');
        $reassignedReadiness = $cycleService->readiness($activated);
        $this->assertSame([$replacement->name], $reassignedReadiness['indicators'][0]['primary_assignees']);
        $this->assertNotContains($assessor->name, $reassignedReadiness['indicators'][0]['primary_assignees']);

        $item = $this->documentItem($project);
        $mappingService = app(LedIndicatorItemMappingService::class);
        $mapping = $mappingService->map($activated, $indicator, $item, 'primary', $manager);
        $revoked = $mappingService->revoke($mapping, $manager, 'Mapping diganti setelah verifikasi scope.');
        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame('Mapping diganti setelah verifikasi scope.', $revoked->revoke_reason);
        $mappingService->map($activated, $indicator, $item, 'supporting', $manager);
        $snapshotJson = app(LedOrganizationSnapshotService::class)->json($activated->organization_snapshot_json);

        $this->assertSame('active', $activated->status);
        $this->assertSame(hash('sha256', $snapshotJson), $activated->organization_snapshot_sha256);
        $this->assertSame($project->code, $activated->organization_snapshot_json['project']['code']);
        $this->assertCount(1, $activated->organization_snapshot_json['unit_assignments']);
        $this->assertDatabaseHas('accreditation_led_cycle_events', ['cycle_id' => $cycle->id, 'action' => 'mapping_revoked']);
        $this->assertDatabaseHas('accreditation_led_cycle_events', ['cycle_id' => $cycle->id, 'action' => 'activated']);
    }

    public function test_mapping_rejects_cross_project_scope_and_active_cycle_changes_remain_audited(): void
    {
        $manager = $this->user('Project Manager');
        $assessor = $this->user('PIC Kriteria');
        $reviewer = $this->user('Reviewer');
        $project = $this->project($manager);
        [$version, $indicator] = $this->publishedVersion($project, $manager);
        $outside = $this->project();
        $outsideItem = $this->documentItem($outside);
        $this->actingAs($manager);
        $cycle = app(LedAssessmentCycleService::class)->create($project, $version, 'Scoped Cycle', $manager);

        try {
            app(LedIndicatorItemMappingService::class)->map($cycle, $indicator, $outsideItem, 'primary', $manager);
            $this->fail('Cross-project item mapping must fail closed.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('accreditation_led_indicator_item_mappings', 0);
        }

        app(LedAssessorAssignmentService::class)->assign($cycle, $indicator, $assessor, 'assessor_primary', $manager, 'Menetapkan Penilai Utama awal.');
        app(LedAssessorAssignmentService::class)->assign($cycle, $indicator, $reviewer, 'reviewer', $manager, 'Menetapkan Reviewer awal.');
        app(LedIndicatorItemMappingService::class)->map($cycle, $indicator, $this->documentItem($project), 'primary', $manager);
        app(LedAssessmentCycleService::class)->activate($cycle, $manager);

        $support = app(LedAssessorAssignmentService::class)->assign($cycle->fresh(), $indicator, $this->user('PIC Kriteria'), 'assessor_support', $manager, 'Menambahkan dukungan saat cycle aktif.');
        $revoked = app(LedAssessorAssignmentService::class)->revoke($support, $manager, 'Rotasi operasional setelah aktivasi.');
        $this->assertNotNull($revoked->revoked_at);
        $this->assertDatabaseHas('accreditation_led_cycle_events', ['cycle_id' => $cycle->id, 'action' => 'assignment_revoked']);
    }

    public function test_single_mapping_requires_matching_criterion_and_allows_relink_after_revocation(): void
    {
        $manager = $this->user('Project Manager');
        $project = $this->project($manager);
        [$version, $indicator] = $this->publishedVersion($project, $manager);
        $matchingItem = $this->documentItem($project, 'A');
        $mismatchedItem = $this->documentItem($project, 'B');
        $outsideItem = $this->documentItem($this->project(), 'A');
        $this->actingAs($manager);
        $cycle = app(LedAssessmentCycleService::class)->create($project, $version, 'Criterion Mapping', $manager);
        $mappings = app(LedIndicatorItemMappingService::class);

        $mapping = $mappings->map($cycle, $indicator, $matchingItem, 'primary', $manager);
        $this->assertSame('A', $mapping->item->criterion->code);
        $mappings->revoke($mapping, $manager, 'Mengganti peran mapping yang tetap satu kriteria.');
        $replacement = $mappings->map($cycle, $indicator, $matchingItem, 'supporting', $manager);
        $this->assertSame('supporting', $replacement->mapping_role);

        foreach ([$mismatchedItem, $outsideItem] as $invalidItem) {
            try {
                $mappings->map($cycle, $indicator, $invalidItem, 'primary', $manager);
                $this->fail('Cross-criterion and cross-project mappings must fail closed.');
            } catch (AuthorizationException) {
                $this->assertDatabaseMissing('accreditation_led_indicator_item_mappings', [
                    'cycle_id' => $cycle->id,
                    'item_id' => $invalidItem->id,
                    'revoked_at' => null,
                ]);
            }
        }
    }

    public function test_setup_ui_is_project_scoped_and_reports_real_readiness_without_fictitious_data(): void
    {
        $manager = $this->user('Project Manager');
        $viewer = $this->user('Viewer');
        $project = $this->project($manager);
        AccreditationProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $viewer->id,
            'membership_role' => 'viewer',
            'is_active' => true,
        ]);
        $outside = $this->project();

        $this->actingAs($viewer)
            ->get(route('administration.led-setup.index', ['proyek' => $project->id]))
            ->assertForbidden();

        $this->actingAs($manager)
            ->get(route('administration.led-setup.index', ['proyek' => $project->id]))
            ->assertOk()
            ->assertSee('Belum ada versi instrumen yang dipublikasikan')
            ->assertSee('Tidak ada konfigurasi indikator atau rubrik yang dibuat secara otomatis.');

        Livewire::withQueryParams(['proyek' => $project->id])
            ->test(LedSetupIndex::class)
            ->assertSee('Konfigurasi Operasional LED')
            ->call('selectProject', $outside->id)
            ->assertForbidden();
    }

    public function test_super_admin_can_complete_operational_setup_through_livewire(): void
    {
        $admin = $this->user('Super Admin');
        $assessor = $this->user('PIC Kriteria');
        $reviewer = $this->user('Reviewer');
        $project = $this->project();
        $this->actingAs($admin);

        $component = Livewire::withQueryParams(['proyek' => $project->id])
            ->test(LedSetupIndex::class)
            ->set('sourcePdf', $this->sourcePdf())
            ->set('manifestFile', $this->manifestFile())
            ->call('previewImport')
            ->assertSet('importPreview.metadata.version_code', '2026.LED.1')
            ->assertSee('Draf belum disimpan')
            ->call('saveImport')
            ->assertSet('importPreview', null)
            ->assertHasNoErrors();

        $version = AccreditationLedInstrumentVersion::query()->sole();
        $component
            ->call('publishVersion', $version->id)
            ->set('versionId', $version->id)
            ->set('cycleName', 'Cycle dari UI')
            ->call('createCycle')
            ->assertSee('Kesiapan Aktivasi')
            ->assertHasNoErrors();

        $cycle = $project->assessmentCycles()->sole();
        $indicator = $cycle->assessments()->sole()->indicator;
        $component
            ->assertSee('Pemetaan tidak wajib untuk aktivasi awal')
            ->set('indicatorId', $indicator->id)
            ->set('assignmentRole', 'assessor_primary')
            ->set('assignmentUserId', $assessor->id)
            ->call('openAssignmentConfirmation')
            ->set('confirmationReason', 'Menetapkan Penilai Utama dari UI.')
            ->call('confirmOperation')
            ->set('assignmentRole', 'reviewer')
            ->set('assignmentUserId', $reviewer->id)
            ->call('openAssignmentConfirmation')
            ->set('confirmationReason', 'Menetapkan Reviewer dari UI.')
            ->call('confirmOperation')
            ->assertSee('1 / 1 indikator')
            ->assertSee($assessor->name)
            ->assertSee($reviewer->name)
            ->call('activateCycle')
            ->assertSee('Siklus Cycle dari UI aktif')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('accreditation_led_instrument_versions', ['id' => $version->id, 'status' => 'published']);
        $this->assertDatabaseHas('accreditation_led_assessment_cycles', ['project_id' => $project->id, 'cycle_name' => 'Cycle dari UI', 'status' => 'active']);
    }

    public function test_operational_setup_keeps_bulk_feedback_and_validation_in_the_owning_panel(): void
    {
        $manager = $this->user('Project Manager');
        $primary = $this->user('PIC Kriteria');
        $replacement = $this->user('PIC Kriteria');
        $project = $this->project($manager);
        $criterion = $this->criterion($project, 'A');
        $this->assignCriterion($criterion, $primary, 'pic');
        $this->assignCriterion($criterion, $replacement, 'pic');
        [$version, $indicator] = $this->publishedVersion($project, $manager);
        $item = $this->itemWithRequirement($criterion, 1);
        $secondItem = $this->itemWithRequirement($criterion, 2);
        $this->actingAs($manager);
        $cycle = app(LedAssessmentCycleService::class)->create($project, $version, 'Contextual Setup Feedback', $manager);

        $component = Livewire::withQueryParams(['proyek' => $project->id, 'siklus' => $cycle->id])
            ->test(LedSetupIndex::class)
            ->set('bulkAssignmentIndicatorIds', [$indicator->id])
            ->set('bulkPrimaryUserId', $primary->id)
            ->set('bulkAssignmentReason', '')
            ->call('previewBulkAssignments')
            ->assertHasErrors(['bulk_assignment_reason'])
            ->assertSet('bulkAssignmentIndicatorIds', [$indicator->id])
            ->assertSet('bulkPrimaryUserId', $primary->id)
            ->assertSee('Alasan penugasan massal wajib diisi.')
            ->assertSee('bulk-assignment-reason-error');

        $html = $component->html();
        $this->assertSame(1, substr_count($html, 'id="bulk-assignment-reason"'));
        $this->assertSame(1, substr_count($html, 'id="bulk-assignment-reason-error"'));
        $this->assertSame(1, substr_count($html, 'Alasan penugasan massal wajib diisi.'));
        $this->assertSame(2, substr_count($html, 'class="setup-required" aria-hidden="true"'));
        $this->assertSame(2, substr_count($html, 'aria-required="true"'));
        $this->assertStringNotContainsString('setup-required-text', $html);
        $this->assertStringContainsString('Wajib hanya saat mencabut pemetaan.', $html);
        $this->assertMatchesRegularExpression('/id="bulk-assignment-reason" class="\s*setup-input-invalid\s*"/', $html);
        $this->assertStringContainsString('aria-invalid="true" aria-describedby="bulk-assignment-reason-error"', $html);
        $this->assertMatchesRegularExpression('/x-init="\$nextTick\(\(\) =>\s*document\.getElementById\(\'bulk-assignment-reason\'\)\?\.focus\(\)\)"/', $html);
        $this->assertStringNotContainsString('<div class="led-error" role="alert">Alasan penugasan massal wajib diisi.</div>', $html);
        $this->assertStringNotContainsString('data-feedback-scope="bulk-mapping"', $html);

        $component
            ->set('bulkAssignmentReason', 'Penugasan awal untuk validasi umpan balik lokal.')
            ->call('previewBulkAssignments')
            ->assertHasNoErrors()
            ->assertSet('bulkAssignmentPreview.create_count', 1)
            ->assertSet('bulkAssignmentMessage', 'Pratinjau penugasan massal siap. Periksa semua relasi sebelum diterapkan.')
            ->call('openConfirmation', 'bulk_assignment')
            ->assertSet('confirmationAction', 'bulk_assignment')
            ->assertSee('Konfirmasi Penugasan Massal');

        $html = $component->html();
        $this->assertStringContainsString('role="dialog" aria-modal="true"', $html);
        $this->assertStringContainsString('x-on:keydown.escape="$wire.cancelConfirmation().then(', $html);
        $this->assertStringContainsString('data-confirm-cancel', $html);
        $this->assertStringContainsString('1 akan dibuat', $html);
        $this->assertStringNotContainsString('wire:click="applyBulkAssignments" wire:confirm', $html);
        $this->assertDatabaseCount('accreditation_led_assessor_assignments', 0);

        $component
            ->call('cancelConfirmation')
            ->assertSet('confirmationAction', null);
        $this->assertDatabaseCount('accreditation_led_assessor_assignments', 0);

        $component
            ->call('openConfirmation', 'bulk_assignment')
            ->call('confirmOperation')
            ->assertSet('bulkAssignmentIndicatorIds', [])
            ->assertSet('bulkPrimaryUserId', null)
            ->assertSet('bulkAssignmentMessage', 'Penugasan massal selesai: 1 dibuat, 0 dilewati.')
            ->assertSet('bulkMappingMessage', null);

        $html = $component->html();
        $this->assertSame(1, substr_count($html, 'data-feedback-scope="bulk-assignment"'));
        $this->assertSame(1, substr_count($html, 'Penugasan massal selesai: 1 dibuat, 0 dilewati.'));
        $this->assertStringNotContainsString('data-feedback-scope="bulk-mapping"', $html);

        $component
            ->set('bulkAssignmentIndicatorIds', [$indicator->id])
            ->set('bulkPrimaryUserId', $replacement->id)
            ->set('bulkAssignmentReason', 'Mengganti Penilai Utama tanpa pencabutan.')
            ->call('previewBulkAssignments')
            ->assertSet('bulkAssignmentPreview.conflict_count', 1)
            ->call('applyBulkAssignments')
            ->assertHasErrors(['assignment'])
            ->assertSet('bulkAssignmentMessage', null)
            ->assertSet('bulkAssignmentIndicatorIds', [$indicator->id])
            ->assertSet('bulkPrimaryUserId', $replacement->id)
            ->assertDontSee('Penugasan massal selesai: 1 dibuat, 0 dilewati.')
            ->assertSet('bulkMappingMessage', null);

        $html = $component->html();
        $this->assertSame(1, substr_count($html, 'Penilai Utama aktif sudah tersedia.'));
        $this->assertStringNotContainsString('data-feedback-scope="assignment"', $html);

        $component
            ->set('bulkMappingIndicatorIds', [$indicator->id])
            ->set('bulkMappingItemIds', [$item->id])
            ->set('bulkMappingRole', 'primary')
            ->call('previewBulkMappings')
            ->assertHasNoErrors()
            ->assertSet('bulkMappingPreview.create_count', 1)
            ->assertSet('bulkMappingMessage', 'Pratinjau pemetaan massal siap. Periksa setiap relasi indikator-item sebelum diterapkan.')
            ->call('openConfirmation', 'bulk_mapping')
            ->assertSet('confirmationAction', 'bulk_mapping')
            ->assertSee('Konfirmasi Pemetaan Massal Indikator-Item')
            ->call('confirmOperation')
            ->assertSet('bulkMappingIndicatorIds', [])
            ->assertSet('bulkMappingItemIds', [])
            ->assertSet('bulkMappingMessage', 'Pemetaan massal selesai: 1 dibuat, 0 dilewati.');

        $html = $component->html();
        $this->assertSame(1, substr_count($html, 'data-feedback-scope="bulk-mapping"'));
        $this->assertSame(1, substr_count($html, 'Pemetaan massal selesai: 1 dibuat, 0 dilewati.'));

        $component
            ->set('assignmentRole', 'assessor_support')
            ->set('assignmentUserId', $replacement->id)
            ->call('openAssignmentConfirmation')
            ->set('confirmationReason', 'Menambahkan Penilai Pendukung melalui panel indikator.')
            ->call('confirmOperation')
            ->assertHasNoErrors()
            ->assertSet('assignmentMessage', $replacement->name.' ditugaskan sebagai Penilai Pendukung.');

        $html = $component->html();
        $this->assertSame(1, substr_count($html, 'data-feedback-scope="assignment"'));
        $this->assertStringNotContainsString('data-feedback-scope="mapping"', $html);

        $component
            ->set('mappingRole', 'primary')
            ->set('mappingItemId', $secondItem->id)
            ->call('createMapping')
            ->assertHasNoErrors()
            ->assertSet('mappingMessage', 'Item 2 ditautkan sebagai Utama.');

        $html = $component->html();
        $this->assertSame(1, substr_count($html, 'data-feedback-scope="mapping"'));

        $this->assertDatabaseHas('accreditation_led_cycle_events', [
            'cycle_id' => $cycle->id,
            'action' => 'bulk_assignments_applied',
            'reason' => 'Penugasan awal untuk validasi umpan balik lokal.',
        ]);
        $this->assertDatabaseHas('accreditation_led_cycle_events', [
            'cycle_id' => $cycle->id,
            'action' => 'bulk_mappings_applied',
        ]);
    }

    public function test_bulk_assignment_preview_is_read_only_scoped_idempotent_and_atomic_on_conflict(): void
    {
        $manager = $this->user('Project Manager');
        $primary = $this->user('PIC Kriteria');
        $support = $this->user('PIC Kriteria');
        $reviewer = $this->user('Reviewer');
        $replacement = $this->user('PIC Kriteria');
        $project = $this->project($manager);
        $criterion = $this->criterion($project, 'A');
        foreach ([$primary, $support, $replacement] as $assessor) {
            $this->assignCriterion($criterion, $assessor, 'pic');
        }
        $this->assignCriterion($criterion, $reviewer, 'reviewer');
        [$version, $firstIndicator] = $this->publishedVersion($project, $manager);
        $secondIndicator = AccreditationLedIndicator::create([
            'instrument_version_id' => $version->id,
            'indicator_number' => 2,
            'criterion_code' => 'A',
            'title' => 'Indikator Operasional Kedua',
            'position' => 2,
            'is_assessable' => true,
        ]);
        $this->actingAs($manager);
        $cycle = app(LedAssessmentCycleService::class)->create($project, $version, 'Bulk Assignment', $manager);
        $bulk = app(LedOperationalSetupBulkService::class);
        $indicatorIds = [$firstIndicator->id, $secondIndicator->id];
        $users = [
            'assessor_primary' => $primary->id,
            'assessor_support' => $support->id,
            'reviewer' => $reviewer->id,
        ];

        $reason = 'Konfigurasi awal bulk assignment.';
        $preview = $bulk->previewAssignments($cycle, $indicatorIds, $users, $reason, $manager);
        $this->assertSame(6, $preview['create_count']);
        $this->assertSame(0, $preview['conflict_count']);
        $this->assertTrue($preview['can_apply']);
        $this->assertDatabaseCount('accreditation_led_assessor_assignments', 0);
        $this->assertDatabaseMissing('accreditation_led_cycle_events', ['action' => 'bulk_assignments_applied']);

        $result = $bulk->applyAssignments($cycle, $indicatorIds, $users, $reason, $preview['fingerprint'], $manager);
        $this->assertSame(['created_count' => 6, 'skipped_count' => 0], $result);
        $this->assertDatabaseCount('accreditation_led_assessor_assignments', 6);
        $this->assertDatabaseHas('accreditation_led_cycle_events', [
            'cycle_id' => $cycle->id,
            'action' => 'bulk_assignments_applied',
            'reason' => $reason,
        ]);

        $idempotent = $bulk->previewAssignments($cycle, $indicatorIds, $users, $reason, $manager);
        $eventCount = DB::table('accreditation_led_cycle_events')->where('action', 'bulk_assignments_applied')->count();
        $this->assertSame(0, $idempotent['create_count']);
        $this->assertSame(6, $idempotent['skipped_count']);
        $bulk->applyAssignments($cycle, $indicatorIds, $users, $reason, $idempotent['fingerprint'], $manager);
        $this->assertSame($eventCount, DB::table('accreditation_led_cycle_events')->where('action', 'bulk_assignments_applied')->count());

        $conflict = $bulk->previewAssignments($cycle, $indicatorIds, ['assessor_primary' => $replacement->id], $reason, $manager);
        $this->assertSame(2, $conflict['conflict_count']);
        try {
            $bulk->applyAssignments($cycle, $indicatorIds, ['assessor_primary' => $replacement->id], $reason, $conflict['fingerprint'], $manager);
            $this->fail('A conflicting batch must be rejected before any row is written.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }
        $this->assertDatabaseMissing('accreditation_led_assessor_assignments', ['user_id' => $replacement->id]);
    }

    public function test_bulk_assignment_rejects_users_without_project_view_and_stale_preview_without_partial_write(): void
    {
        $manager = $this->user('Project Manager');
        $scoped = $this->user('PIC Kriteria');
        $ineligible = User::create([
            'name' => 'Tanpa Akses Project',
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('ValidPassword!123'),
            'is_active' => true,
        ]);
        $concurrent = $this->user('PIC Kriteria');
        $project = $this->project($manager);
        $criterion = $this->criterion($project, 'A');
        $this->assignCriterion($criterion, $scoped, 'pic');
        $this->assignCriterion($criterion, $concurrent, 'pic');
        [$version, $indicator] = $this->publishedVersion($project, $manager);
        $this->actingAs($manager);
        $cycle = app(LedAssessmentCycleService::class)->create($project, $version, 'Stale Bulk Assignment', $manager);
        $bulk = app(LedOperationalSetupBulkService::class);

        try {
            $bulk->previewAssignments($cycle, [$indicator->id], ['assessor_support' => $ineligible->id], 'Bulk assignment user tanpa akses.', $manager);
            $this->fail('A user without project.view capability must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bulk_assignment_users', $exception->errors());
        }

        $reason = 'Bulk assignment untuk pengujian stale preview.';
        $preview = $bulk->previewAssignments($cycle, [$indicator->id], ['assessor_support' => $scoped->id], $reason, $manager);
        app(LedAssessorAssignmentService::class)->assign($cycle, $indicator, $concurrent, 'assessor_support', $manager, 'Perubahan concurrent assignment.');
        try {
            $bulk->applyAssignments($cycle, [$indicator->id], ['assessor_support' => $scoped->id], $reason, $preview['fingerprint'], $manager);
            $this->fail('A stale preview must reject the whole batch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }
        $this->assertDatabaseMissing('accreditation_led_assessor_assignments', ['user_id' => $scoped->id]);
    }

    public function test_bulk_assignment_supports_separate_indicator_groups_without_replacing_existing_assignments(): void
    {
        $manager = $this->user('Project Manager');
        $ardha = $this->user('PIC Kriteria');
        $latifah = $this->user('PIC Kriteria');
        $annas = $this->user('Reviewer');
        $poppy = $this->user('PIC Kriteria');
        $eko = $this->user('PIC Kriteria');
        $buanasari = $this->user('Reviewer');
        $dian = $this->user('PIC Kriteria');
        foreach (compact('ardha', 'latifah', 'annas', 'poppy', 'eko', 'buanasari', 'dian') as $name => $user) {
            $user->update(['name' => ucfirst($name)]);
        }

        $project = $this->project($manager);
        $criterion = $this->criterion($project, 'A');
        foreach ([$ardha, $latifah, $poppy, $eko, $dian] as $assessor) {
            $this->assignCriterion($criterion, $assessor, 'pic');
        }
        foreach ([$annas, $buanasari] as $reviewer) {
            $this->assignCriterion($criterion, $reviewer, 'reviewer');
        }

        [$version, $firstIndicator] = $this->publishedVersion($project, $manager);
        $indicators = [$firstIndicator];
        for ($number = 2; $number <= 6; $number++) {
            $indicators[] = AccreditationLedIndicator::create([
                'instrument_version_id' => $version->id,
                'indicator_number' => $number,
                'criterion_code' => 'A',
                'title' => "Indikator Kelompok {$number}",
                'position' => $number,
                'is_assessable' => true,
            ]);
        }

        $this->actingAs($manager);
        $cycle = app(LedAssessmentCycleService::class)->create($project, $version, 'Kelompok Penugasan Terpisah', $manager);
        $bulk = app(LedOperationalSetupBulkService::class);
        $groups = [
            [[$indicators[0]->id, $indicators[1]->id], ['assessor_primary' => $ardha->id, 'assessor_support' => $latifah->id, 'reviewer' => $annas->id], 6],
            [[$indicators[2]->id], ['assessor_primary' => $poppy->id, 'assessor_support' => $eko->id, 'reviewer' => $buanasari->id], 3],
            [[$indicators[3]->id, $indicators[4]->id, $indicators[5]->id], ['assessor_primary' => $ardha->id, 'assessor_support' => $dian->id, 'reviewer' => $buanasari->id], 9],
        ];

        foreach ($groups as [$indicatorIds, $users, $expectedCreated]) {
            $preview = $bulk->previewAssignments($cycle, $indicatorIds, $users, 'Konfigurasi kelompok penugasan terpisah.', $manager);
            $this->assertSame($expectedCreated, $preview['create_count']);
            $this->assertSame(0, $preview['skipped_count']);
            $this->assertSame(0, $preview['conflict_count']);
            $this->assertTrue($preview['can_apply']);
            $this->assertSame(['created_count' => $expectedCreated, 'skipped_count' => 0], $bulk->applyAssignments($cycle, $indicatorIds, $users, 'Konfigurasi kelompok penugasan terpisah.', $preview['fingerprint'], $manager));
        }

        $this->assertDatabaseCount('accreditation_led_assessor_assignments', 18);
        foreach ([
            [$indicators[0], $ardha, $latifah, $annas],
            [$indicators[1], $ardha, $latifah, $annas],
            [$indicators[2], $poppy, $eko, $buanasari],
            [$indicators[3], $ardha, $dian, $buanasari],
            [$indicators[4], $ardha, $dian, $buanasari],
            [$indicators[5], $ardha, $dian, $buanasari],
        ] as [$indicator, $primary, $support, $reviewer]) {
            $activeAssignments = DB::table('accreditation_led_assessor_assignments')
                ->where('cycle_id', $cycle->id)
                ->where('indicator_id', $indicator->id)
                ->whereNull('revoked_at')
                ->orderBy('assignment_role')
                ->get(['assignment_role', 'user_id'])
                ->map(fn ($assignment): array => [$assignment->assignment_role, $assignment->user_id])
                ->all();
            $this->assertSame([
                ['assessor_primary', $primary->id],
                ['assessor_support', $support->id],
                ['reviewer', $reviewer->id],
            ], $activeAssignments);
        }

        $primaryConflict = $bulk->previewAssignments($cycle, [$indicators[0]->id], ['assessor_primary' => $poppy->id], 'Penggantian Lead harus eksplisit.', $manager);
        $this->assertSame(1, $primaryConflict['conflict_count']);
        $this->assertFalse($primaryConflict['can_apply']);
        foreach ([$ardha, $latifah] as $activeAssessor) {
            $reviewerConflict = $bulk->previewAssignments($cycle, [$indicators[0]->id], ['reviewer' => $activeAssessor->id], 'Reviewer harus independen.', $manager);
            $this->assertSame(1, $reviewerConflict['conflict_count']);
            $this->assertFalse($reviewerConflict['can_apply']);
        }

        try {
            $bulk->applyAssignments($cycle, [$indicators[0]->id], ['assessor_primary' => $poppy->id], 'Penggantian Lead harus eksplisit.', $primaryConflict['fingerprint'], $manager);
            $this->fail('Bulk assignment must not replace an existing Lead Assessor.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }
        $this->assertDatabaseCount('accreditation_led_assessor_assignments', 18);
    }

    public function test_livewire_assignment_revocation_confirms_only_the_selected_assignment_and_preserves_history(): void
    {
        $admin = $this->user('Super Admin');
        $ardha = $this->user('PIC Kriteria');
        $latifah = $this->user('PIC Kriteria');
        $annas = $this->user('Reviewer');
        $eko = $this->user('PIC Kriteria');
        foreach (compact('ardha', 'latifah', 'annas', 'eko') as $name => $user) {
            $user->update(['name' => ucfirst($name)]);
        }

        $project = $this->project($admin);
        $criterion = $this->criterion($project, 'A');
        $item = $this->itemWithRequirement($criterion, 2);
        foreach ([$ardha, $latifah, $eko] as $assessor) {
            $this->assignCriterion($criterion, $assessor, 'pic');
        }
        $this->assignCriterion($criterion, $annas, 'reviewer');
        [$version, $indicator] = $this->publishedVersion($project, $admin);
        $this->actingAs($admin);
        $cycle = app(LedAssessmentCycleService::class)->create($project, $version, 'Siklus LED Biotech 2026', $admin);
        $cycle->update(['status' => 'active']);
        $assignments = app(LedAssessorAssignmentService::class);
        $lead = $assignments->assign($cycle, $indicator, $ardha, 'assessor_primary', $admin, 'Penugasan Lead Assessor A2.');
        $latifahAssignment = $assignments->assign($cycle, $indicator, $latifah, 'assessor_support', $admin, 'Penugasan Supporting Assessor A2.');
        $reviewer = $assignments->assign($cycle, $indicator, $annas, 'reviewer', $admin, 'Penugasan Reviewer A2.');
        $ekoAssignment = $assignments->assign($cycle, $indicator, $eko, 'assessor_support', $admin, 'Penugasan Supporting Assessor tambahan A2.');
        $mapping = app(LedIndicatorItemMappingService::class)->map($cycle, $indicator, $item, 'primary', $admin);

        $this->actingAs($ardha);
        $draft = app(LedAssessmentWorkflowService::class)->saveDraft($cycle->assessments()->where('indicator_id', $indicator->id)->sole(), $ardha, [
            'rubric_id' => $indicator->rubrics()->sole()->id,
            'justification' => 'Draft A2 harus tetap utuh saat rotasi Supporting Assessor.',
        ]);
        $draftSnapshot = collect($draft->toArray())->only(['id', 'status', 'rubric_id', 'justification', 'created_by'])->all();

        $this->actingAs($admin);
        $reason = 'Pergantian Supporting Assessor A2 dari Latifah kepada Eko untuk simulasi UAT.';
        $component = Livewire::withQueryParams(['proyek' => $project->id, 'siklus' => $cycle->id, 'indikator' => $indicator->id])
            ->test(LedSetupIndex::class)
            ->assertSeeHtml('wire:submit="openAssignmentConfirmation"')
            ->call('openConfirmation', 'revoke_assignment', $latifahAssignment->id)
            ->set('confirmationReason', $reason)
            ->assertSet('confirmationAction', 'revoke_assignment')
            ->assertSet('confirmationTargetId', $latifahAssignment->id);

        $this->assertNull($latifahAssignment->fresh()->revoked_at);
        $component->call('cancelConfirmation')->assertSet('confirmationAction', null);
        $this->assertNull($latifahAssignment->fresh()->revoked_at);

        $component
            ->call('openConfirmation', 'revoke_assignment', $latifahAssignment->id)
            ->set('confirmationReason', $reason)
            ->call('confirmOperation')
            ->assertHasNoErrors()
            ->assertSet('assignmentMessage', 'Penugasan Latifah dicabut dan historinya dipertahankan.');

        $this->assertNotNull($latifahAssignment->fresh()->revoked_at);
        $this->assertSame($reason, $latifahAssignment->fresh()->revocation_reason);
        foreach ([$lead, $ekoAssignment, $reviewer] as $assignment) {
            $this->assertNull($assignment->fresh()->revoked_at);
        }
        $readiness = app(LedAssessmentCycleService::class)->readiness($cycle->fresh());
        $this->assertSame([$ardha->name], $readiness['indicators'][0]['primary_assignees']);
        $this->assertSame([$annas->name], $readiness['indicators'][0]['independent_reviewer_assignees']);
        $this->assertSame($draftSnapshot, collect($draft->fresh()->toArray())->only(['id', 'status', 'rubric_id', 'justification', 'created_by'])->all());

        $event = DB::table('accreditation_led_cycle_events')
            ->where('cycle_id', $cycle->id)
            ->where('action', 'assignment_revoked')
            ->latest('id')
            ->first();
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame($reason, json_decode($event->event_payload_json, true, flags: JSON_THROW_ON_ERROR)['reason']);
        $this->assertStringNotContainsString("openConfirmation('revoke_assignment', {$latifahAssignment->id})", $component->html());

        $component
            ->call('openConfirmation', 'revoke_assignment', $ekoAssignment->id)
            ->set('confirmationReason', '   ')
            ->call('confirmOperation')
            ->assertHasErrors(['confirmationReason'])
            ->assertSee('Kolom justifikasi audit penugasan wajib diisi.');
        $this->assertNull($ekoAssignment->fresh()->revoked_at);

        foreach ([
            [$reviewer, 'Pencabutan Reviewer A2 untuk pengujian UI.'],
            [$lead, 'Pencabutan Lead Assessor A2 untuk pengujian UI.'],
        ] as [$assignment, $revocationReason]) {
            $component
                ->call('openConfirmation', 'revoke_assignment', $assignment->id)
                ->set('confirmationReason', $revocationReason)
                ->call('confirmOperation')
                ->assertHasNoErrors();
            $this->assertNotNull($assignment->fresh()->revoked_at);
        }

        $component
            ->assertSeeHtml('wire:model.live="mappingRevocationReason"')
            ->set('mappingRevocationReason', 'Pencabutan mapping A2 untuk pengujian UI.')
            ->call('openConfirmation', 'revoke_mapping', $mapping->id)
            ->call('confirmOperation')
            ->assertHasNoErrors();
        $this->assertNotNull($mapping->fresh()->revoked_at);
        $this->assertSame(3, DB::table('accreditation_led_cycle_events')->where('cycle_id', $cycle->id)->where('action', 'assignment_revoked')->count());
        $this->assertSame(1, DB::table('accreditation_led_cycle_events')->where('cycle_id', $cycle->id)->where('action', 'mapping_revoked')->count());
    }

    public function test_individual_assignment_modal_adds_reviewers_and_replaces_leads_only_after_confirmation(): void
    {
        $admin = $this->user('Super Admin');
        $ardha = $this->user('PIC Kriteria');
        $eko = $this->user('PIC Kriteria');
        $poppy = $this->user('PIC Kriteria');
        $annas = $this->user('Reviewer');
        foreach (compact('ardha', 'eko', 'poppy', 'annas') as $name => $user) {
            $user->update(['name' => ucfirst($name)]);
        }
        $project = $this->project($admin);
        $criterion = $this->criterion($project, 'A');
        foreach ([$ardha, $eko, $poppy] as $assessor) {
            $this->assignCriterion($criterion, $assessor, 'pic');
        }
        $this->assignCriterion($criterion, $annas, 'reviewer');
        [$version, $indicator] = $this->publishedVersion($project, $admin);
        $secondIndicator = AccreditationLedIndicator::create([
            'instrument_version_id' => $version->id,
            'indicator_number' => 2,
            'criterion_code' => 'A',
            'title' => 'Indikator Kedua',
            'position' => 2,
            'is_assessable' => true,
        ]);
        $this->actingAs($admin);
        $cycle = app(LedAssessmentCycleService::class)->create($project, $version, 'Modal Penugasan Individual', $admin);
        $lead = app(LedAssessorAssignmentService::class)->assign($cycle, $indicator, $ardha, 'assessor_primary', $admin, 'Lead awal untuk pengujian modal.');

        $component = Livewire::withQueryParams(['proyek' => $project->id, 'siklus' => $cycle->id, 'indikator' => $indicator->id])
            ->test(LedSetupIndex::class)
            ->set('assignmentRole', 'assessor_support')
            ->set('assignmentUserId', $eko->id)
            ->call('openAssignmentConfirmation')
            ->assertSet('confirmationAction', 'add_assignment')
            ->assertSee('Eko')
            ->assertSee('Justifikasi Audit Penugasan');
        $html = $component->html();
        $this->assertStringContainsString('class="setup-confirmation-reason-heading"', $html);
        $this->assertStringContainsString('wire:model.live="confirmationReason"', $html);
        $this->assertStringContainsString('<details>', $html);
        $this->assertStringNotContainsString('setup-required-text', $html);
        $this->assertDatabaseCount('accreditation_led_assessor_assignments', 1);
        $component->call('cancelConfirmation')->assertSet('confirmationAction', null);
        $this->assertDatabaseCount('accreditation_led_assessor_assignments', 1);

        $component
            ->call('openAssignmentConfirmation')
            ->set('confirmationReason', 'Menambahkan Eko sebagai Supporting Assessor.')
            ->call('confirmOperation')
            ->assertHasNoErrors()
            ->assertSet('assignmentMessage', 'Eko ditugaskan sebagai Penilai Pendukung.');
        $ekoAssignment = $cycle->assignments()->where('user_id', $eko->id)->where('assignment_role', 'assessor_support')->sole();
        $this->assertNull($ekoAssignment->revoked_at);
        $this->assertDatabaseHas('accreditation_led_cycle_events', ['cycle_id' => $cycle->id, 'actor_id' => $admin->id, 'action' => 'assignment_created', 'reason' => 'Menambahkan Eko sebagai Supporting Assessor.']);

        $component
            ->set('assignmentRole', 'reviewer')
            ->set('assignmentUserId', $annas->id)
            ->call('openAssignmentConfirmation')
            ->set('confirmationReason', 'Menambahkan Annas sebagai Reviewer independen.')
            ->call('confirmOperation')
            ->assertHasNoErrors();
        $this->assertDatabaseHas('accreditation_led_assessor_assignments', ['cycle_id' => $cycle->id, 'user_id' => $annas->id, 'assignment_role' => 'reviewer', 'revoked_at' => null]);

        $component
            ->set('assignmentRole', 'assessor_primary')
            ->set('assignmentUserId', $poppy->id)
            ->call('openAssignmentConfirmation')
            ->assertSet('confirmationAction', 'replace_assignment')
            ->assertSee('Ardha')
            ->assertSee('Poppy')
            ->call('cancelConfirmation');
        $this->assertNull($lead->fresh()->revoked_at);

        $component
            ->call('openAssignmentConfirmation')
            ->set('confirmationReason', 'Mengganti Ardha dengan Poppy sebagai Lead Assessor.')
            ->call('confirmOperation')
            ->assertHasNoErrors()
            ->assertSet('assignmentMessage', 'Penilai Utama diganti dengan Poppy; histori sebelumnya dipertahankan.');
        $this->assertNotNull($lead->fresh()->revoked_at);
        $this->assertDatabaseHas('accreditation_led_assessor_assignments', ['cycle_id' => $cycle->id, 'user_id' => $poppy->id, 'assignment_role' => 'assessor_primary', 'revoked_at' => null]);
        $this->assertDatabaseHas('accreditation_led_cycle_events', ['cycle_id' => $cycle->id, 'actor_id' => $admin->id, 'action' => 'assignment_replaced']);

        $component
            ->set('assignmentRole', 'reviewer')
            ->set('assignmentUserId', $poppy->id)
            ->call('openAssignmentConfirmation')
            ->set('confirmationReason', 'Reviewer tidak boleh menjadi Lead aktif.')
            ->call('confirmOperation')
            ->assertHasErrors(['assignment'])
            ->assertSet('confirmationAction', 'add_assignment');

        $component->call('selectIndicator', $secondIndicator->id)->assertSet('confirmationAction', null);
        $this->assertDatabaseMissing('accreditation_led_assessor_assignments', ['cycle_id' => $cycle->id, 'indicator_id' => $secondIndicator->id]);
    }

    public function test_bulk_mapping_previews_all_relations_and_preserves_duplicate_and_revocation_rules(): void
    {
        $manager = $this->user('Project Manager');
        $project = $this->project($manager);
        $criterion = $this->criterion($project, 'A');
        $items = collect([
            $this->itemWithRequirement($criterion, 1),
            $this->itemWithRequirement($criterion, 2),
        ]);
        [$version, $firstIndicator] = $this->publishedVersion($project, $manager);
        $secondIndicator = AccreditationLedIndicator::create([
            'instrument_version_id' => $version->id,
            'indicator_number' => 2,
            'criterion_code' => 'A',
            'title' => 'Indikator Mapping Kedua',
            'position' => 2,
            'is_assessable' => true,
        ]);
        $this->actingAs($manager);
        $cycle = app(LedAssessmentCycleService::class)->create($project, $version, 'Bulk Mapping', $manager);
        $bulk = app(LedOperationalSetupBulkService::class);
        $indicatorIds = [$firstIndicator->id, $secondIndicator->id];
        $itemIds = $items->pluck('id')->all();

        $preview = $bulk->previewMappings($cycle, $indicatorIds, $itemIds, 'primary', $manager);
        $this->assertSame(4, $preview['create_count']);
        $this->assertDatabaseCount('accreditation_led_indicator_item_mappings', 0);
        $result = $bulk->applyMappings($cycle, $indicatorIds, $itemIds, 'primary', $preview['fingerprint'], $manager);
        $this->assertSame(['created_count' => 4, 'skipped_count' => 0], $result);
        $this->assertDatabaseCount('accreditation_led_indicator_item_mappings', 4);
        $this->assertDatabaseHas('accreditation_led_cycle_events', ['cycle_id' => $cycle->id, 'action' => 'bulk_mappings_applied']);

        $duplicate = $bulk->previewMappings($cycle, $indicatorIds, $itemIds, 'primary', $manager);
        $this->assertSame(4, $duplicate['skipped_count']);
        $conflict = $bulk->previewMappings($cycle, $indicatorIds, $itemIds, 'supporting', $manager);
        $this->assertSame(4, $conflict['conflict_count']);
        try {
            $bulk->applyMappings($cycle, $indicatorIds, $itemIds, 'supporting', $conflict['fingerprint'], $manager);
            $this->fail('Changing an active mapping role requires explicit revocation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mapping', $exception->errors());
        }
        $this->assertDatabaseMissing('accreditation_led_indicator_item_mappings', ['mapping_role' => 'supporting']);
    }

    public function test_readiness_keeps_the_24_indicator_count_when_names_are_added(): void
    {
        $manager = $this->user('Project Manager');
        $project = $this->project($manager);
        [$version] = $this->publishedVersion($project, $manager);
        for ($number = 2; $number <= 24; $number++) {
            AccreditationLedIndicator::create([
                'instrument_version_id' => $version->id,
                'indicator_number' => $number,
                'criterion_code' => 'A',
                'title' => "Indikator Kesiapan {$number}",
                'position' => $number,
                'is_assessable' => true,
            ]);
        }

        $this->actingAs($manager);
        $cycle = app(LedAssessmentCycleService::class)->create($project, $version, 'Readiness Dua Puluh Empat', $manager);
        $readiness = app(LedAssessmentCycleService::class)->readiness($cycle);

        $this->assertSame(24, $readiness['total']);
        $this->assertSame(24, $readiness['missing_primary']);
        $this->assertSame(24, $readiness['missing_reviewer']);
        $this->assertSame(24, $readiness['missing_mapping']);
        $this->assertCount(24, $readiness['indicators']);
        $this->assertSame([], $readiness['indicators'][23]['primary_assignees']);
    }

    public function test_bulk_mapping_confirmation_rejects_a_stale_preview_without_a_second_write(): void
    {
        $manager = $this->user('Project Manager');
        $project = $this->project($manager);
        $criterion = $this->criterion($project, 'A');
        $item = $this->itemWithRequirement($criterion, 1);
        [$version, $indicator] = $this->publishedVersion($project, $manager);
        $this->actingAs($manager);
        $cycle = app(LedAssessmentCycleService::class)->create($project, $version, 'Stale Modal Preview', $manager);

        $component = Livewire::withQueryParams(['proyek' => $project->id, 'siklus' => $cycle->id])
            ->test(LedSetupIndex::class)
            ->set('bulkMappingIndicatorIds', [$indicator->id])
            ->set('bulkMappingItemIds', [$item->id])
            ->call('previewBulkMappings')
            ->call('openConfirmation', 'bulk_mapping')
            ->assertSet('confirmationAction', 'bulk_mapping');

        app(LedIndicatorItemMappingService::class)->map($cycle, $indicator, $item, 'primary', $manager);

        $component
            ->call('confirmOperation')
            ->assertHasErrors(['mapping'])
            ->assertSet('confirmationAction', null);

        $this->assertDatabaseCount('accreditation_led_indicator_item_mappings', 1);
    }

    private function project(?User $manager = null): AccreditationProject
    {
        $program = StudyProgram::create(['code' => Str::random(8), 'name' => 'Program '.Str::random(4), 'is_active' => true]);
        $instrument = AccreditationInstrument::create(['code' => Str::random(8), 'name' => 'Instrumen '.Str::random(4)]);
        $project = AccreditationProject::create([
            'code' => Str::random(10),
            'name' => 'Project '.Str::random(5),
            'study_program_id' => $program->id,
            'instrument_id' => $instrument->id,
            'final_deadline' => '2026-12-31',
            'status' => 'active',
        ]);

        if ($manager) {
            AccreditationProjectMember::create([
                'project_id' => $project->id,
                'user_id' => $manager->id,
                'membership_role' => 'project_manager',
                'is_active' => true,
            ]);
        }

        return $project->load(['studyProgram', 'instrument']);
    }

    private function publishedVersion(AccreditationProject $project, User $actor): array
    {
        $version = AccreditationLedInstrumentVersion::create([
            'instrument_id' => $project->instrument_id,
            'version_code' => 'PUB-'.Str::random(5),
            'assessment_pathway' => 'terakreditasi',
            'education_level' => 'S1',
            'source_title' => 'Sumber Published',
            'source_reference' => 'REF-PUBLISHED',
            'source_filename' => 'published.pdf',
            'source_disk' => 'local',
            'source_storage_path' => 'published.pdf',
            'source_pdf_sha256' => str_repeat('a', 64),
            'rubric_manifest_sha256' => str_repeat('b', 64),
            'status' => 'published',
            'published_by' => $actor->id,
            'published_at' => now(),
            'created_by' => $actor->id,
        ]);
        $indicator = AccreditationLedIndicator::create([
            'instrument_version_id' => $version->id,
            'indicator_number' => 1,
            'criterion_code' => 'A',
            'title' => 'Indikator Operasional',
            'position' => 1,
            'is_assessable' => true,
        ]);
        AccreditationLedRubric::create([
            'indicator_id' => $indicator->id,
            'category_code' => 'baik',
            'category_label' => 'Baik',
            'internal_score' => 3,
            'description' => 'Rubrik resmi terkonfigurasi.',
            'position' => 1,
        ]);

        return [$version, $indicator];
    }

    private function documentItem(AccreditationProject $project, string $criterionCode = 'A'): AccreditationItem
    {
        $position = AccreditationCriterion::query()->where('project_id', $project->id)->count() + 1;
        $criterion = AccreditationCriterion::create([
            'project_id' => $project->id,
            'code' => $criterionCode,
            'name' => 'Kriteria '.$criterionCode,
            'color_header' => '#173E70',
            'color_body' => '#E7EEF8',
            'position' => $position,
        ]);
        $item = AccreditationItem::create([
            'criterion_id' => $criterion->id,
            'number' => 1,
            'position' => 1,
            'title' => 'Item bukti operasional',
        ]);
        AccreditationDocumentRequirement::create([
            'item_id' => $item->id,
            'number' => 1,
            'title' => 'Dokumen wajib',
            'is_required' => true,
            'workflow_status' => 'not_started',
            'current_review_revision' => 0,
        ]);

        return $item->load('criterion');
    }

    private function criterion(AccreditationProject $project, string $code): AccreditationCriterion
    {
        return AccreditationCriterion::create([
            'project_id' => $project->id,
            'code' => $code,
            'name' => 'Kriteria '.$code,
            'color_header' => '#173E70',
            'color_body' => '#E7EEF8',
            'position' => AccreditationCriterion::where('project_id', $project->id)->count() + 1,
        ]);
    }

    private function itemWithRequirement(AccreditationCriterion $criterion, int $number): AccreditationItem
    {
        $item = AccreditationItem::create([
            'criterion_id' => $criterion->id,
            'number' => $number,
            'position' => $number,
            'title' => "Item bukti {$number}",
        ]);
        AccreditationDocumentRequirement::create([
            'item_id' => $item->id,
            'number' => 1,
            'title' => 'Dokumen wajib',
            'is_required' => true,
            'workflow_status' => 'not_started',
            'current_review_revision' => 0,
        ]);

        return $item->load('criterion');
    }

    private function assignCriterion(AccreditationCriterion $criterion, User $user, string $role): void
    {
        AccreditationCriterionAssignment::create([
            'criterion_id' => $criterion->id,
            'user_id' => $user->id,
            'assignment_role' => $role,
            'is_active' => true,
        ]);
    }

    private function createOrganizationAssignment(AccreditationProject $project, User $actor): void
    {
        $unitId = DB::table('qa_organizational_units')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'unit_code' => 'UPPS-'.Str::random(5),
            'unit_name' => 'Unit Pengelola Program Studi',
            'unit_type' => 'upps',
            'is_active' => true,
            'valid_from' => '2026-01-01',
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('qa_study_program_unit_assignments')->insert([
            'study_program_id' => $project->study_program_id,
            'unit_id' => $unitId,
            'relationship_type' => 'managing_unit',
            'valid_from' => '2026-01-01',
            'decision_ref' => 'SK-UNIT-2026',
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
    }

    private function manifestFile(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('instrument.json', json_encode([
            'version_code' => '2026.LED.1',
            'assessment_pathway' => 'terakreditasi',
            'education_level' => 'S1',
            'source_title' => 'Pedoman LED Terverifikasi',
            'source_reference' => 'SK-LAMSAMA-2026',
            'source_effective_date' => '2026-01-01',
            'indicators' => [
                [
                    'indicator_number' => 1,
                    'criterion_code' => 'A',
                    'title' => 'Indikator Assessable',
                    'description' => 'Deskripsi resmi.',
                    'position' => 1,
                    'page_start' => 10,
                    'page_end' => 11,
                    'is_assessable' => true,
                    'rubrics' => [
                        ['category_code' => 'unggul', 'category_label' => 'Unggul', 'internal_score' => 4, 'description' => 'Kondisi unggul.', 'position' => 1, 'page_start' => 12, 'page_end' => 12],
                        ['category_code' => 'baik', 'category_label' => 'Baik', 'internal_score' => 3, 'description' => 'Kondisi baik.', 'position' => 2, 'page_start' => 12, 'page_end' => 13],
                    ],
                ],
                [
                    'indicator_number' => 2,
                    'criterion_code' => 'A',
                    'title' => 'Header Sumber',
                    'description' => null,
                    'position' => 2,
                    'page_start' => null,
                    'page_end' => null,
                    'is_assessable' => false,
                    'rubrics' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function sourcePdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('source.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\nxref\n0 1\n0000000000 65535 f \ntrailer\n<<>>\nstartxref\n42\n%%EOF");
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => $role.' '.Str::random(4),
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('ValidPassword!123'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
