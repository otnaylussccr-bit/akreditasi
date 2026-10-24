<?php

namespace Tests\Feature;

use App\Livewire\LedAssessment\RelationshipExplorer;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationCriterionAssignment;
use App\Models\AccreditationDocument;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationInstrument;
use App\Models\AccreditationItem;
use App\Models\AccreditationLedAssessment;
use App\Models\AccreditationLedAssessmentCycle;
use App\Models\AccreditationLedAssessmentRevision;
use App\Models\AccreditationLedAssessorAssignment;
use App\Models\AccreditationLedIndicator;
use App\Models\AccreditationLedIndicatorItemMapping;
use App\Models\AccreditationLedInstrumentVersion;
use App\Models\AccreditationProject;
use App\Models\AccreditationProjectMember;
use App\Models\AccreditationReviewSubmission;
use App\Models\StudyProgram;
use App\Models\User;
use Database\Seeders\AccreditationRolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class LedRelationshipExplorerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
    }

    public function test_explorer_derives_active_relationships_assignments_evidence_and_approved_state_without_mutation(): void
    {
        [$admin, $project, $cycle, $assessments, $items] = $this->fixture();
        $this->actingAs($admin);

        $component = Livewire::withQueryParams(['proyek' => $project->id, 'siklus' => $cycle->id])
            ->test(RelationshipExplorer::class)
            ->assertSee('Assessable indicators')
            ->assertSee('3')
            ->assertSee('2 active relationships')
            ->assertSee('Indicator one')
            ->assertSee('Item A')
            ->assertSee('Lead')
            ->assertSee('Approved')
            ->assertDontSee('Revoked item')
            ->assertDontSee('LPMI')
            ->call('selectIndicator', $assessments[0]->id)
            ->assertSee('Document submitted, not verified')
            ->assertSee('administrative stored mappings')
            ->call('openMappedItem', $items[0]->id)
            ->assertSet('viewMode', 'item')
            ->assertSee('1 active relationship')
            ->assertSee('Item A')
            ->assertSee('No document file recorded')
            ->call('openMappedIndicator', $assessments[0]->indicator_id)
            ->assertSet('viewMode', 'indicator')
            ->assertSee('Indicator one')
            ->set('search', 'Unmapped')
            ->assertSee('Unmapped indicator')
            ->assertDontSee('Indicator two');

        $this->assertSame(3, AccreditationLedIndicatorItemMapping::count());
        $this->assertSame(2, AccreditationLedIndicatorItemMapping::whereNull('revoked_at')->count());
        $this->assertSame('approved', $assessments[0]->fresh()->currentApprovedRevision->status);
        $this->assertSame('draft', $assessments[1]->fresh()->currentWorkingRevision->status);
    }

    public function test_explorer_denies_cross_project_direct_urls_and_keeps_document_details_restricted_without_document_permission(): void
    {
        [$admin, $project, $cycle] = $this->fixture();
        $pic = $this->user('PIC Kriteria');
        $other = $this->project('Other');
        $criterion = AccreditationCriterion::create(['project_id' => $other->id, 'code' => 'B', 'name' => 'Criterion B', 'color_header' => '#102A43', 'color_body' => '#EEF2F7', 'position' => 1]);
        AccreditationCriterionAssignment::create(['criterion_id' => $criterion->id, 'user_id' => $pic->id, 'assignment_role' => 'pic', 'is_active' => true]);

        $this->actingAs($pic)->get(route('led-assessment.relationship-explorer', ['proyek' => $project->id, 'siklus' => $cycle->id]))->assertForbidden();
        $this->actingAs($admin)->get(route('led-assessment.relationship-explorer', ['proyek' => $project->id, 'siklus' => $cycle->id]))->assertOk();
    }

    public function test_explorer_has_indonesian_labels(): void
    {
        [$admin, $project, $cycle] = $this->fixture();
        app()->setLocale('id');
        $this->actingAs($admin)->get(route('led-assessment.relationship-explorer', ['proyek' => $project->id, 'siklus' => $cycle->id]))
            ->assertOk()->assertSee('Penjelajah Indikator-Item LED')->assertSee('Tampilan indikator');
    }

    private function fixture(): array
    {
        $admin = $this->user('Super Admin');
        $project = $this->project('Explorer');
        $criterion = AccreditationCriterion::create(['project_id' => $project->id, 'code' => 'A', 'name' => 'Criterion A', 'color_header' => '#102A43', 'color_body' => '#EEF2F7', 'position' => 1]);
        $items = collect(['Item A', 'Item B', 'Revoked item'])->map(function (string $title, int $index) use ($criterion) {
            $item = AccreditationItem::create(['criterion_id' => $criterion->id, 'number' => $index + 1, 'position' => $index + 1, 'title' => $title]);
            AccreditationDocumentRequirement::create(['item_id' => $item->id, 'number' => 1, 'title' => 'Requirement '.$index, 'is_required' => true, 'workflow_status' => $index === 1 ? 'pending_review' : 'not_started', 'current_review_revision' => 0]);
            return $item;
        });
        $version = AccreditationLedInstrumentVersion::create(['instrument_id' => $project->instrument_id, 'version_code' => 'EXP-'.Str::random(5), 'assessment_pathway' => 'terakreditasi', 'education_level' => 'S1', 'source_title' => 'Published source', 'source_reference' => 'EXP', 'source_filename' => 'source.pdf', 'source_disk' => 'local', 'source_storage_path' => 'source.pdf', 'source_pdf_sha256' => str_repeat('a', 64), 'status' => 'published', 'published_by' => $admin->id, 'published_at' => now(), 'created_by' => $admin->id]);
        $indicators = collect(['Indicator one', 'Indicator two', 'Unmapped indicator'])->map(function (string $title, int $index) use ($version) {
            return AccreditationLedIndicator::create(['instrument_version_id' => $version->id, 'indicator_number' => $index + 1, 'criterion_code' => 'A', 'title' => $title, 'position' => $index + 1, 'is_assessable' => true]);
        });
        $cycle = AccreditationLedAssessmentCycle::create(['project_id' => $project->id, 'instrument_id' => $project->instrument_id, 'instrument_version_id' => $version->id, 'cycle_number' => 1, 'cycle_name' => 'Explorer cycle', 'status' => 'active', 'created_by' => $admin->id]);
        $assessments = $indicators->map(fn ($indicator) => AccreditationLedAssessment::create(['cycle_id' => $cycle->id, 'project_id' => $project->id, 'instrument_version_id' => $version->id, 'indicator_id' => $indicator->id]));
        $approved = AccreditationLedAssessmentRevision::create(['assessment_id' => $assessments[0]->id, 'project_id' => $project->id, 'indicator_id' => $indicators[0]->id, 'revision_number' => 1, 'status' => 'approved', 'created_by' => $admin->id]);
        $draft = AccreditationLedAssessmentRevision::create(['assessment_id' => $assessments[1]->id, 'project_id' => $project->id, 'indicator_id' => $indicators[1]->id, 'revision_number' => 1, 'status' => 'draft', 'created_by' => $admin->id]);
        $assessments[0]->forceFill(['current_working_revision_id' => $approved->id, 'current_approved_revision_id' => $approved->id])->save();
        $assessments[1]->forceFill(['current_working_revision_id' => $draft->id])->save();
        foreach ([[0, 0, 'primary'], [0, 1, 'supporting'], [1, 2, 'supporting']] as [$indicator, $item, $role]) {
            AccreditationLedIndicatorItemMapping::create(['cycle_id' => $cycle->id, 'project_id' => $project->id, 'instrument_version_id' => $version->id, 'indicator_id' => $indicators[$indicator]->id, 'criterion_id' => $criterion->id, 'item_id' => $items[$item]->id, 'mapping_role' => $role, 'created_by' => $admin->id, 'created_at' => now(), 'revoked_at' => $item === 2 ? now() : null, 'revoked_by' => $item === 2 ? $admin->id : null]);
        }
        $lead = $this->user('PIC Kriteria');
        AccreditationLedAssessorAssignment::create(['cycle_id' => $cycle->id, 'instrument_version_id' => $version->id, 'indicator_id' => $indicators[0]->id, 'user_id' => $lead->id, 'assignment_role' => 'assessor_primary', 'assigned_by' => $admin->id, 'assigned_at' => now()]);
        $requirement = $items[1]->requirements()->first();
        $document = AccreditationDocument::create(['requirement_id' => $requirement->id, 'uploaded_by' => $admin->id, 'original_name' => 'pending.pdf', 'disk' => 'local', 'storage_path' => 'pending.pdf', 'storage_uuid' => Str::uuid(), 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 10, 'sha256' => str_repeat('b', 64), 'revision_number' => 1, 'is_active' => true, 'uploaded_at' => now()]);
        AccreditationReviewSubmission::create(['requirement_id' => $requirement->id, 'submitted_by' => $admin->id, 'revision_number' => 1, 'status' => 'pending_review', 'submitted_at' => now()]);
        return [$admin, $project, $cycle, $assessments, $items];
    }

    private function project(string $suffix): AccreditationProject
    {
        $program = StudyProgram::create(['code' => Str::random(8), 'name' => 'Program '.$suffix, 'is_active' => true]);
        $instrument = AccreditationInstrument::create(['code' => Str::random(8), 'name' => 'Instrument '.$suffix]);
        return AccreditationProject::create(['code' => Str::random(10), 'name' => 'Project '.$suffix, 'study_program_id' => $program->id, 'instrument_id' => $instrument->id, 'final_deadline' => '2026-12-31', 'status' => 'active']);
    }

    private function user(string $role): User
    {
        $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.test', 'password' => Hash::make('ValidPassword!123'), 'is_active' => true]);
        $user->assignRole($role);
        return $user;
    }
}
