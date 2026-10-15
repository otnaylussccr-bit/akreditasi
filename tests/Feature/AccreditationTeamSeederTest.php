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
use App\Models\AccreditationReviewSubmission;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\AccreditationAuthorizationService;
use Database\Seeders\AccreditationRolePermissionSeeder;
use Database\Seeders\AccreditationTeamSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AccreditationTeamSeederTest extends TestCase
{
    private const OLD_EMAILS = [
        'eko.alif@akreditasi.apu.invalid',
        'dian.respati@akreditasi.apu.invalid',
        'salindri.prawitasari@akreditasi.apu.invalid',
        'nurul.hidayah@akreditasi.apu.invalid',
        'buanasari@akreditasi.apu.invalid',
    ];

    private const FINAL_USERS = [
        'eko.alif@akreditasi.apu' => ['drh. Eko Alif Bin Anto, M.Si', 'PIC Kriteria', 'A'],
        'salindri.prawitasari@akreditasi.apu' => ['Salindri Prawitasari, S.Si., M.Si', 'PIC Kriteria', 'B'],
        'dian.respati@akreditasi.apu' => ['Dian Respati Ayu, S.Si., M.Biomed', 'PIC Kriteria', 'C'],
        'mariza.mustika@akreditasi.apu' => ['Bd. Mariza Mustika D, S.Tr.Keb., M.Tr.Keb', 'PIC Kriteria', 'D'],
        'nurul.hidayah@akreditasi.apu' => ['Nurul Hidayah, M. Biotech', 'PIC Kriteria', 'E'],
        'latifah.mutia@akreditasi.apu' => ['Latifah Mutia Sari, S.Hum', 'PIC Kriteria', 'F'],
        'buanasari@akreditasi.apu' => ['Dr. Buanasari, M.T.', 'Reviewer', 'A-F'],
    ];

    private AccreditationProject $project;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
        $this->project = $this->createBiotechnologyProject();
        $this->superAdmin = $this->user('Existing Super Admin', 'admin@example.test', 'Super Admin');
        $this->seedOldTeam();
    }

    public function test_clean_reprovisioning_only_replaces_old_team_and_builds_final_matrix(): void
    {
        $unrelated = $this->user('Unrelated Viewer', 'viewer@example.test', 'Viewer');
        $oldIds = User::whereIn('email', self::OLD_EMAILS)->pluck('id');

        $seeder = $this->runTeamSeeder();

        $this->assertSame(0, User::whereIn('email', self::OLD_EMAILS)->count());
        $this->assertSame(7, User::whereIn('email', array_keys(self::FINAL_USERS))->count());
        $this->assertTrue($this->superAdmin->fresh()->hasRole('Super Admin'));
        $this->assertTrue($unrelated->fresh()->hasRole('Viewer'));
        $this->assertDatabaseMissing('model_has_roles', ['model_id' => $oldIds->first()]);
        $this->assertDatabaseCount('accreditation_criterion_assignments', 12);
        $this->assertDatabaseCount('accreditation_project_members', 7);
        $this->assertDatabaseMissing('accreditation_criterion_assignments', ['is_active' => false]);
        $this->assertDatabaseMissing('accreditation_project_members', ['is_active' => false]);

        foreach (self::FINAL_USERS as $email => [$name, $role, $criterion]) {
            $user = User::where('email', $email)->sole();
            $this->assertSame($name, $user->name);
            $this->assertSame([$role], $user->getRoleNames()->all());
            $this->assertMembership($user, $role === 'Reviewer' ? 'reviewer' : 'pic');

            if ($criterion !== 'A-F') {
                $this->assertAssignment($user, $criterion, 'pic');
            }
        }

        $buanasari = User::where('email', 'buanasari@akreditasi.apu')->sole();
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $code) {
            $this->assertAssignment($buanasari, $code, 'reviewer');
            $criterion = $this->criterion($code);
            $this->assertSame(1, $criterion->assignments()->where('assignment_role', 'pic')->where('is_active', true)->count());
            $this->assertSame(1, $criterion->assignments()->where('assignment_role', 'reviewer')->where('is_active', true)->count());
        }

        $this->assertFalse($buanasari->hasRole('PIC Kriteria'));
        $this->assertFalse($buanasari->criterionAssignments()->where('assignment_role', 'pic')->exists());
        $this->assertCount(7, $seeder->credentials);
        $this->assertCount(7, array_unique(array_column($seeder->credentials, 'password')));

        foreach ($seeder->credentials as $credential) {
            $password = $credential['password'];
            $user = User::where('email', $credential['email'])->sole();
            $this->assertSame(8, strlen($password));
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            $this->assertMatchesRegularExpression('/[^A-Za-z0-9]/', $password);
            $this->assertTrue(Hash::check($password, $user->password));
            $this->assertNotSame($password, $user->password);
        }
    }

    public function test_second_run_is_idempotent_and_does_not_reset_passwords(): void
    {
        $this->runTeamSeeder();
        $passwords = User::whereIn('email', array_keys(self::FINAL_USERS))->pluck('password', 'email')->all();

        $secondRun = $this->runTeamSeeder();

        $this->assertSame($passwords, User::whereIn('email', array_keys(self::FINAL_USERS))->pluck('password', 'email')->all());
        $this->assertSame([], $secondRun->credentials);
        $this->assertDatabaseCount('accreditation_criterion_assignments', 12);
        $this->assertDatabaseCount('accreditation_project_members', 7);
        $this->assertTrue($this->superAdmin->fresh()->hasRole('Super Admin'));
    }

    public function test_reprovisioning_is_blocked_when_an_old_user_owns_a_document(): void
    {
        $oldUser = User::where('email', self::OLD_EMAILS[0])->sole();
        $this->document($this->requirement('A'), $oldUser);

        $this->assertPreflightBlockedWithoutChanges('dokumen upload');
    }

    #[DataProvider('operationalReferenceProvider')]
    public function test_reprovisioning_is_blocked_by_submission_review_or_audit_reference(string $reference): void
    {
        $oldUser = User::where('email', self::OLD_EMAILS[0])->sole();
        $requirement = $this->requirement('A');

        if ($reference === 'submitted_by') {
            $this->submission($requirement, $oldUser);
        } elseif ($reference === 'decided_by') {
            $submission = $this->submission($requirement, $this->superAdmin);
            $submission->update(['decided_by' => $oldUser->id, 'decided_at' => now()]);
        } elseif ($reference === 'review_event') {
            $submission = $this->submission($requirement, $this->superAdmin);
            DB::table('accreditation_review_events')->insert([
                'submission_id' => $submission->id,
                'actor_id' => $oldUser->id,
                'action' => 'verified',
                'created_at' => now(),
            ]);
        } else {
            AccreditationActivityLog::create([
                'project_id' => $this->project->id,
                'actor_id' => $oldUser->id,
                'event' => 'test.reference',
            ]);
        }

        $this->assertPreflightBlockedWithoutChanges('referensi operasional');
    }

    public static function operationalReferenceProvider(): array
    {
        return [
            'submission submitted_by' => ['submitted_by'],
            'submission decided_by' => ['decided_by'],
            'review event actor' => ['review_event'],
            'activity log actor' => ['activity_log'],
        ];
    }

    public function test_reprovisioning_is_blocked_when_a_final_email_already_exists(): void
    {
        $this->user('Conflicting User', 'mariza.mustika@akreditasi.apu', 'Viewer');

        $this->assertPreflightBlockedWithoutChanges('email login final sudah digunakan', 1);
    }

    public function test_reprovisioning_requires_exactly_five_old_accounts(): void
    {
        $missing = User::where('email', self::OLD_EMAILS[0])->sole();
        AccreditationCriterionAssignment::where('user_id', $missing->id)->delete();
        AccreditationProjectMember::where('user_id', $missing->id)->delete();
        $missing->syncRoles([]);
        $missing->delete();

        $this->assertPreflightBlockedWithoutChanges('tepat lima akun lama');
    }

    public function test_transaction_rolls_back_when_final_user_provisioning_fails(): void
    {
        $oldIds = User::whereIn('email', self::OLD_EMAILS)->pluck('id')->sort()->values()->all();
        User::creating(function (User $user): void {
            if (str_ends_with($user->email, '@akreditasi.apu')) {
                throw new RuntimeException('Simulated provisioning failure.');
            }
        });

        try {
            $this->runTeamSeeder();
            $this->fail('Seeder seharusnya gagal saat provisioning user final.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated provisioning failure.', $exception->getMessage());
        }

        $this->assertSame($oldIds, User::whereIn('email', self::OLD_EMAILS)->pluck('id')->sort()->values()->all());
        $this->assertSame(0, User::whereIn('email', array_keys(self::FINAL_USERS))->count());
        $this->assertDatabaseCount('accreditation_criterion_assignments', 12);
        $this->assertDatabaseCount('accreditation_project_members', 6);
        $this->assertTrue($this->superAdmin->fresh()->hasRole('Super Admin'));
    }

    public function test_final_team_respects_existing_upload_and_review_authorization(): void
    {
        $this->runTeamSeeder();
        $authorization = app(AccreditationAuthorizationService::class);
        $eko = User::where('email', 'eko.alif@akreditasi.apu')->sole();
        $latifah = User::where('email', 'latifah.mutia@akreditasi.apu')->sole();
        $buanasari = User::where('email', 'buanasari@akreditasi.apu')->sole();
        $requirementA = $this->requirement('A');
        $requirementB = $this->requirement('B');
        $requirementF = $this->requirement('F');
        $submission = $this->submission($requirementA, $eko);
        $ownSubmission = $this->submission($requirementB, $buanasari);

        $this->assertTrue($authorization->canUploadRequirement($eko, $requirementA));
        $this->assertFalse($authorization->canUploadRequirement($eko, $requirementB));
        $this->assertTrue($authorization->canUploadRequirement($latifah, $requirementF));
        $this->assertFalse($authorization->canUploadRequirement($buanasari, $requirementA));
        $this->assertTrue($authorization->canReviewSubmission($buanasari, $submission));
        $this->assertFalse($authorization->canReviewSubmission($buanasari, $ownSubmission));
    }

    public function test_reprovisioning_does_not_start_without_safe_interactive_output(): void
    {
        $seeder = new AccreditationTeamSeeder;
        $seeder->setContainer($this->app);

        try {
            $seeder();
            $this->fail('Seeder seharusnya ditolak tanpa output interaktif yang aman.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('terminal lokal interaktif', $exception->getMessage());
        }

        $this->assertSame(5, User::whereIn('email', self::OLD_EMAILS)->count());
        $this->assertSame(0, User::whereIn('email', array_keys(self::FINAL_USERS))->count());
    }

    private function runTeamSeeder(): TestableAccreditationTeamSeeder
    {
        $seeder = new TestableAccreditationTeamSeeder;
        $seeder->setContainer($this->app);
        $seeder();

        return $seeder;
    }

    private function assertPreflightBlockedWithoutChanges(string $message, int $expectedFinalUsers = 0): void
    {
        $oldIds = User::whereIn('email', self::OLD_EMAILS)->pluck('id')->sort()->values()->all();

        try {
            $this->runTeamSeeder();
            $this->fail('Seeder seharusnya dihentikan oleh preflight.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertSame($oldIds, User::whereIn('email', self::OLD_EMAILS)->pluck('id')->sort()->values()->all());
        $this->assertSame($expectedFinalUsers, User::whereIn('email', array_keys(self::FINAL_USERS))->count());
        $this->assertTrue($this->superAdmin->fresh()->hasRole('Super Admin'));
    }

    private function createBiotechnologyProject(): AccreditationProject
    {
        $program = StudyProgram::create([
            'code' => 'S1-BIOTEK',
            'name' => 'Bioteknologi',
            'is_active' => true,
        ]);
        $project = AccreditationProject::create([
            'code' => 'APU-BIOTEK-2026',
            'name' => 'Akreditasi Prodi Bioteknologi APU',
            'study_program_id' => $program->id,
            'final_deadline' => '2026-10-30',
            'status' => 'active',
        ]);

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $position => $code) {
            AccreditationCriterion::create([
                'project_id' => $project->id,
                'code' => $code,
                'name' => 'Kriteria '.$code,
                'color_header' => '#173E70',
                'color_body' => '#E7EEF8',
                'position' => $position + 1,
            ]);
        }

        return $project;
    }

    private function seedOldTeam(): void
    {
        $definitions = [
            self::OLD_EMAILS[0] => ['drh. Eko Alif Bin Anto, M.Si', ['PIC Kriteria'], ['A'], []],
            self::OLD_EMAILS[1] => ['Dian Respati Ayu, S.Si., M.Biomed', ['PIC Kriteria'], ['C', 'D'], []],
            self::OLD_EMAILS[2] => ['Salindri Prawitasari, S.Si., M.Si', ['PIC Kriteria'], ['B'], []],
            self::OLD_EMAILS[3] => ['Nurul Hidayah, M. Biotech', ['PIC Kriteria'], ['E'], []],
            self::OLD_EMAILS[4] => ['Dr. Buanasari, M.T.', ['PIC Kriteria', 'Reviewer'], ['F'], ['A', 'B', 'C', 'D', 'E', 'F']],
        ];

        foreach ($definitions as $email => [$name, $roles, $picCriteria, $reviewCriteria]) {
            $user = $this->user($name, $email, ...$roles);
            $this->membership($user, 'pic');

            if ($reviewCriteria !== []) {
                $this->membership($user, 'reviewer');
            }

            foreach ($picCriteria as $code) {
                $this->assignment($user, $code, 'pic');
            }

            foreach ($reviewCriteria as $code) {
                $this->assignment($user, $code, 'reviewer');
            }
        }
    }

    private function user(string $name, string $email, string ...$roles): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::random(20)),
        ]);
        $user->assignRole($roles);

        return $user;
    }

    private function membership(User $user, string $role): void
    {
        AccreditationProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $user->id,
            'membership_role' => $role,
            'is_active' => true,
        ]);
    }

    private function assignment(User $user, string $criterionCode, string $role): void
    {
        AccreditationCriterionAssignment::create([
            'criterion_id' => $this->criterion($criterionCode)->id,
            'user_id' => $user->id,
            'assignment_role' => $role,
            'is_active' => true,
        ]);
    }

    private function assertMembership(User $user, string $role): void
    {
        $this->assertDatabaseHas('accreditation_project_members', [
            'project_id' => $this->project->id,
            'user_id' => $user->id,
            'membership_role' => $role,
            'is_active' => true,
        ]);
    }

    private function assertAssignment(User $user, string $criterionCode, string $role): void
    {
        $this->assertDatabaseHas('accreditation_criterion_assignments', [
            'criterion_id' => $this->criterion($criterionCode)->id,
            'user_id' => $user->id,
            'assignment_role' => $role,
            'is_active' => true,
        ]);
    }

    private function criterion(string $code): AccreditationCriterion
    {
        return AccreditationCriterion::where('project_id', $this->project->id)
            ->where('code', $code)
            ->sole();
    }

    private function requirement(string $criterionCode): AccreditationDocumentRequirement
    {
        $criterion = $this->criterion($criterionCode);
        $item = AccreditationItem::create([
            'criterion_id' => $criterion->id,
            'number' => 2,
            'position' => 2,
            'title' => 'Butir '.$criterionCode.' '.Str::random(6),
        ]);

        return AccreditationDocumentRequirement::create([
            'item_id' => $item->id,
            'number' => 1,
            'title' => 'Dokumen '.$criterionCode,
            'is_required' => true,
            'workflow_status' => 'not_started',
            'current_review_revision' => 0,
        ]);
    }

    private function submission(
        AccreditationDocumentRequirement $requirement,
        User $submitter,
    ): AccreditationReviewSubmission {
        return AccreditationReviewSubmission::create([
            'requirement_id' => $requirement->id,
            'submitted_by' => $submitter->id,
            'revision_number' => 1,
            'status' => 'pending_review',
            'submitted_at' => now(),
        ]);
    }

    private function document(AccreditationDocumentRequirement $requirement, User $uploader): AccreditationDocument
    {
        return AccreditationDocument::create([
            'requirement_id' => $requirement->id,
            'uploaded_by' => $uploader->id,
            'original_name' => 'owned-document.pdf',
            'disk' => 'local',
            'storage_path' => 'accreditation/'.Str::uuid().'.pdf',
            'storage_uuid' => (string) Str::uuid(),
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', (string) Str::uuid()),
            'revision_number' => 1,
            'is_active' => true,
            'uploaded_at' => now(),
        ]);
    }
}

class TestableAccreditationTeamSeeder extends AccreditationTeamSeeder
{
    public array $credentials = [];

    protected function canDisplayInitialCredentials(): bool
    {
        return true;
    }

    protected function displayInitialCredentials(array $credentials): void
    {
        $this->credentials = $credentials;
    }
}
