<?php

namespace Tests\Feature;

use App\Models\AccreditationCriterion;
use App\Models\AccreditationCriterionAssignment;
use App\Models\AccreditationInstrument;
use App\Models\AccreditationLedAssessment;
use App\Models\AccreditationLedAssessmentCycle;
use App\Models\AccreditationLedIndicator;
use App\Models\AccreditationLedInstrumentVersion;
use App\Models\AccreditationProject;
use App\Models\AccreditationProjectMember;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\AccreditationProjectContextService;
use App\Services\LedAssessmentAuthorizationService;
use App\Services\LedAssessorAssignmentService;
use Database\Seeders\AccreditationRolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SidebarNavigationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
    }

    public function test_unscoped_viewer_sees_only_accessible_navigation_in_both_locales(): void
    {
        $viewer = $this->user('Viewer');
        $this->actingAs($viewer);

        foreach ([
            'en' => ['Assessment Dashboard', 'Document Input', 'Evidence Repository', 'Document Verification', 'LED Follow-Up', 'LED Assessment', 'LED Configuration', 'Team Activity', 'Administration'],
            'id' => ['Dashboard Penilaian', 'Input Dokumen', 'Repository Bukti', 'Verifikasi Dokumen', 'Follow Up LED', 'Penilaian LED', 'Konfigurasi LED', 'Aktivitas Tim', 'Administrasi'],
        ] as $locale => $hiddenLabels) {
            $response = $this->withSession(['locale' => $locale])
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee('href="'.route('dashboard').'"', false)
                ->assertDontSee('data-viewer-disabled="true"', false);

            foreach ($hiddenLabels as $label) {
                $response->assertDontSee($label);
            }
        }

        foreach ([
            route('led-assessment.dashboard'),
            route('led-assessment.index'),
            route('documents.index'),
            route('repository.index'),
            route('verifications.index'),
            route('follow-up-led.index'),
            route('team-activity.index'),
            route('administration.led-setup.index'),
            route('administration.users.index'),
            route('administration.roles.index'),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_viewer_navigation_follows_led_assignment_scope_and_disappears_after_revocation(): void
    {
        $viewer = $this->user('Viewer');
        $unrelatedProject = $this->project();
        AccreditationProjectMember::create([
            'project_id' => $unrelatedProject->id,
            'user_id' => $viewer->id,
            'membership_role' => 'viewer',
            'is_active' => true,
        ]);
        $domain = $this->ledDomain($viewer, 'assessor_primary');
        $this->actingAs($viewer);

        $response = $this->withSession([
            AccreditationProjectContextService::SESSION_KEY => $unrelatedProject->id,
            'locale' => 'en',
        ])->get(route('dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('dashboard', ['proyek' => $unrelatedProject->id]).'"', false)
            ->assertSee('href="'.e(route('led-assessment.dashboard', [
                'proyek' => $domain['project']->id,
                'siklus' => $domain['cycle']->id,
            ])).'"', false)
            ->assertSee('href="'.e(route('led-assessment.index', [
                'proyek' => $domain['project']->id,
                'siklus' => $domain['cycle']->id,
            ])).'"', false)
            ->assertDontSee('Document Input')
            ->assertDontSee('Document Verification');

        $this->assertStringNotContainsString(
            route('led-assessment.index', ['proyek' => $unrelatedProject->id]),
            $response->getContent(),
        );
        $this->get(route('led-assessment.dashboard', [
            'proyek' => $domain['project']->id,
            'siklus' => $domain['cycle']->id,
        ]))->assertOk();
        $this->get(route('led-assessment.index', [
            'proyek' => $domain['project']->id,
            'siklus' => $domain['cycle']->id,
        ]))->assertOk();
        $this->get(route('led-assessment.dashboard', ['proyek' => $unrelatedProject->id]))->assertForbidden();

        $this->actingAs($domain['manager']);
        app(LedAssessorAssignmentService::class)->revoke(
            $domain['assignment'],
            $domain['manager'],
            'Mencabut akses untuk regression navigation.',
        );
        $this->actingAs($viewer);

        $this->withSession([
            AccreditationProjectContextService::SESSION_KEY => $unrelatedProject->id,
            'locale' => 'en',
        ])->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Assessment Dashboard')
            ->assertDontSee('LED Assessment');
        $this->get(route('led-assessment.dashboard', ['proyek' => $domain['project']->id]))->assertForbidden();
        $this->get(route('led-assessment.index', ['proyek' => $domain['project']->id]))->assertForbidden();
    }

    public function test_primary_supporting_and_reviewer_assignments_expose_only_led_navigation(): void
    {
        $primary = $this->user('Viewer');
        $support = $this->user('Viewer');
        $reviewer = $this->user('Viewer');
        $domain = $this->ledDomain($primary, 'assessor_primary');
        $this->actingAs($domain['manager']);
        app(LedAssessorAssignmentService::class)->assign(
            $domain['cycle'],
            $domain['indicator'],
            $support,
            'assessor_support',
            $domain['manager'],
            'Menetapkan Penilai Pendukung untuk navigation test.',
        );
        app(LedAssessorAssignmentService::class)->assign(
            $domain['cycle'],
            $domain['indicator'],
            $reviewer,
            'reviewer',
            $domain['manager'],
            'Menetapkan Reviewer untuk navigation test.',
        );

        foreach ([$primary, $support, $reviewer] as $assignedViewer) {
            $this->actingAs($assignedViewer);
            $this->withSession(['locale' => 'en'])
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee('Assessment Dashboard')
                ->assertSee('LED Assessment')
                ->assertDontSee('Document Input')
                ->assertDontSee('Document Verification');
            $this->get(route('led-assessment.dashboard', ['proyek' => $domain['project']->id]))->assertOk();
            $this->get(route('led-assessment.index', ['proyek' => $domain['project']->id]))->assertOk();
        }
    }

    public function test_dashboard_project_scope_requires_led_entitlement_instead_of_phase_one_access(): void
    {
        $lead = $this->user('Viewer');
        $domain = $this->ledDomain($lead, 'assessor_primary', 'draft');
        $admin = $this->user('Super Admin');

        foreach ([$admin, $domain['manager']] as $oversightUser) {
            $this->actingAs($oversightUser);
            $this->withSession(['locale' => 'en'])
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee('href="'.route('led-assessment.dashboard', [
                    'proyek' => $domain['project']->id,
                ]).'"', false);
            $this->get(route('led-assessment.dashboard', [
                'proyek' => $domain['project']->id,
            ]))->assertOk();
        }

        $criterion = $this->criterion($domain['project']);
        $pic = $this->user('PIC Kriteria');
        $documentReviewer = $this->user('Reviewer');
        $monitoringViewer = $this->user('Viewer');

        foreach ([[$pic, 'pic'], [$documentReviewer, 'reviewer']] as [$user, $role]) {
            AccreditationCriterionAssignment::create([
                'criterion_id' => $criterion->id,
                'user_id' => $user->id,
                'assignment_role' => $role,
                'is_active' => true,
            ]);
        }

        AccreditationProjectMember::create([
            'project_id' => $domain['project']->id,
            'user_id' => $monitoringViewer->id,
            'membership_role' => 'viewer',
            'is_active' => true,
        ]);

        foreach ([$pic, $documentReviewer, $monitoringViewer] as $phaseOneUser) {
            $this->actingAs($phaseOneUser);
            $this->withSession(['locale' => 'en'])
                ->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee('Assessment Dashboard');
            $this->get(route('led-assessment.dashboard', [
                'proyek' => $domain['project']->id,
            ]))->assertForbidden();
        }
    }

    public function test_assigned_dashboard_scope_preserves_draft_and_reportable_cycle_boundaries(): void
    {
        foreach (['draft', 'active', 'closed', 'finalized'] as $status) {
            $lead = $this->user('Viewer');
            $domain = $this->ledDomain($lead, 'assessor_primary', $status);
            $this->actingAs($lead);

            $dashboardParams = ['proyek' => $domain['project']->id];

            if ($status !== 'draft') {
                $dashboardParams['siklus'] = $domain['cycle']->id;
            }

            $this->withSession(['locale' => 'en'])
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee('href="'.e(route('led-assessment.dashboard', $dashboardParams)).'"', false);
            $dashboard = $this->get(route('led-assessment.dashboard', [
                'proyek' => $domain['project']->id,
            ]))->assertOk();

            if ($status === 'draft') {
                $dashboard
                    ->assertSee('The cycle is still Draft')
                    ->assertDontSee('data-approved-score="true"', false);
            }

            $explicitCycle = $this->get(route('led-assessment.dashboard', [
                'proyek' => $domain['project']->id,
                'siklus' => $domain['cycle']->id,
            ]));

            if ($status === 'draft') {
                $explicitCycle->assertForbidden();
            } else {
                $explicitCycle->assertOk();
            }
        }
    }

    public function test_inactive_assignee_has_no_dashboard_scope(): void
    {
        $lead = $this->user('Viewer');
        $domain = $this->ledDomain($lead, 'assessor_primary');
        $lead->update(['is_active' => false]);

        $this->assertFalse(app(LedAssessmentAuthorizationService::class)
            ->dashboardProjects($lead->fresh())
            ->whereKey($domain['project']->id)
            ->exists());

        $this->actingAs($lead->fresh())
            ->get(route('led-assessment.dashboard', ['proyek' => $domain['project']->id]))
            ->assertRedirect(route('login'));
    }

    public function test_every_protected_navigation_link_rendered_for_super_admin_has_an_accessible_destination(): void
    {
        $admin = $this->user('Super Admin');
        $project = $this->project();
        $this->criterion($project);
        $this->actingAs($admin);

        $urls = [
            route('led-assessment.dashboard', ['proyek' => $project->id]),
            route('documents.index', ['proyek' => $project->id]),
            route('repository.index', ['proyek' => $project->id]),
            route('verifications.index', ['proyek' => $project->id]),
            route('follow-up-led.index', ['proyek' => $project->id]),
            route('led-assessment.index', ['proyek' => $project->id]),
            route('administration.led-setup.index', ['proyek' => $project->id]),
            route('team-activity.index', ['proyek' => $project->id]),
            route('administration.users.index'),
            route('administration.roles.index'),
        ];
        $response = $this->withSession(['locale' => 'en'])->get(route('dashboard'))->assertOk();

        foreach ($urls as $url) {
            $response->assertSee('href="'.$url.'"', false);
            $this->get($url)->assertOk();
        }
    }

    private function ledDomain(User $assignee, string $role, string $status = 'active'): array
    {
        $manager = $this->user('Project Manager');
        $project = $this->project($manager);
        $version = AccreditationLedInstrumentVersion::create([
            'instrument_id' => $project->instrument_id,
            'version_code' => 'NAV-'.Str::random(6),
            'assessment_pathway' => 'terakreditasi',
            'education_level' => 'S1',
            'source_title' => 'Navigation source',
            'source_reference' => 'NAV-REF',
            'source_filename' => 'navigation.pdf',
            'source_disk' => 'local',
            'source_storage_path' => 'navigation.pdf',
            'source_pdf_sha256' => str_repeat('a', 64),
            'rubric_manifest_sha256' => str_repeat('b', 64),
            'status' => 'published',
            'published_by' => $manager->id,
            'published_at' => now(),
            'created_by' => $manager->id,
        ]);
        $indicator = AccreditationLedIndicator::create([
            'instrument_version_id' => $version->id,
            'indicator_number' => 1,
            'criterion_code' => 'A',
            'title' => 'Navigation Indicator',
            'position' => 1,
            'is_assessable' => true,
        ]);
        $cycle = AccreditationLedAssessmentCycle::create([
            'project_id' => $project->id,
            'instrument_id' => $project->instrument_id,
            'instrument_version_id' => $version->id,
            'cycle_number' => 1,
            'cycle_name' => 'Navigation Cycle',
            'status' => $status === 'draft' ? 'draft' : 'active',
            'created_by' => $manager->id,
        ]);
        $assessment = AccreditationLedAssessment::create([
            'cycle_id' => $cycle->id,
            'project_id' => $project->id,
            'instrument_version_id' => $version->id,
            'indicator_id' => $indicator->id,
        ]);
        $this->actingAs($manager);
        $assignment = app(LedAssessorAssignmentService::class)->assign(
            $cycle,
            $indicator,
            $assignee,
            $role,
            $manager,
            'Menetapkan assignment untuk navigation test.',
        );

        if ($cycle->status !== $status) {
            $cycle->update(['status' => $status]);
            $cycle->refresh();
        }

        return compact('manager', 'project', 'version', 'indicator', 'cycle', 'assessment', 'assignment');
    }

    private function project(?User $manager = null): AccreditationProject
    {
        $program = StudyProgram::create([
            'code' => Str::random(8),
            'name' => 'Navigation Program '.Str::random(4),
            'is_active' => true,
        ]);
        $instrument = AccreditationInstrument::create([
            'code' => Str::random(8),
            'name' => 'Navigation Instrument '.Str::random(4),
        ]);
        $project = AccreditationProject::create([
            'code' => Str::random(10),
            'name' => 'Navigation Project '.Str::random(4),
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

        return $project;
    }

    private function criterion(AccreditationProject $project): AccreditationCriterion
    {
        return AccreditationCriterion::create([
            'project_id' => $project->id,
            'code' => 'A',
            'name' => 'Navigation Criterion',
            'color_header' => '#173E70',
            'color_body' => '#E7EEF8',
            'position' => 1,
        ]);
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
