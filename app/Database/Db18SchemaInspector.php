<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Db18SchemaInspector
{
    private const DATABASE_COLLATIONS = [
        'utf8mb4_general_ci',
        'utf8mb4_unicode_ci',
    ];

    private const PARENTS = [
        'users',
        'study_programs',
        'accreditation_instruments',
        'accreditation_projects',
        'accreditation_criteria',
        'accreditation_items',
        'accreditation_documents',
        'accreditation_submission_documents',
    ];

    private const POINTER_FOREIGN_KEYS = [
        'fk_led_approved_ptr',
        'fk_led_working_ptr',
    ];

    private const SCOPE_KEYS = [
        'uq_led_project_instrument' => ['accreditation_projects', ['id', 'instrument_id']],
        'uq_led_criterion_project' => ['accreditation_criteria', ['id', 'project_id']],
        'uq_led_item_criterion' => ['accreditation_items', ['id', 'criterion_id']],
    ];

    /** @return array{database: string, server_version: string} */
    public static function assertCompatibleDatabase(): array
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('DB-18 migrations require MariaDB 10.4.x or 10.11.x.');
        }

        $identity = DB::selectOne(
            'SELECT DATABASE() AS database_name, VERSION() AS version, @@foreign_key_checks AS foreign_key_checks, @@check_constraint_checks AS check_constraint_checks',
        );

        if (! $identity || ! self::supportsServerVersion((string) $identity->version)) {
            throw new RuntimeException('DB-18 migrations require MariaDB 10.4.x or 10.11.x.');
        }

        $database = (string) $identity->database_name;

        if ($database === '' || in_array(strtolower($database), ['information_schema', 'mysql', 'performance_schema', 'sys'], true)) {
            throw new RuntimeException('DB-18 requires an explicit non-system database.');
        }

        if ((int) $identity->foreign_key_checks !== 1) {
            throw new RuntimeException('DB-18 migrations require foreign_key_checks=1.');
        }

        if ((int) $identity->check_constraint_checks !== 1) {
            throw new RuntimeException('DB-18 migrations require check_constraint_checks=1.');
        }

        $schema = DB::selectOne(
            'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()',
        );

        if (! $schema || ! self::supportsDatabaseDefaults(
            (string) $schema->DEFAULT_CHARACTER_SET_NAME,
            (string) $schema->DEFAULT_COLLATION_NAME,
        )) {
            throw new RuntimeException('DB-18 requires utf8mb4 with utf8mb4_general_ci or utf8mb4_unicode_ci as the database default.');
        }

        return ['database' => $database, 'server_version' => (string) $identity->version];
    }

    public static function supportsServerVersion(string $version): bool
    {
        return preg_match('/^10\.(?:4|11)\.\d+-MariaDB(?:$|[-+~])/i', $version) === 1;
    }

    public static function supportsDatabaseDefaults(string $characterSet, string $collation): bool
    {
        return strtolower($characterSet) === 'utf8mb4'
            && in_array(strtolower($collation), self::DATABASE_COLLATIONS, true);
    }

    public static function assertParentSchema(): void
    {
        $placeholders = implode(',', array_fill(0, count(self::PARENTS), '?'));
        $tables = DB::select(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
            self::PARENTS,
        );

        if (count($tables) !== count(self::PARENTS)) {
            throw new RuntimeException('DB-18 parent-table preflight failed. Expected all eight Phase 1 parent tables.');
        }

        foreach ($tables as $table) {
            if (strtoupper((string) $table->ENGINE) !== 'INNODB') {
                throw new RuntimeException("DB-18 parent table {$table->TABLE_NAME} must use InnoDB.");
            }
        }

        foreach ([
            ['users', 'id'],
            ['study_programs', 'id'],
            ['accreditation_instruments', 'id'],
            ['accreditation_projects', 'id'],
            ['accreditation_projects', 'instrument_id'],
            ['accreditation_criteria', 'id'],
            ['accreditation_criteria', 'project_id'],
            ['accreditation_items', 'id'],
            ['accreditation_items', 'criterion_id'],
            ['accreditation_documents', 'id'],
            ['accreditation_submission_documents', 'submission_id'],
            ['accreditation_submission_documents', 'document_id'],
        ] as [$table, $column]) {
            $metadata = DB::selectOne(
                'SELECT DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column],
            );

            if (! $metadata || $metadata->DATA_TYPE !== 'bigint' || ! str_contains(strtolower((string) $metadata->COLUMN_TYPE), 'unsigned')) {
                throw new RuntimeException("DB-18 requires {$table}.{$column} to be BIGINT UNSIGNED.");
            }
        }

        if (! self::indexMatches(
            'accreditation_submission_documents',
            'accred_snapshot_unique',
            ['submission_id', 'document_id'],
            true,
        )) {
            throw new RuntimeException('DB-18 requires unique accreditation_submission_documents(submission_id, document_id).');
        }
    }

    public static function tableExists(string $table): bool
    {
        return (int) DB::scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        ) === 1;
    }

    public static function assertTableMatches(int $statementIndex): void
    {
        $table = Db18Schema::TABLES[$statementIndex] ?? null;

        if (! $table || ! self::tableExists($table)) {
            throw new RuntimeException("DB-18 table {$table} does not exist.");
        }

        $statement = Db18Schema::statements()[$statementIndex];
        $tableMetadata = DB::selectOne(
            'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        if (! $tableMetadata
            || strtoupper((string) $tableMetadata->ENGINE) !== 'INNODB'
            || strtolower((string) $tableMetadata->TABLE_COLLATION) !== 'utf8mb4_unicode_ci') {
            throw new RuntimeException("DB-18 table {$table} has an incompatible engine or collation.");
        }

        self::assertColumns($table, $statement);
        self::assertIndexes($table, $statement);
        self::assertForeignKeys($table, $statement);
        self::assertChecks($table, $statement);
    }

    public static function assertResumableTablePrefix(): int
    {
        $presentCount = 0;
        $seenMissing = false;

        foreach (Db18Schema::TABLES as $index => $table) {
            if (! self::tableExists($table)) {
                $seenMissing = true;

                continue;
            }

            if ($seenMissing) {
                throw new RuntimeException('DB-18 target tables do not form a resumable LOCK-order prefix. No schema changes were made.');
            }

            self::assertTableMatches($index);
            $presentCount++;
        }

        $pointers = self::pointerForeignKeys();

        if (count($pointers) === 1) {
            throw new RuntimeException('DB-18 circular pointer foreign keys are in a partial state. No schema changes were made.');
        }

        if ($presentCount < count(Db18Schema::TABLES) && $pointers !== []) {
            throw new RuntimeException('DB-18 circular pointer foreign keys exist before the table prefix is complete. No schema changes were made.');
        }

        if (count($pointers) === 2) {
            self::assertCircularPointersMatch();
        }

        return $presentCount;
    }

    /** @return array<string, bool> */
    public static function scopeKeys(): array
    {
        $result = [];

        foreach (self::SCOPE_KEYS as $name => [$table, $columns]) {
            if (! self::indexExists($table, $name)) {
                $result[$name] = false;

                continue;
            }

            if (! self::indexMatches($table, $name, $columns, true)) {
                throw new RuntimeException("DB-18 scope key {$table}.{$name} is incompatible.");
            }

            $result[$name] = true;
        }

        return $result;
    }

    public static function assertScopeKeysComplete(): void
    {
        if (in_array(false, self::scopeKeys(), true)) {
            throw new RuntimeException('DB-18 requires all three compatible Phase 1 scope keys.');
        }
    }

    /** @return array{status: string, database: string, server_version: string, present_tables: list<string>, scope_keys: array<string, bool>, pointer_foreign_keys: list<string>, recorded_migrations: list<string>} */
    public static function report(): array
    {
        $identity = self::assertCompatibleDatabase();
        self::assertParentSchema();
        Db18Schema::statements();
        $present = [];
        $seenMissing = false;
        $hasGap = false;

        foreach (Db18Schema::TABLES as $index => $table) {
            if (! self::tableExists($table)) {
                $seenMissing = true;

                continue;
            }

            if ($seenMissing) {
                $hasGap = true;
            }

            self::assertTableMatches($index);
            $present[] = $table;
        }

        $scopeKeys = self::scopeKeys();
        $pointers = self::pointerForeignKeys();

        if (count($pointers) === 2) {
            self::assertCircularPointersMatch();
        }

        $recorded = DB::getSchemaBuilder()->hasTable('migrations')
            ? DB::table('migrations')->whereIn('migration', Db18Schema::MIGRATIONS)->orderBy('migration')->pluck('migration')->all()
            : [];
        $scopeCount = count(array_filter($scopeKeys));
        $migrationGap = $recorded !== array_slice(Db18Schema::MIGRATIONS, 0, count($recorded));
        $recordedArtifactsMissing = (in_array(Db18Schema::MIGRATIONS[0], $recorded, true) && $scopeCount !== 3)
            || (in_array(Db18Schema::MIGRATIONS[1], $recorded, true) && count($present) < 5)
            || (in_array(Db18Schema::MIGRATIONS[2], $recorded, true) && count($present) < 10)
            || (in_array(Db18Schema::MIGRATIONS[3], $recorded, true) && count($present) < 20)
            || (in_array(Db18Schema::MIGRATIONS[4], $recorded, true) && count($pointers) !== 2);

        $status = match (true) {
            $migrationGap || $recordedArtifactsMissing => 'INCONSISTENT_MIGRATION_STATE',
            $hasGap => 'INCONSISTENT_TABLE_GAP',
            count($present) < 20 && count($pointers) > 0 => 'INCONSISTENT_POINTER_ORDER',
            count($pointers) === 1 => 'INCONSISTENT_POINTER_FK',
            count($present) === 0 && $scopeCount === 0 => 'PRISTINE',
            count($present) === 0 && $scopeCount < 3 => 'PARTIAL_SCOPE_KEYS',
            count($present) === 0 => 'SCOPE_KEYS_ONLY',
            count($present) < 20 => 'PARTIAL_TABLE_PREFIX',
            count($pointers) === 0 => 'TABLES_COMPLETE_POINTERS_PENDING',
            count($pointers) === 2 => 'COMPLETE',
            default => 'INCONSISTENT',
        };

        return [
            'status' => $status,
            'database' => $identity['database'],
            'server_version' => $identity['server_version'],
            'present_tables' => $present,
            'scope_keys' => $scopeKeys,
            'pointer_foreign_keys' => $pointers,
            'recorded_migrations' => $recorded,
        ];
    }

    public static function assertCircularPointersMatch(): void
    {
        $actual = self::pointerForeignKeys();

        if ($actual !== self::POINTER_FOREIGN_KEYS) {
            throw new RuntimeException('DB-18 circular pointer foreign keys are incomplete or incompatible.');
        }

        $expected = [
            'fk_led_approved_ptr' => ['id', 'assessment_id', 'current_approved_revision_id', 'id'],
            'fk_led_working_ptr' => ['id', 'assessment_id', 'current_working_revision_id', 'id'],
        ];

        foreach ($expected as $name => $columns) {
            $rows = DB::select(
                'SELECT k.COLUMN_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE '
                .'FROM information_schema.KEY_COLUMN_USAGE k '
                .'JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
                .'WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.CONSTRAINT_NAME = ? ORDER BY k.ORDINAL_POSITION',
                ['accreditation_led_assessments', $name],
            );
            $actualColumns = [];

            foreach ($rows as $row) {
                $actualColumns[] = $row->COLUMN_NAME;
                $actualColumns[] = $row->REFERENCED_COLUMN_NAME;

                if ($row->UPDATE_RULE !== 'RESTRICT' || $row->DELETE_RULE !== 'RESTRICT') {
                    throw new RuntimeException("DB-18 foreign key {$name} must use RESTRICT rules.");
                }
            }

            if ($actualColumns !== $columns) {
                throw new RuntimeException("DB-18 foreign key {$name} has incompatible columns.");
            }
        }
    }

    /** @return list<string> */
    public static function circularPointerForeignKeys(): array
    {
        return self::pointerForeignKeys();
    }

    /** @param list<string> $columns */
    public static function indexMatches(string $table, string $name, array $columns, bool $unique): bool
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            [$table, $name],
        );

        if ($rows === []) {
            return false;
        }

        return array_map(static fn (object $row): string => $row->COLUMN_NAME, $rows) === $columns
            && collect($rows)->every(static fn (object $row): bool => (int) $row->NON_UNIQUE === ($unique ? 0 : 1));
    }

    private static function assertColumns(string $table, string $statement): void
    {
        preg_match_all('/^\s*`([^`]+)`\s+([A-Z]+(?:\([^)]+\))?(?:\s+UNSIGNED)?)(.*?)(?:,)?$/mi', $statement, $matches, PREG_SET_ORDER);
        $expected = collect($matches)->mapWithKeys(static fn (array $match): array => [$match[1] => [
            'type' => self::normalizeColumnType($match[2]),
            'nullable' => ! str_contains(strtoupper($match[3]), 'NOT NULL'),
            'auto_increment' => str_contains(strtoupper($match[3]), 'AUTO_INCREMENT'),
            'virtual' => str_contains(strtoupper($match[3]), 'GENERATED ALWAYS'),
            'generation_expression' => self::expectedGenerationExpression($match[3]),
        ]]);
        $actual = collect(DB::select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table],
        ));

        if ($actual->pluck('COLUMN_NAME')->all() !== $expected->keys()->all()) {
            throw new RuntimeException("DB-18 table {$table} has incompatible columns or column order.");
        }

        foreach ($actual as $column) {
            $definition = $expected[$column->COLUMN_NAME];
            $actualExpression = trim((string) $column->GENERATION_EXPRESSION);

            if (self::normalizeColumnType((string) $column->COLUMN_TYPE) !== $definition['type']
                || (($column->IS_NULLABLE === 'YES') !== $definition['nullable'])
                || (str_contains(strtolower((string) $column->EXTRA), 'auto_increment') !== $definition['auto_increment'])
                || (str_contains(strtolower((string) $column->EXTRA), 'virtual generated') !== $definition['virtual'])
                || (($actualExpression !== '') !== ($definition['generation_expression'] !== null))
                || ($actualExpression !== '' && self::normalizeSqlExpression($actualExpression) !== $definition['generation_expression'])) {
                throw new RuntimeException("DB-18 column {$table}.{$column->COLUMN_NAME} is incompatible with the LOCK.");
            }
        }
    }

    private static function assertIndexes(string $table, string $statement): void
    {
        $expected = [];

        if (preg_match('/PRIMARY KEY \(([^)]+)\)/i', $statement, $primary)) {
            $expected['PRIMARY'] = ['columns' => self::identifierList($primary[1]), 'unique' => true];
        }

        preg_match_all('/^\s*(UNIQUE KEY|KEY) `([^`]+)` \(([^)]+)\)/mi', $statement, $indexes, PREG_SET_ORDER);

        foreach ($indexes as $index) {
            $expected[$index[2]] = [
                'columns' => self::identifierList($index[3]),
                'unique' => strtoupper($index[1]) === 'UNIQUE KEY',
            ];
        }

        foreach ($expected as $name => $definition) {
            if (! self::indexMatches($table, $name, $definition['columns'], $definition['unique'])) {
                throw new RuntimeException("DB-18 index {$table}.{$name} is missing or incompatible.");
            }
        }
    }

    private static function assertForeignKeys(string $table, string $statement): void
    {
        preg_match_all(
            '/CONSTRAINT `([^`]+)` FOREIGN KEY \(([^)]+)\) REFERENCES `([^`]+)` \(([^)]+)\) ON UPDATE RESTRICT ON DELETE RESTRICT/i',
            $statement,
            $foreignKeys,
            PREG_SET_ORDER,
        );
        $expectedNames = array_column($foreignKeys, 1);
        $actualNames = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->when(
                $table === 'accreditation_led_assessments',
                fn ($query) => $query->whereNotIn('CONSTRAINT_NAME', self::POINTER_FOREIGN_KEYS),
            )
            ->orderBy('CONSTRAINT_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->all();
        sort($expectedNames);

        if ($actualNames !== $expectedNames) {
            throw new RuntimeException("DB-18 foreign keys for {$table} differ from the LOCK.");
        }

        foreach ($foreignKeys as $foreignKey) {
            $rows = DB::select(
                'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE '
                .'FROM information_schema.KEY_COLUMN_USAGE k '
                .'JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
                .'WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.CONSTRAINT_NAME = ? ORDER BY k.ORDINAL_POSITION',
                [$table, $foreignKey[1]],
            );

            if (array_map(static fn (object $row): string => $row->COLUMN_NAME, $rows) !== self::identifierList($foreignKey[2])
                || collect($rows)->pluck('REFERENCED_TABLE_NAME')->unique()->all() !== [$foreignKey[3]]
                || array_map(static fn (object $row): string => $row->REFERENCED_COLUMN_NAME, $rows) !== self::identifierList($foreignKey[4])
                || ! collect($rows)->every(static fn (object $row): bool => $row->UPDATE_RULE === 'RESTRICT' && $row->DELETE_RULE === 'RESTRICT')) {
                throw new RuntimeException("DB-18 foreign key {$table}.{$foreignKey[1]} is missing or incompatible.");
            }
        }
    }

    private static function assertChecks(string $table, string $statement): void
    {
        preg_match_all('/^\s*CONSTRAINT `([^`]+)` CHECK \((.*)\),?\s*$/mi', $statement, $checks, PREG_SET_ORDER);
        $expected = collect($checks)->mapWithKeys(static fn (array $check): array => [
            $check[1] => self::normalizeCheckExpression($check[2]),
        ])->sortKeys()->all();
        $actual = collect(DB::select(
            'SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE '
            .'FROM information_schema.TABLE_CONSTRAINTS tc '
            .'JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
            .'WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = ? '
            .'ORDER BY tc.CONSTRAINT_NAME',
            [$table, 'CHECK'],
        ))->mapWithKeys(static fn (object $check): array => [
            $check->CONSTRAINT_NAME => self::normalizeCheckExpression((string) $check->CHECK_CLAUSE),
        ])->all();

        if ($actual !== $expected) {
            $name = collect(array_unique([...array_keys($expected), ...array_keys($actual)]))
                ->first(fn (string $constraint): bool => ($expected[$constraint] ?? null) !== ($actual[$constraint] ?? null));

            throw new RuntimeException(
                "DB-18 CHECK constraint {$table}.{$name} is missing or incompatible "
                .'(expected '.($expected[$name] ?? 'missing').'; found '.($actual[$name] ?? 'missing').').',
            );
        }
    }

    /** @return list<string> */
    private static function pointerForeignKeys(): array
    {
        return DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'accreditation_led_assessments')
            ->whereIn('CONSTRAINT_NAME', self::POINTER_FOREIGN_KEYS)
            ->orderBy('CONSTRAINT_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->all();
    }

    private static function indexExists(string $table, string $name): bool
    {
        return (int) DB::scalar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $name],
        ) > 0;
    }

    private static function normalizeColumnType(string $type): string
    {
        $normalized = strtolower((string) preg_replace('/\s+/', ' ', trim($type)));

        return (string) preg_replace('/\b(bigint|int|smallint)\(\d+\)/', '$1', $normalized);
    }

    private static function expectedGenerationExpression(string $definition): ?string
    {
        if (! preg_match('/GENERATED ALWAYS AS \((.*)\) VIRTUAL/i', $definition, $expression)) {
            return null;
        }

        return self::normalizeSqlExpression($expression[1]);
    }

    private static function normalizeSqlExpression(string $expression): string
    {
        return strtolower(str_replace(['`', ' ', "\r", "\n", "\t"], '', trim($expression)));
    }

    private static function normalizeCheckExpression(string $expression): string
    {
        $normalized = self::normalizeSqlExpression($expression);

        return (string) preg_replace('/\(([^()]*(?:and)[^()]*)\)/', '$1', $normalized);
    }

    /** @return list<string> */
    private static function identifierList(string $sql): array
    {
        preg_match_all('/`([^`]+)`/', $sql, $identifiers);

        return $identifiers[1];
    }
}
