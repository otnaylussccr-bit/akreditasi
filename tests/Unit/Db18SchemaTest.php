<?php

namespace Tests\Unit;

use App\Database\Db18Schema;
use App\Database\Db18SchemaInspector;
use Tests\TestCase;

class Db18SchemaTest extends TestCase
{
    public function test_authoritative_sql_is_hash_pinned_and_has_the_locked_statement_order(): void
    {
        $this->assertSame(Db18Schema::SHA256, hash_file('sha256', base_path('AMS_APU_DB17T_Full_DDL_CANDIDATE.sql')));
        $this->assertCount(20, Db18Schema::TABLES);
        $this->assertCount(21, Db18Schema::statements());

        foreach (Db18Schema::TABLES as $index => $table) {
            $this->assertStringStartsWith("CREATE TABLE `{$table}`", Db18Schema::statements()[$index]);
        }

        $this->assertStringStartsWith('ALTER TABLE `accreditation_led_assessments`', Db18Schema::statements()[20]);
    }

    public function test_compatibility_gate_accepts_only_mariadb_10_4_versions(): void
    {
        $this->assertTrue(Db18SchemaInspector::supportsServerVersion('10.4.0-MariaDB'));
        $this->assertTrue(Db18SchemaInspector::supportsServerVersion('10.4.32-MariaDB-1:10.4.32+maria~ubu2004'));
        $this->assertFalse(Db18SchemaInspector::supportsServerVersion('10.5.0-MariaDB'));
        $this->assertFalse(Db18SchemaInspector::supportsServerVersion('8.0.36'));
        $this->assertFalse(Db18SchemaInspector::supportsServerVersion('10.4.32'));
    }

    public function test_compatibility_gate_accepts_only_approved_utf8mb4_database_defaults(): void
    {
        $this->assertTrue(Db18SchemaInspector::supportsDatabaseDefaults('utf8mb4', 'utf8mb4_general_ci'));
        $this->assertTrue(Db18SchemaInspector::supportsDatabaseDefaults('utf8mb4', 'utf8mb4_unicode_ci'));
        $this->assertFalse(Db18SchemaInspector::supportsDatabaseDefaults('utf8', 'utf8_general_ci'));
        $this->assertFalse(Db18SchemaInspector::supportsDatabaseDefaults('utf8mb4', 'utf8mb4_bin'));
        $this->assertFalse(Db18SchemaInspector::supportsDatabaseDefaults('utf8mb4', 'utf8mb4_0900_ai_ci'));
    }
}
