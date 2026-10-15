<?php

namespace Database\Seeders;

use App\Models\AccreditationActivityLog;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationCriterionAssignment;
use App\Models\AccreditationDocument;
use App\Models\AccreditationProject;
use App\Models\AccreditationProjectMember;
use App\Models\AccreditationReviewSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\Models\Role;

class AccreditationTeamSeeder extends Seeder
{
    private const PROJECT_CODE = 'APU-BIOTEK-2026';

    private const OLD_TEAM = [
        'eko.alif@akreditasi.apu.invalid' => 'drh. Eko Alif Bin Anto, M.Si',
        'dian.respati@akreditasi.apu.invalid' => 'Dian Respati Ayu, S.Si., M.Biomed',
        'salindri.prawitasari@akreditasi.apu.invalid' => 'Salindri Prawitasari, S.Si., M.Si',
        'nurul.hidayah@akreditasi.apu.invalid' => 'Nurul Hidayah, M. Biotech',
        'buanasari@akreditasi.apu.invalid' => 'Dr. Buanasari, M.T.',
    ];

    private const FINAL_TEAM = [
        'eko.alif@akreditasi.apu' => [
            'name' => 'drh. Eko Alif Bin Anto, M.Si',
            'role' => 'PIC Kriteria',
            'membership' => 'pic',
            'assignments' => ['A'],
        ],
        'salindri.prawitasari@akreditasi.apu' => [
            'name' => 'Salindri Prawitasari, S.Si., M.Si',
            'role' => 'PIC Kriteria',
            'membership' => 'pic',
            'assignments' => ['B'],
        ],
        'dian.respati@akreditasi.apu' => [
            'name' => 'Dian Respati Ayu, S.Si., M.Biomed',
            'role' => 'PIC Kriteria',
            'membership' => 'pic',
            'assignments' => ['C'],
        ],
        'mariza.mustika@akreditasi.apu' => [
            'name' => 'Bd. Mariza Mustika D, S.Tr.Keb., M.Tr.Keb',
            'role' => 'PIC Kriteria',
            'membership' => 'pic',
            'assignments' => ['D'],
        ],
        'nurul.hidayah@akreditasi.apu' => [
            'name' => 'Nurul Hidayah, M. Biotech',
            'role' => 'PIC Kriteria',
            'membership' => 'pic',
            'assignments' => ['E'],
        ],
        'latifah.mutia@akreditasi.apu' => [
            'name' => 'Latifah Mutia Sari, S.Hum',
            'role' => 'PIC Kriteria',
            'membership' => 'pic',
            'assignments' => ['F'],
        ],
        'buanasari@akreditasi.apu' => [
            'name' => 'Dr. Buanasari, M.T.',
            'role' => 'Reviewer',
            'membership' => 'reviewer',
            'assignments' => ['A', 'B', 'C', 'D', 'E', 'F'],
        ],
    ];

    public function run(): void
    {
        $credentials = DB::transaction(function (): array {
            $project = $this->validatedProject();
            $criteria = $this->validatedCriteria($project);
            $this->validateRoles();

            $oldUsers = $this->usersByEmails(array_keys(self::OLD_TEAM));
            $finalUsers = $this->usersByEmails(array_keys(self::FINAL_TEAM));

            if ($oldUsers->isEmpty()) {
                $this->validateFinalState($project, $criteria, $finalUsers);

                return [];
            }

            $this->validateReplacementState($project, $criteria, $oldUsers, $finalUsers);

            if (! $this->canDisplayInitialCredentials()) {
                throw new RuntimeException(
                    'Clean re-provisioning tidak dijalankan karena kredensial awal hanya boleh ditampilkan pada terminal lokal interaktif.',
                );
            }

            $superAdminIds = User::role('Super Admin')->pluck('id')->sort()->values();
            $oldUserIds = $oldUsers->pluck('id');

            AccreditationCriterionAssignment::query()->whereIn('user_id', $oldUserIds)->delete();
            AccreditationProjectMember::query()->whereIn('user_id', $oldUserIds)->delete();

            foreach ($oldUsers as $user) {
                $user->syncRoles([]);
                $user->delete();
            }

            $credentials = $this->provisionFinalTeam($project, $criteria);
            $this->validateFinalState(
                $project,
                $criteria,
                $this->usersByEmails(array_keys(self::FINAL_TEAM)),
            );

            if (User::role('Super Admin')->pluck('id')->sort()->values()->all() !== $superAdminIds->all()) {
                throw new RuntimeException('Verifikasi gagal: akun Super Admin berubah selama clean re-provisioning.');
            }

            return $credentials;
        });

        if ($credentials !== []) {
            $this->displayInitialCredentials($credentials);
        }
    }

