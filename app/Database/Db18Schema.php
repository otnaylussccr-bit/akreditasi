<?php

namespace App\Database;

use RuntimeException;

final class Db18Schema
{
    public const SHA256 = 'b9d639eb495324a84fe27160582527f6353843eb477365079fa6f5230f5ca097';

    public const MIGRATIONS = [
        '2026_10_19_000001_add_db18_scope_keys_to_phase_one_tables',
        '2026_10_19_000002_create_db18_catalog_and_organization_tables',
        '2026_10_19_000003_create_db18_cycle_and_assessment_tables',
        '2026_10_19_000004_create_db18_revision_evidence_and_improvement_tables',
        '2026_10_19_000005_add_db18_assessment_revision_pointer_foreign_keys',
    ];

    public const TABLES = [
        'qa_organizational_units',
        'qa_study_program_unit_assignments',
        'accreditation_led_instrument_versions',
        'accreditation_led_indicators',
        'accreditation_led_rubrics',
        'accreditation_led_assessment_cycles',
        'accreditation_led_reporting_cycles',
        'accreditation_led_cycle_events',
        'accreditation_led_assessor_assignments',
        'accreditation_led_assessments',
        'accreditation_led_assessment_revisions',
        'accreditation_led_review_events',
        'accreditation_led_indicator_item_mappings',
        'accreditation_led_assessment_evidence',
        'accreditation_led_evidence_exceptions',
        'accreditation_led_evidence_exception_events',
        'qa_improvement_actions',
        'qa_improvement_action_events',
        'accreditation_led_improvement_action_links',
        'accreditation_led_final_snapshots',
    ];

    /** @return list<string> */
    public static function statements(): array
    {
        $path = base_path('AMS_APU_DB18_LOCKED_DDL.sql');

        if (! is_file($path)) {
            throw new RuntimeException('DB-18 authoritative SQL file is missing. No schema changes were made.');
        }

        $sql = file_get_contents($path);

        if ($sql === false || hash('sha256', $sql) !== self::SHA256) {
            throw new RuntimeException('DB-18 authoritative SQL checksum mismatch. No schema changes were made.');
        }

        $withoutComments = preg_replace('/^\s*--[^\r\n]*$/m', '', $sql);

        if (! is_string($withoutComments)) {
            throw new RuntimeException('DB-18 SQL comments could not be parsed.');
        }

        $statements = array_values(array_filter(
            array_map('trim', explode(';', $withoutComments)),
            static fn (string $statement): bool => $statement !== '',
        ));

        if (count($statements) !== 21) {
            throw new RuntimeException('DB-18 SQL must contain exactly 20 CREATE TABLE statements and one circular-FK ALTER.');
        }

        foreach (self::TABLES as $index => $table) {
            if (! preg_match('/^CREATE TABLE `'.preg_quote($table, '/').'`\s*\(/i', $statements[$index])) {
                throw new RuntimeException("Unexpected DB-18 statement order at table {$table}.");
            }
        }

        if (! preg_match('/^ALTER TABLE `accreditation_led_assessments`\s+ADD CONSTRAINT `fk_led_approved_ptr`/i', $statements[20])) {
            throw new RuntimeException('DB-18 circular foreign-key statement is missing or changed.');
        }

        return $statements;
    }
}
