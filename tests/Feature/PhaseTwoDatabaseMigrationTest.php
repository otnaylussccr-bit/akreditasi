<?php

namespace Tests\Feature;

use App\Database\Db18Schema;
use App\Database\Db18SchemaInspector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class PhaseTwoDatabaseMigrationTest extends TestCase
{
    private const CONNECTION = 'phase2_mariadb_test';

    public function test_db18_migrations_and_integrity_contract_on_isolated_mariadb(): void
    {
        if (! filter_var(env('RUN_PHASE2_MARIADB_TESTS', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set RUN_PHASE2_MARIADB_TESTS=1 to run the isolated DB-18 migration contract.');
        }

        [$admin, $database] = $this->createIsolatedDatabase();

        try {
            $this->configureIsolatedConnection($database);
            $this->migrate($this->migrationPaths(false));
            $phaseOne = $this->insertPhaseOneFixtures();
            $before = $this->phaseOneFingerprint();
            $this->seedInterruptedPhaseTwoState();

            $partialReport = DB::usingConnection(
                self::CONNECTION,
                fn (): array => Db18SchemaInspector::report(),
            );
            $this->assertSame('PARTIAL_TABLE_PREFIX', $partialReport['status']);
            $this->assertCount(2, $partialReport['present_tables']);
            $this->assertSame(1, count(array_filter($partialReport['scope_keys'])));

            $this->migrate($this->migrationPaths(true));

            $this->assertSame($before, $this->phaseOneFingerprint());
            $completeReport = DB::usingConnection(
                self::CONNECTION,
                fn (): array => Db18SchemaInspector::report(),
            );
            $this->assertSame('COMPLETE', $completeReport['status']);
            $this->assertSame(Db18Schema::MIGRATIONS, $completeReport['recorded_migrations']);
            $this->assertSchemaMetadata();
            $this->assertIntegrityContracts($phaseOne);
        } finally {
            DB::purge(self::CONNECTION);
            $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
        }
    }

    /** @return array{PDO, string} */
    private function createIsolatedDatabase(): array
    {
        $config = config('database.connections.mariadb');
        $host = (string) ($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 0);

        if (! in_array($host, ['127.0.0.1', 'localhost'], true) || $port !== 3306) {
            $this->fail('Phase 2 integration tests may only use local MariaDB on port 3306.');
        }

        $admin = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            (string) $config['username'],
            (string) $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
        $identity = $admin->query('SELECT DATABASE() AS db, VERSION() AS version, @@foreign_key_checks AS fk')->fetch(PDO::FETCH_OBJ);

        $this->assertNull($identity->db);
        $this->assertStringStartsWith('10.4.32-MariaDB', $identity->version);
        $this->assertSame(1, (int) $identity->fk);

        $database = 'ams_phase2_test_'.getmypid().'_'.bin2hex(random_bytes(4));
        $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        return [$admin, $database];
    }

    private function configureIsolatedConnection(string $database): void
    {
        $config = config('database.connections.mariadb');
        $config['database'] = $database;
        $config['url'] = null;
        $config['timezone'] = '+00:00';

        config(['database.connections.'.self::CONNECTION => $config]);
        DB::purge(self::CONNECTION);
    }

    /** @return list<string> */
    private function migrationPaths(bool $phaseTwo): array
    {
        $paths = glob(database_path('migrations/*.php')) ?: [];

        if ($phaseTwo) {
            return array_map(
                static fn (string $migration): string => database_path("migrations/{$migration}.php"),
                Db18Schema::MIGRATIONS,
            );
        }

        return array_values(array_filter(
            $paths,
            static fn (string $path): bool => ! in_array(pathinfo($path, PATHINFO_FILENAME), Db18Schema::MIGRATIONS, true),
        ));
    }

    private function seedInterruptedPhaseTwoState(): void
    {
        $db = DB::connection(self::CONNECTION);
        $db->statement('ALTER TABLE `accreditation_projects` ADD UNIQUE KEY `uq_led_project_instrument` (`id`,`instrument_id`)');
        $db->unprepared(Db18Schema::statements()[0]);
        $db->unprepared(Db18Schema::statements()[1]);
    }

    /** @param list<string> $paths */
    private function migrate(array $paths): void
    {
        $exitCode = Artisan::call('migrate', [
            '--database' => self::CONNECTION,
            '--path' => $paths,
            '--realpath' => true,
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    /** @return array<string, int> */
    private function insertPhaseOneFixtures(): array
    {
        $db = DB::connection(self::CONNECTION);
        $now = '2026-10-19 01:00:00';
        $userOne = $db->table('users')->insertGetId([
            'name' => 'DB18 Test User One',
            'email' => 'db18-one@example.test',
            'password' => 'not-used',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userTwo = $db->table('users')->insertGetId([
            'name' => 'DB18 Test User Two',
            'email' => 'db18-two@example.test',
            'password' => 'not-used',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fixtures = ['user_one' => $userOne, 'user_two' => $userTwo];

        foreach ([1, 2] as $scope) {
            $program = $db->table('study_programs')->insertGetId([
                'code' => "DB18-P{$scope}",
                'name' => "DB18 Program {$scope}",
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $instrument = $db->table('accreditation_instruments')->insertGetId([
                'code' => "DB18-I{$scope}",
                'name' => "DB18 Instrument {$scope}",
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $project = $db->table('accreditation_projects')->insertGetId([
                'code' => "DB18-PRJ{$scope}",
                'name' => "DB18 Project {$scope}",
                'study_program_id' => $program,
                'instrument_id' => $instrument,
                'final_deadline' => '2027-10-19',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $criterion = $db->table('accreditation_criteria')->insertGetId([
                'project_id' => $project,
                'code' => "C{$scope}",
                'name' => "DB18 Criterion {$scope}",
                'color_header' => '#123456',
                'color_body' => '#ABCDEF',
                'position' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $item = $db->table('accreditation_items')->insertGetId([
                'criterion_id' => $criterion,
                'number' => 1,
                'position' => 1,
                'title' => "DB18 Item {$scope}",
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $requirement = $db->table('accreditation_document_requirements')->insertGetId([
                'item_id' => $item,
                'number' => 1,
                'title' => "DB18 Requirement {$scope}",
                'is_required' => true,
                'workflow_status' => 'verified',
                'current_review_revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $document = $db->table('accreditation_documents')->insertGetId([
                'requirement_id' => $requirement,
                'uploaded_by' => $userOne,
                'original_name' => "db18-evidence-{$scope}.pdf",
                'disk' => 'local',
                'storage_path' => "db18/evidence-{$scope}.pdf",
                'storage_uuid' => (string) Str::uuid(),
                'mime_type' => 'application/pdf',
                'extension' => 'pdf',
                'size_bytes' => 100,
                'sha256' => str_repeat((string) $scope, 64),
                'revision_number' => 1,
                'is_active' => true,
                'uploaded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $submission = $db->table('accreditation_review_submissions')->insertGetId([
                'requirement_id' => $requirement,
                'submitted_by' => $userOne,
                'revision_number' => 1,
                'status' => 'verified',
                'submitted_at' => $now,
                'decided_at' => $now,
                'decided_by' => $userTwo,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $db->table('accreditation_submission_documents')->insert([
                'submission_id' => $submission,
                'document_id' => $document,
            ]);

            foreach (compact('program', 'instrument', 'project', 'criterion', 'item', 'requirement', 'document', 'submission') as $key => $id) {
                $fixtures["{$key}_{$scope}"] = $id;
            }
        }

        return $fixtures;
    }

    private function phaseOneFingerprint(): string
    {
        $db = DB::connection(self::CONNECTION);
        $tables = [
            'users',
            'study_programs',
            'accreditation_instruments',
            'accreditation_projects',
            'accreditation_criteria',
            'accreditation_items',
            'accreditation_document_requirements',
            'accreditation_documents',
            'accreditation_review_submissions',
            'accreditation_submission_documents',
        ];
        $snapshot = [];

        foreach ($tables as $table) {
            $snapshot[$table] = $db->table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return hash('sha256', serialize($snapshot));
    }

    private function assertSchemaMetadata(): void
    {
        $db = DB::connection(self::CONNECTION);
        $placeholders = implode(',', array_fill(0, count(Db18Schema::TABLES), '?'));
        $tables = $db->select(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders}) ORDER BY TABLE_NAME",
            Db18Schema::TABLES,
        );
        $foreignKeys = $db->scalar(
            "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
            Db18Schema::TABLES,
        );
        $checks = $db->scalar(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK' AND TABLE_NAME IN ({$placeholders})",
            Db18Schema::TABLES,
        );
        $generated = $db->select(
            "SELECT TABLE_NAME, COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('active_primary_indicator_id','active_item_id','active_source_revision_id') ORDER BY TABLE_NAME",
        );

        $this->assertCount(20, $tables);
        $this->assertSame(66, (int) $foreignKeys);
        $this->assertSame(23, (int) $checks);
        $this->assertCount(3, $generated);
        $this->assertTrue(collect($generated)->every(fn (object $column): bool => str_contains($column->EXTRA, 'VIRTUAL GENERATED')));

        foreach ([
            'accreditation_projects' => ['id', 'instrument_id'],
            'accreditation_criteria' => ['id', 'project_id'],
            'accreditation_items' => ['id', 'criterion_id'],
        ] as $table => $columns) {
            $actual = $db->table('information_schema.STATISTICS')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', $table)
                ->where('INDEX_NAME', match ($table) {
                    'accreditation_projects' => 'uq_led_project_instrument',
                    'accreditation_criteria' => 'uq_led_criterion_project',
                    default => 'uq_led_item_criterion',
                })
                ->where('NON_UNIQUE', 0)
                ->orderBy('SEQ_IN_INDEX')
                ->pluck('COLUMN_NAME')
                ->all();

            $this->assertSame($columns, $actual);
        }
    }

    /** @param array<string, int> $phaseOne */
    private function assertIntegrityContracts(array $phaseOne): void
    {
        $db = DB::connection(self::CONNECTION);
        $at = '2026-10-19 02:00:00.000000';
        $version = [];
        $indicator = [];
        $rubric = [];
        $cycle = [];
        $assessment = [];
        $revision = [];

        foreach ([1, 2] as $scope) {
            $version[$scope] = $db->table('accreditation_led_instrument_versions')->insertGetId([
                'instrument_id' => $phaseOne["instrument_{$scope}"],
                'version_code' => "DB18-V{$scope}",
                'assessment_pathway' => 'test',
                'education_level' => 'test',
                'source_title' => "DB18 Source {$scope}",
                'source_reference' => "DB18-REF-{$scope}",
                'source_filename' => "db18-{$scope}.pdf",
                'source_disk' => 'local',
                'source_storage_path' => "db18/source-{$scope}.pdf",
                'source_pdf_sha256' => str_repeat((string) $scope, 64),
                'status' => 'draft',
                'created_by' => $phaseOne['user_one'],
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $indicator[$scope] = $db->table('accreditation_led_indicators')->insertGetId([
                'instrument_version_id' => $version[$scope],
                'indicator_number' => 1,
                'criterion_code' => "C{$scope}",
                'title' => "DB18 Indicator {$scope}",
                'position' => 1,
                'is_assessable' => true,
                'created_at' => $at,
            ]);
            $rubric[$scope] = $db->table('accreditation_led_rubrics')->insertGetId([
                'indicator_id' => $indicator[$scope],
                'category_code' => 'meets',
                'category_label' => 'Meets',
                'internal_score' => 4,
                'description' => 'DB18 test rubric',
                'position' => 1,
                'created_at' => $at,
            ]);
            $cycle[$scope] = $db->table('accreditation_led_assessment_cycles')->insertGetId([
                'project_id' => $phaseOne["project_{$scope}"],
                'instrument_id' => $phaseOne["instrument_{$scope}"],
                'instrument_version_id' => $version[$scope],
                'cycle_number' => 1,
                'cycle_name' => "DB18 Cycle {$scope}",
                'status' => 'draft',
                'created_by' => $phaseOne['user_one'],
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $assessment[$scope] = $db->table('accreditation_led_assessments')->insertGetId([
                'cycle_id' => $cycle[$scope],
                'project_id' => $phaseOne["project_{$scope}"],
                'instrument_version_id' => $version[$scope],
                'indicator_id' => $indicator[$scope],
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $revision[$scope] = $db->table('accreditation_led_assessment_revisions')->insertGetId([
                'assessment_id' => $assessment[$scope],
                'project_id' => $phaseOne["project_{$scope}"],
                'indicator_id' => $indicator[$scope],
                'revision_number' => 1,
                'rubric_id' => $rubric[$scope],
                'status' => 'approved',
                'created_by' => $phaseOne['user_one'],
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        $this->assertConstraintError(1452, fn () => $db->table('accreditation_led_assessments')->insert([
            'cycle_id' => $cycle[1],
            'project_id' => $phaseOne['project_2'],
            'instrument_version_id' => $version[1],
            'indicator_id' => $indicator[2],
            'created_at' => $at,
            'updated_at' => $at,
        ]));
        $this->assertConstraintError(1452, fn () => $db->table('accreditation_led_assessor_assignments')->insert([
            'cycle_id' => $cycle[1],
            'instrument_version_id' => $version[1],
            'indicator_id' => $indicator[2],
            'user_id' => $phaseOne['user_one'],
            'assignment_role' => 'reviewer',
            'assigned_by' => $phaseOne['user_two'],
            'assigned_at' => $at,
        ]));
        $primaryAssignment = $db->table('accreditation_led_assessor_assignments')->insertGetId([
            'cycle_id' => $cycle[1],
            'instrument_version_id' => $version[1],
            'indicator_id' => $indicator[1],
            'user_id' => $phaseOne['user_one'],
            'assignment_role' => 'assessor_primary',
            'assigned_by' => $phaseOne['user_two'],
            'assigned_at' => $at,
        ]);
        $primaryRow = [
            'cycle_id' => $cycle[1],
            'instrument_version_id' => $version[1],
            'indicator_id' => $indicator[1],
            'user_id' => $phaseOne['user_two'],
            'assignment_role' => 'assessor_primary',
            'assigned_by' => $phaseOne['user_one'],
            'assigned_at' => $at,
        ];
        $this->assertConstraintError(1062, fn () => $db->table('accreditation_led_assessor_assignments')->insert($primaryRow));
        $db->table('accreditation_led_assessor_assignments')->where('id', $primaryAssignment)->update([
            'revoked_at' => $at,
            'revoked_by' => $phaseOne['user_two'],
            'revocation_reason' => 'DB18 reassignment test',
        ]);
        $this->assertTrue($db->table('accreditation_led_assessor_assignments')->insert($primaryRow));

        $db->table('accreditation_led_assessments')->where('id', $assessment[1])->update([
            'current_approved_revision_id' => $revision[1],
            'current_working_revision_id' => $revision[1],
        ]);
        $this->assertConstraintError(1452, fn () => $db->table('accreditation_led_assessments')->where('id', $assessment[1])->update([
            'current_approved_revision_id' => $revision[2],
        ]));

        $evidence = $db->table('accreditation_led_assessment_evidence')->insertGetId([
            'revision_id' => $revision[1],
            'document_id' => $phaseOne['document_1'],
            'verification_submission_id' => $phaseOne['submission_1'],
            'document_revision_number' => 1,
            'document_sha256' => str_repeat('1', 64),
            'evidence_role' => 'primary',
            'linked_by' => $phaseOne['user_one'],
            'linked_at' => $at,
        ]);
        $this->assertGreaterThan(0, $evidence);
        $this->assertConstraintError(1452, fn () => $db->table('accreditation_led_assessment_evidence')->insert([
            'revision_id' => $revision[1],
            'document_id' => $phaseOne['document_2'],
            'verification_submission_id' => $phaseOne['submission_1'],
            'document_revision_number' => 1,
            'document_sha256' => str_repeat('2', 64),
            'evidence_role' => 'supporting',
            'linked_by' => $phaseOne['user_one'],
            'linked_at' => $at,
        ]));

        $unit = $db->table('qa_organizational_units')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'unit_code' => 'DB18-UNIT',
            'unit_name' => 'DB18 Test Unit',
            'unit_type' => 'test',
            'is_active' => true,
            'valid_from' => '2026-10-19',
            'created_by' => $phaseOne['user_one'],
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $db->table('qa_organizational_units')->where('id', $unit)->update([
            'deleted_at' => $at,
            'deleted_by' => $phaseOne['user_two'],
            'delete_reason' => 'DB18 test delete',
        ]);
        $this->assertConstraintError(1062, fn () => $db->table('qa_organizational_units')->insert([
            'uuid' => (string) Str::uuid(),
            'unit_code' => 'DB18-UNIT',
            'unit_name' => 'Duplicate Test Unit',
            'unit_type' => 'test',
            'is_active' => true,
            'valid_from' => '2026-10-19',
            'created_by' => $phaseOne['user_one'],
            'created_at' => $at,
            'updated_at' => $at,
        ]));
        $db->table('qa_organizational_units')->where('id', $unit)->update([
            'deleted_at' => null,
            'deleted_by' => null,
            'delete_reason' => null,
        ]);
        $this->assertNull($db->table('qa_organizational_units')->where('id', $unit)->value('deleted_at'));

        $mapping = $db->table('accreditation_led_indicator_item_mappings')->insertGetId([
            'cycle_id' => $cycle[1],
            'project_id' => $phaseOne['project_1'],
            'instrument_version_id' => $version[1],
            'indicator_id' => $indicator[1],
            'criterion_id' => $phaseOne['criterion_1'],
            'item_id' => $phaseOne['item_1'],
            'mapping_role' => 'supporting',
            'created_by' => $phaseOne['user_one'],
            'created_at' => $at,
        ]);
        $mappingRow = [
            'cycle_id' => $cycle[1],
            'project_id' => $phaseOne['project_1'],
            'instrument_version_id' => $version[1],
            'indicator_id' => $indicator[1],
            'criterion_id' => $phaseOne['criterion_1'],
            'item_id' => $phaseOne['item_1'],
            'mapping_role' => 'supporting',
            'created_by' => $phaseOne['user_one'],
            'created_at' => $at,
        ];
        $this->assertConstraintError(1062, fn () => $db->table('accreditation_led_indicator_item_mappings')->insert($mappingRow));
        $db->table('accreditation_led_indicator_item_mappings')->where('id', $mapping)->update([
            'revoked_at' => $at,
            'revoked_by' => $phaseOne['user_two'],
            'revoke_reason' => 'DB18 remap test',
        ]);
        $this->assertTrue($db->table('accreditation_led_indicator_item_mappings')->insert($mappingRow));

        $action = $db->table('qa_improvement_actions')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'action_code' => 'DB18-ACTION',
            'title' => 'DB18 Test Action',
            'description' => 'DB18 test action only',
            'owner_unit_id' => $unit,
            'status' => 'open',
            'priority' => 'normal',
            'created_by' => $phaseOne['user_one'],
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $db->table('qa_improvement_actions')->where('id', $action)->update([
            'deleted_at' => $at,
            'deleted_by' => $phaseOne['user_two'],
            'delete_reason' => 'DB18 test delete',
        ]);
        $this->assertConstraintError(1062, fn () => $db->table('qa_improvement_actions')->insert([
            'uuid' => (string) Str::uuid(),
            'action_code' => 'DB18-ACTION',
            'title' => 'Duplicate Action',
            'description' => 'duplicate',
            'owner_unit_id' => $unit,
            'created_by' => $phaseOne['user_one'],
            'created_at' => $at,
            'updated_at' => $at,
        ]));
        $db->table('qa_improvement_actions')->where('id', $action)->update([
            'deleted_at' => null,
            'deleted_by' => null,
            'delete_reason' => null,
        ]);
        $this->assertNull($db->table('qa_improvement_actions')->where('id', $action)->value('deleted_at'));

        $link = $db->table('accreditation_led_improvement_action_links')->insertGetId([
            'improvement_action_id' => $action,
            'project_id' => $phaseOne['project_1'],
            'source_revision_id' => $revision[1],
            'link_role' => 'origin',
            'linked_by' => $phaseOne['user_one'],
            'linked_at' => $at,
        ]);
        $linkRow = [
            'improvement_action_id' => $action,
            'project_id' => $phaseOne['project_1'],
            'source_revision_id' => $revision[1],
            'link_role' => 'origin',
            'linked_by' => $phaseOne['user_one'],
            'linked_at' => $at,
        ];
        $this->assertConstraintError(1062, fn () => $db->table('accreditation_led_improvement_action_links')->insert($linkRow));
        $db->table('accreditation_led_improvement_action_links')->where('id', $link)->update([
            'revoked_at' => $at,
            'revoked_by' => $phaseOne['user_two'],
            'revoke_reason' => 'DB18 relink test',
        ]);
        $this->assertTrue($db->table('accreditation_led_improvement_action_links')->insert($linkRow));

        $this->assertTrue($db->table('accreditation_led_review_events')->insert([
            'revision_id' => $revision[1],
            'actor_id' => $phaseOne['user_two'],
            'action' => 'approved',
            'payload_json' => '{"source":"db18-test"}',
            'occurred_at' => $at,
        ]));
        $this->assertConstraintError(1452, fn () => $db->table('accreditation_led_review_events')->insert([
            'revision_id' => $revision[1],
            'actor_id' => 999999,
            'action' => 'invalid-actor',
            'occurred_at' => $at,
        ]));
        $this->assertConstraintError(4025, fn () => $db->table('accreditation_led_review_events')->insert([
            'revision_id' => $revision[1],
            'actor_id' => $phaseOne['user_two'],
            'action' => 'invalid-json',
            'payload_json' => '{invalid',
            'occurred_at' => $at,
        ]));
        $this->assertConstraintError(4025, fn () => $db->table('accreditation_led_assessment_revisions')->insert([
            'assessment_id' => $assessment[1],
            'project_id' => $phaseOne['project_1'],
            'indicator_id' => $indicator[1],
            'revision_number' => 2,
            'status' => 'not-a-valid-state',
            'created_by' => $phaseOne['user_one'],
            'created_at' => $at,
            'updated_at' => $at,
        ]));
    }

    private function assertConstraintError(int $expectedError, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected MariaDB error {$expectedError} was not raised.");
        } catch (QueryException $exception) {
            $this->assertSame($expectedError, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
        }
    }
}