    protected function canDisplayInitialCredentials(): bool
    {
        return isset($this->command)
            && app()->environment('local')
            && defined('STDOUT')
            && function_exists('stream_isatty')
            && stream_isatty(STDOUT);
    }

    protected function displayInitialCredentials(array $credentials): void
    {
        $this->command->warn('Kredensial berikut hanya ditampilkan satu kali. Simpan dan distribusikan secara aman.');
        $this->command->table(
            ['Name', 'Email Login', 'Initial Password'],
            array_map(fn (array $credential): array => [
                $credential['name'],
                $credential['email'],
                $credential['password'],
            ], $credentials),
        );
    }

    private function validatedProject(): AccreditationProject
    {
        $projects = AccreditationProject::query()
            ->where('code', self::PROJECT_CODE)
            ->get();

        if ($projects->count() !== 1) {
            throw new RuntimeException('Project APU-BIOTEK-2026 harus tersedia tepat satu kali sebelum team seeder dijalankan.');
        }

        return $projects->first();
    }

    private function validatedCriteria(AccreditationProject $project): Collection
    {
        $expectedCodes = ['A', 'B', 'C', 'D', 'E', 'F'];
        $criteria = AccreditationCriterion::query()
            ->where('project_id', $project->id)
            ->whereIn('code', $expectedCodes)
            ->get();

        if ($criteria->count() !== count($expectedCodes)
            || $criteria->pluck('code')->sort()->values()->all() !== $expectedCodes) {
            throw new RuntimeException('Kriteria A-F untuk project APU-BIOTEK-2026 belum lengkap atau tidak unik.');
        }

        return $criteria->keyBy('code');
    }

    private function validateRoles(): void
    {
        $expectedRoles = ['PIC Kriteria', 'Reviewer'];
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $expectedRoles)
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        sort($expectedRoles);

