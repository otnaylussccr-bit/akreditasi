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

    public function test_cycle_activation_requires_independent_assignments_mapping_and_stores_canonical_organization_snapshot(): void
    {
        $manager = $this->user('Project Manager');
        $assessor = $this->user('PIC Kriteria');
        $reviewer = $this->user('Reviewer');
        $project = $this->project($manager);
        [$version, $indicator] = $this->publishedVersion($project, $manager);
        $this->actingAs($manager);
        $cycleService = app(LedAssessmentCycleService::class);
        $cycle = $cycleService->create($project, $version, 'Cycle End-to-End', $manager);
        $assignments = app(LedAssessorAssignmentService::class);
        $assignments->assign($cycle, $indicator, $assessor, 'assessor_primary', $manager, 'Menetapkan Penilai Utama untuk aktivasi.');

        $assessor->assignRole('Reviewer');
        try {
            $assignments->assign($cycle, $indicator, $assessor->fresh('roles'), 'reviewer', $manager, 'Negative test independensi reviewer.');
            $this->fail('The same user cannot be assessor and reviewer for one indicator.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }

        $assignments->assign($cycle, $indicator, $reviewer, 'reviewer', $manager, 'Menetapkan Reviewer untuk aktivasi.');

        try {
            $cycleService->activate($cycle, $manager);
            $this->fail('Activation without mapping must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cycle', $exception->errors());
        }

        $item = $this->documentItem($project);
        $mappingService = app(LedIndicatorItemMappingService::class);
        $mapping = $mappingService->map($cycle, $indicator, $item, 'primary', $manager);
        $revoked = $mappingService->revoke($mapping, $manager, 'Mapping diganti setelah verifikasi scope.');
        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame('Mapping diganti setelah verifikasi scope.', $revoked->revoke_reason);
        $mappingService->map($cycle, $indicator, $item, 'supporting', $manager);

        $this->createOrganizationAssignment($project, $manager);
        $readiness = $cycleService->readiness($cycle);
        $this->assertTrue($readiness['ready']);
        $this->assertSame(1, $readiness['configured']);
        $activated = $cycleService->activate($cycle, $manager);
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
        $item = $this->documentItem($project);
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
            ->set('indicatorId', $indicator->id)
            ->set('assignmentRole', 'assessor_primary')
            ->set('assignmentUserId', $assessor->id)
            ->set('assignmentRevocationReason', 'Menetapkan Penilai Utama dari UI.')
            ->call('assignUser')
            ->set('assignmentRole', 'reviewer')
            ->set('assignmentUserId', $reviewer->id)
            ->set('assignmentRevocationReason', 'Menetapkan Reviewer dari UI.')
            ->call('assignUser')
            ->set('mappingRole', 'primary')
            ->set('mappingItemId', $item->id)
            ->call('createMapping')
            ->assertSee('1 / 1 indikator')
            ->call('activateCycle')
            ->assertSee('Siklus Cycle dari UI aktif')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('accreditation_led_instrument_versions', ['id' => $version->id, 'status' => 'published']);
        $this->assertDatabaseHas('accreditation_led_assessment_cycles', ['project_id' => $project->id, 'cycle_name' => 'Cycle dari UI', 'status' => 'active']);
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