        if ($roles !== $expectedRoles) {
            throw new RuntimeException('Role PIC Kriteria dan Reviewer dengan guard web harus tersedia sebelum team seeder dijalankan.');
        }
    }

    private function validateReplacementState(
        AccreditationProject $project,
        Collection $criteria,
        Collection $oldUsers,
        Collection $finalUsers,
    ): void {
        if ($oldUsers->count() !== count(self::OLD_TEAM)) {
            throw new RuntimeException('Preflight gagal: tepat lima akun lama harus ditemukan.');
        }

        foreach (self::OLD_TEAM as $email => $name) {
            if ($oldUsers[$email]->name !== $name) {
                throw new RuntimeException("Preflight gagal: identitas akun lama {$email} tidak cocok.");
            }
        }

        if ($finalUsers->isNotEmpty()) {
            throw new RuntimeException('Preflight gagal: satu atau lebih email login final sudah digunakan.');
        }

        $oldUserIds = $oldUsers->pluck('id');
        $superAdminIds = User::role('Super Admin')->pluck('id');

        if ($oldUserIds->intersect($superAdminIds)->isNotEmpty()) {
            throw new RuntimeException('Preflight gagal: akun Super Admin termasuk dalam target penghapusan.');
        }

        if ($oldUsers->contains(fn (User $user): bool => $user->permissions->isNotEmpty())) {
            throw new RuntimeException('Preflight gagal: akun lama memiliki direct permission yang tidak diharapkan.');
        }

        $this->validateFinalNameConflicts($oldUserIds);
        $this->validateOperationalReferences($oldUsers);
        $this->validateNoCompetingActiveAssignments($criteria, $oldUserIds);

        if (! $project->exists) {
            throw new RuntimeException('Preflight gagal: project target tidak valid.');
        }
    }

    private function validateFinalNameConflicts(Collection $oldUserIds): void
    {
        $finalNames = array_column(self::FINAL_TEAM, 'name');
        $conflict = User::query()
            ->whereIn('name', $finalNames)
            ->whereNotIn('id', $oldUserIds)
            ->exists();

        if ($conflict) {
            throw new RuntimeException('Preflight gagal: identitas salah satu akun final sudah digunakan oleh akun lain.');
        }
    }

    private function validateOperationalReferences(Collection $oldUsers): void
    {
        $ids = $oldUsers->pluck('id');
        $emails = $oldUsers->pluck('email');
        $checks = [
            'dokumen upload' => AccreditationDocument::query()->whereIn('uploaded_by', $ids)->exists(),
            'submission sebagai pengirim' => AccreditationReviewSubmission::query()->whereIn('submitted_by', $ids)->exists(),
            'submission sebagai pemutus' => AccreditationReviewSubmission::query()->whereIn('decided_by', $ids)->exists(),
            'review event' => $this->tableReferenceExists('accreditation_review_events', 'actor_id', $ids),
            'activity log sebagai actor' => AccreditationActivityLog::query()->whereIn('actor_id', $ids)->exists(),
            'activity log sebagai subject' => AccreditationActivityLog::query()
                ->where('subject_type', User::class)
                ->whereIn('subject_id', $ids)
                ->exists(),
            'session login' => $this->tableReferenceExists('sessions', 'user_id', $ids),
            'password reset' => $this->tableReferenceExists('password_reset_tokens', 'email', $emails),
        ];

        foreach ($checks as $label => $exists) {
            if ($exists) {
                throw new RuntimeException("Preflight gagal: ditemukan referensi operasional {$label} untuk akun lama.");
            }
        }
    }

    private function validateNoCompetingActiveAssignments(Collection $criteria, Collection $oldUserIds): void
    {
        $exists = AccreditationCriterionAssignment::query()
            ->whereIn('criterion_id', $criteria->pluck('id'))
            ->where('is_active', true)
            ->whereNotIn('user_id', $oldUserIds)
            ->whereIn('assignment_role', ['pic', 'reviewer'])
            ->exists();

        if ($exists) {
            throw new RuntimeException('Preflight gagal: terdapat assignment aktif non-target pada Kriteria A-F.');
        }
    }

    private function provisionFinalTeam(AccreditationProject $project, Collection $criteria): array
    {
        $credentials = [];
        $passwords = [];

        foreach (self::FINAL_TEAM as $email => $definition) {
            $password = $this->uniquePassword($passwords);
            $passwords[] = $password;
            $user = new User;
            $user->name = $definition['name'];
            $user->email = $email;
            $user->email_verified_at = now();
            $user->password = Hash::make($password);
            $user->save();
            $user->assignRole($definition['role']);

            AccreditationProjectMember::query()->create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'membership_role' => $definition['membership'],
                'is_active' => true,
            ]);

            $assignmentRole = $definition['role'] === 'Reviewer' ? 'reviewer' : 'pic';

            foreach ($definition['assignments'] as $criterionCode) {
                AccreditationCriterionAssignment::query()->create([
                    'criterion_id' => $criteria[$criterionCode]->id,
                    'user_id' => $user->id,
                    'assignment_role' => $assignmentRole,
                    'is_active' => true,
                ]);
            }

            $credentials[] = [
                'name' => $user->name,
                'email' => $user->email,
                'password' => $password,
            ];
        }

        return $credentials;
    }

    private function validateFinalState(
        AccreditationProject $project,
        Collection $criteria,
        Collection $finalUsers,
    ): void {
        if ($this->usersByEmails(array_keys(self::OLD_TEAM))->isNotEmpty()
            || $finalUsers->count() !== count(self::FINAL_TEAM)) {
            throw new RuntimeException('Verifikasi gagal: jumlah akun lama atau akun final tidak sesuai.');
        }

        foreach (self::FINAL_TEAM as $email => $definition) {
            $user = $finalUsers[$email] ?? null;

            if (! $user
                || $user->name !== $definition['name']
                || $user->getRoleNames()->sort()->values()->all() !== [$definition['role']]) {
                throw new RuntimeException("Verifikasi gagal: identitas atau role akun final {$email} tidak sesuai.");
            }

            $memberships = AccreditationProjectMember::query()
                ->where('project_id', $project->id)
                ->where('user_id', $user->id)
                ->get();

            if ($memberships->count() !== 1
                || ! $memberships->first()->is_active
                || $memberships->first()->membership_role !== $definition['membership']) {
                throw new RuntimeException("Verifikasi gagal: membership akun final {$email} tidak sesuai.");
            }
        }

        $this->validateFinalAssignments($criteria, $finalUsers);
    }

    private function validateFinalAssignments(Collection $criteria, Collection $finalUsers): void
    {
        foreach ($criteria as $code => $criterion) {
            $expectedPicEmail = collect(self::FINAL_TEAM)
                ->search(fn (array $definition): bool => $definition['role'] === 'PIC Kriteria'
                    && in_array($code, $definition['assignments'], true));
            $expectedReviewer = $finalUsers['buanasari@akreditasi.apu'];
            $activeAssignments = AccreditationCriterionAssignment::query()
                ->where('criterion_id', $criterion->id)
                ->where('is_active', true)
                ->get();
            $picAssignments = $activeAssignments->where('assignment_role', 'pic');
            $reviewerAssignments = $activeAssignments->where('assignment_role', 'reviewer');

            if ($picAssignments->count() !== 1
                || ! is_string($expectedPicEmail)
                || $picAssignments->first()->user_id !== $finalUsers[$expectedPicEmail]->id
                || $reviewerAssignments->count() !== 1
                || $reviewerAssignments->first()->user_id !== $expectedReviewer->id) {
                throw new RuntimeException("Verifikasi gagal: assignment aktif Kriteria {$code} tidak sesuai.");
            }
        }
    }

    private function usersByEmails(array $emails): Collection
    {
        return User::query()
            ->with(['roles', 'permissions'])
            ->whereIn(DB::raw('LOWER(email)'), $emails)
            ->get()
            ->keyBy(fn (User $user): string => strtolower($user->email));
    }

    private function tableReferenceExists(string $table, string $column, Collection $values): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        $model = new class extends Model
        {
            public $timestamps = false;

            protected $guarded = [];
        };
        $model->setTable($table);

        return $model->newQuery()->whereIn($column, $values)->exists();
    }

    private function uniquePassword(array $existingPasswords): string
    {
        do {
            $password = $this->randomPassword();
        } while (in_array($password, $existingPasswords, true));

        return $password;
    }

    private function randomPassword(): string
    {
        $groups = [
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'abcdefghijkmnopqrstuvwxyz',
            '23456789',
            '!@#$%^&*',
        ];
        $characters = array_map(
            fn (string $group): string => $group[random_int(0, strlen($group) - 1)],
            $groups,
        );
        $all = implode('', $groups);

        while (count($characters) < 8) {
            $characters[] = $all[random_int(0, strlen($all) - 1)];
        }

        for ($index = count($characters) - 1; $index > 0; $index--) {
            $swap = random_int(0, $index);
            [$characters[$index], $characters[$swap]] = [$characters[$swap], $characters[$index]];
        }

        return implode('', $characters);
    }
}
