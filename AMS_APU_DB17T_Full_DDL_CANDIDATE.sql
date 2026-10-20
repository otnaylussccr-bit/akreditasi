-- DB-17T SQL DDL CANDIDATE (STATIC ONLY / NOT EXECUTED / NOT LOCKED)
-- MariaDB 10.4.32 target; NO CREATE/DROP SCHEMA; NO SET foreign_key_checks=0
-- Only use in newly provisioned ISOLATED local test schema after separate approval.
-- Database must contain compatible fixture definitions for existing Phase 1 parent tables.
-- Do not execute against akreditasi_db, Production, or ams_db10_constraint_lab.

-- Existing Phase 1 prerequisite indexes -- REVIEW ONLY, NOT EXECUTED IN THIS FILE
-- ALTER TABLE `accreditation_projects` ADD UNIQUE KEY `uq_led_project_instrument` (`id`,`instrument_id`);
-- ALTER TABLE `accreditation_criteria` ADD UNIQUE KEY `uq_led_criterion_project` (`id`,`project_id`);
-- ALTER TABLE `accreditation_items` ADD UNIQUE KEY `uq_led_item_criterion` (`id`,`criterion_id`);

-- qa_organizational_units
CREATE TABLE `qa_organizational_units` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `unit_code` VARCHAR(80) NOT NULL,
  `unit_name` VARCHAR(255) NOT NULL,
  `unit_type` VARCHAR(48) NOT NULL,
  `parent_unit_id` BIGINT UNSIGNED NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `valid_from` DATE NOT NULL,
  `valid_until` DATE NULL,
  `source_decision_ref` VARCHAR(255) NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `deleted_at` DATETIME(6) NULL,
  `deleted_by` BIGINT UNSIGNED NULL,
  `delete_reason` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_01_uuid` (`uuid`),
  UNIQUE KEY `uq_01_code` (`unit_code`),
  KEY `ix_01_deleted_at` (`deleted_at`),
  KEY `ix_01_parent` (`parent_unit_id`),
  CONSTRAINT `fk_01_parent` FOREIGN KEY (`parent_unit_id`) REFERENCES `qa_organizational_units` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_01_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_01_deleter` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_01_valid_period` CHECK (`valid_until` IS NULL OR `valid_until` >= `valid_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- qa_study_program_unit_assignments
CREATE TABLE `qa_study_program_unit_assignments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `study_program_id` BIGINT UNSIGNED NOT NULL,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  `relationship_type` VARCHAR(32) NOT NULL,
  `valid_from` DATE NOT NULL,
  `valid_until` DATE NULL,
  `decision_ref` VARCHAR(255) NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_02_assignment` (`study_program_id`, `unit_id`, `relationship_type`, `valid_from`),
  KEY `ix_02_validity` (`study_program_id`, `relationship_type`, `valid_from`),
  CONSTRAINT `fk_02_program` FOREIGN KEY (`study_program_id`) REFERENCES `study_programs` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_02_unit` FOREIGN KEY (`unit_id`) REFERENCES `qa_organizational_units` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_02_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_02_valid_period` CHECK (`valid_until` IS NULL OR `valid_until` >= `valid_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accreditation_led_instrument_versions
CREATE TABLE `accreditation_led_instrument_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instrument_id` BIGINT UNSIGNED NOT NULL,
  `version_code` VARCHAR(80) NOT NULL,
  `assessment_pathway` VARCHAR(64) NOT NULL,
  `education_level` VARCHAR(32) NOT NULL,
  `source_title` VARCHAR(255) NOT NULL,
  `source_reference` VARCHAR(255) NOT NULL,
  `source_effective_date` DATE NULL,
  `source_filename` VARCHAR(512) NOT NULL,
  `source_disk` VARCHAR(64) NOT NULL,
  `source_storage_path` VARCHAR(1024) NOT NULL,
  `source_pdf_sha256` CHAR(64) NOT NULL,
  `rubric_manifest_sha256` CHAR(64) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
  `published_by` BIGINT UNSIGNED NULL,
  `published_at` DATETIME(6) NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_03_version` (`instrument_id`, `version_code`),
  UNIQUE KEY `uq_03_master` (`id`, `instrument_id`),
  CONSTRAINT `fk_03_master` FOREIGN KEY (`instrument_id`) REFERENCES `accreditation_instruments` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_03_publisher` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_03_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_03_status` CHECK (`status` IN ('draft','published','retired'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accreditation_led_indicators
CREATE TABLE `accreditation_led_indicators` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instrument_version_id` BIGINT UNSIGNED NOT NULL,
  `indicator_number` SMALLINT UNSIGNED NOT NULL,
  `criterion_code` VARCHAR(24) NOT NULL,
  `title` VARCHAR(512) NOT NULL,
  `description` LONGTEXT NULL,
  `position` SMALLINT UNSIGNED NOT NULL,
  `page_start` SMALLINT UNSIGNED NULL,
  `page_end` SMALLINT UNSIGNED NULL,
  `is_assessable` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_04_number` (`instrument_version_id`, `indicator_number`),
  UNIQUE KEY `uq_04_position` (`instrument_version_id`, `position`),
  UNIQUE KEY `uq_04_version` (`id`, `instrument_version_id`),
  CONSTRAINT `fk_04_version` FOREIGN KEY (`instrument_version_id`) REFERENCES `accreditation_led_instrument_versions` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_04_pages` CHECK (`page_end` IS NULL OR (`page_start` IS NOT NULL AND `page_end`>=`page_start`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accreditation_led_rubrics
CREATE TABLE `accreditation_led_rubrics` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `indicator_id` BIGINT UNSIGNED NOT NULL,
  `category_code` VARCHAR(32) NOT NULL,
  `category_label` VARCHAR(80) NOT NULL,
  `internal_score` DECIMAL(6,2) NOT NULL,
  `description` LONGTEXT NOT NULL,
  `position` SMALLINT UNSIGNED NOT NULL,
  `page_start` SMALLINT UNSIGNED NULL,
  `page_end` SMALLINT UNSIGNED NULL,
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_05_category` (`indicator_id`, `category_code`),
  UNIQUE KEY `uq_05_position` (`indicator_id`, `position`),
  UNIQUE KEY `uq_05_indicator` (`id`, `indicator_id`),
  CONSTRAINT `fk_05_indicator` FOREIGN KEY (`indicator_id`) REFERENCES `accreditation_led_indicators` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_05_score` CHECK (`internal_score`>=0),
  CONSTRAINT `ck_05_pages` CHECK (`page_end` IS NULL OR (`page_start` IS NOT NULL AND `page_end`>=`page_start`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accreditation_led_assessment_cycles
CREATE TABLE `accreditation_led_assessment_cycles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `instrument_id` BIGINT UNSIGNED NOT NULL,
  `instrument_version_id` BIGINT UNSIGNED NOT NULL,
  `cycle_number` INT UNSIGNED NOT NULL,
  `cycle_name` VARCHAR(255) NOT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'draft',
  `organization_snapshot_json` LONGTEXT NULL,
  `organization_snapshot_sha256` CHAR(64) NULL,
  `activated_by` BIGINT UNSIGNED NULL,
  `activated_at` DATETIME(6) NULL,
  `closed_by` BIGINT UNSIGNED NULL,
  `closed_at` DATETIME(6) NULL,
  `finalized_by` BIGINT UNSIGNED NULL,
  `finalized_at` DATETIME(6) NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_06_number` (`project_id`, `cycle_number`),
  UNIQUE KEY `uq_06_project` (`project_id`, `id`),
  UNIQUE KEY `uq_06_version` (`id`, `instrument_version_id`),
  UNIQUE KEY `uq_06_scope` (`id`, `project_id`, `instrument_version_id`),
  UNIQUE KEY `uq_06_id_project` (`id`, `project_id`),
  CONSTRAINT `fk_06_project_instrument` FOREIGN KEY (`project_id`, `instrument_id`) REFERENCES `accreditation_projects` (`id`, `instrument_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_06_version_master` FOREIGN KEY (`instrument_version_id`, `instrument_id`) REFERENCES `accreditation_led_instrument_versions` (`id`, `instrument_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_06_activated` FOREIGN KEY (`activated_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_06_closed` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_06_finalized` FOREIGN KEY (`finalized_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_06_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_06_status` CHECK (`status` IN ('draft','active','closed','finalized')),
  CONSTRAINT `ck_06_organization_json` CHECK (`organization_snapshot_json` IS NULL OR JSON_VALID(`organization_snapshot_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accreditation_led_reporting_cycles
CREATE TABLE `accreditation_led_reporting_cycles` (
  `project_id` BIGINT UNSIGNED NOT NULL,
  `cycle_id` BIGINT UNSIGNED NOT NULL,
  `changed_by` BIGINT UNSIGNED NOT NULL,
  `changed_at` DATETIME(6) NOT NULL,
  `reason` TEXT NOT NULL,
  PRIMARY KEY (`project_id`),
  CONSTRAINT `fk_07_cycle` FOREIGN KEY (`project_id`, `cycle_id`) REFERENCES `accreditation_led_assessment_cycles` (`project_id`, `id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_07_changer` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accreditation_led_cycle_events
CREATE TABLE `accreditation_led_cycle_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id` BIGINT UNSIGNED NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `action` VARCHAR(64) NOT NULL,
  `old_state` VARCHAR(32) NULL,
  `new_state` VARCHAR(32) NULL,
  `event_payload_json` LONGTEXT NULL,
  `reason` TEXT NOT NULL,
  `correlation_id` CHAR(36) NULL,
  `occurred_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_08_cycle_time` (`cycle_id`, `occurred_at`, `id`),
  CONSTRAINT `fk_08_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `accreditation_led_assessment_cycles` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_08_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_08_json` CHECK (`event_payload_json` IS NULL OR JSON_VALID(`event_payload_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accreditation_led_assessor_assignments
CREATE TABLE `accreditation_led_assessor_assignments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id` BIGINT UNSIGNED NOT NULL,
  `instrument_version_id` BIGINT UNSIGNED NOT NULL,
  `indicator_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `assignment_role` VARCHAR(24) NOT NULL,
  `assigned_by` BIGINT UNSIGNED NOT NULL,
  `assigned_at` DATETIME(6) NOT NULL,
  `revoked_by` BIGINT UNSIGNED NULL,
  `revoked_at` DATETIME(6) NULL,
  `revocation_reason` TEXT NULL,
  `active_primary_indicator_id` BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `assignment_role` = 'assessor_primary' AND `revoked_at` IS NULL THEN `indicator_id` ELSE NULL END) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_09_active_primary` (`cycle_id`, `active_primary_indicator_id`),
  KEY `ix_09_scope` (`cycle_id`, `indicator_id`, `user_id`, `assignment_role`, `revoked_at`),
  CONSTRAINT `fk_09_cycle_version` FOREIGN KEY (`cycle_id`, `instrument_version_id`) REFERENCES `accreditation_led_assessment_cycles` (`id`, `instrument_version_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_09_indicator_version` FOREIGN KEY (`indicator_id`, `instrument_version_id`) REFERENCES `accreditation_led_indicators` (`id`, `instrument_version_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_09_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_09_assigner` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_09_revoker` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_09_role` CHECK (`assignment_role` IN ('assessor_primary','assessor_support','reviewer')),
  CONSTRAINT `ck_09_revoke` CHECK (`revoked_at` IS NULL OR `revoked_at`>=`assigned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accreditation_led_assessments
CREATE TABLE `accreditation_led_assessments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id` BIGINT UNSIGNED NOT NULL,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `instrument_version_id` BIGINT UNSIGNED NOT NULL,
  `indicator_id` BIGINT UNSIGNED NOT NULL,
  `current_approved_revision_id` BIGINT UNSIGNED NULL,
  `current_working_revision_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_10_cycle_indicator` (`cycle_id`, `indicator_id`),
  UNIQUE KEY `uq_10_indicator` (`id`, `indicator_id`),
  UNIQUE KEY `uq_10_scope` (`id`, `project_id`, `indicator_id`),
  UNIQUE KEY `uq_10_project` (`id`, `project_id`),
  CONSTRAINT `fk_10_cycle_scope` FOREIGN KEY (`cycle_id`, `project_id`, `instrument_version_id`) REFERENCES `accreditation_led_assessment_cycles` (`id`, `project_id`, `instrument_version_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_10_indicator_version` FOREIGN KEY (`indicator_id`, `instrument_version_id`) REFERENCES `accreditation_led_indicators` (`id`, `instrument_version_id`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accreditation_led_assessment_revisions
CREATE TABLE `accreditation_led_assessment_revisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assessment_id` BIGINT UNSIGNED NOT NULL,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `indicator_id` BIGINT UNSIGNED NOT NULL,
  `revision_number` INT UNSIGNED NOT NULL,
  `rubric_id` BIGINT UNSIGNED NULL,
  `justification` LONGTEXT NULL,
  `gap_analysis` LONGTEXT NULL,
  `recommendation` LONGTEXT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'draft',
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `submitted_by` BIGINT UNSIGNED NULL,
  `submitted_at` DATETIME(6) NULL,
  `decided_by` BIGINT UNSIGNED NULL,
  `decided_at` DATETIME(6) NULL,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_11_revision` (`assessment_id`, `revision_number`),
  UNIQUE KEY `uq_11_assessment` (`assessment_id`, `id`),
  UNIQUE KEY `uq_11_project` (`project_id`, `id`),
  UNIQUE KEY `uq_11_id_project` (`id`, `project_id`),
  CONSTRAINT `fk_11_assessment_scope` FOREIGN KEY (`assessment_id`, `project_id`, `indicator_id`) REFERENCES `accreditation_led_assessments` (`id`, `project_id`, `indicator_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_11_rubric_scope` FOREIGN KEY (`rubric_id`, `indicator_id`) REFERENCES `accreditation_led_rubrics` (`id`, `indicator_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_11_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_11_submitted` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_11_decided` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_11_status` CHECK (`status` IN ('draft','submitted','in_review','revision_requested','approved'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accreditation_led_review_events
CREATE TABLE `accreditation_led_review_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `revision_id` BIGINT UNSIGNED NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `action` VARCHAR(64) NOT NULL,
  `from_state` VARCHAR(24) NULL,
  `to_state` VARCHAR(24) NULL,
  `decision_note` TEXT NULL,
  `payload_json` LONGTEXT NULL,
  `correlation_id` CHAR(36) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(512) NULL,
  `occurred_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_12_revision_time` (`revision_id`, `occurred_at`, `id`),
  CONSTRAINT `fk_12_revision` FOREIGN KEY (`revision_id`) REFERENCES `accreditation_led_assessment_revisions` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_12_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_12_json` CHECK (`payload_json` IS NULL OR JSON_VALID(`payload_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accreditation_led_indicator_item_mappings
CREATE TABLE `accreditation_led_indicator_item_mappings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id` BIGINT UNSIGNED NOT NULL,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `instrument_version_id` BIGINT UNSIGNED NOT NULL,
  `indicator_id` BIGINT UNSIGNED NOT NULL,
  `criterion_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `mapping_role` VARCHAR(32) NOT NULL DEFAULT 'supporting',
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `revoked_at` DATETIME(6) NULL,
  `revoked_by` BIGINT UNSIGNED NULL,
  `revoke_reason` TEXT NULL,
  `active_item_id` BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `revoked_at` IS NULL THEN `item_id` ELSE NULL END) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_13_mapping_active` (`cycle_id`, `indicator_id`, `active_item_id`),
  KEY `ix_13_item_cycle` (`item_id`, `cycle_id`),
  CONSTRAINT `fk_13_cycle_scope` FOREIGN KEY (`cycle_id`, `project_id`, `instrument_version_id`) REFERENCES `accreditation_led_assessment_cycles` (`id`, `project_id`, `instrument_version_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_13_indicator_version` FOREIGN KEY (`indicator_id`, `instrument_version_id`) REFERENCES `accreditation_led_indicators` (`id`, `instrument_version_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_13_item_criterion` FOREIGN KEY (`item_id`, `criterion_id`) REFERENCES `accreditation_items` (`id`, `criterion_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_13_criterion_project` FOREIGN KEY (`criterion_id`, `project_id`) REFERENCES `accreditation_criteria` (`id`, `project_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_13_revoker` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_13_revocation` CHECK (`revoked_at` IS NULL OR (`revoked_by` IS NOT NULL AND `revoke_reason` IS NOT NULL AND `revoked_at` >= `created_at`)),
  CONSTRAINT `fk_13_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accreditation_led_assessment_evidence
CREATE TABLE `accreditation_led_assessment_evidence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `revision_id` BIGINT UNSIGNED NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `verification_submission_id` BIGINT UNSIGNED NULL,
  `document_revision_number` INT UNSIGNED NOT NULL,
  `document_sha256` CHAR(64) NOT NULL,
  `evidence_role` VARCHAR(32) NOT NULL,
  `note` TEXT NULL,
  `linked_by` BIGINT UNSIGNED NOT NULL,
  `linked_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_14_revision_doc` (`revision_id`, `document_id`),
  UNIQUE KEY `uq_14_revision_id` (`revision_id`, `id`),
  KEY `ix_14_verified_pair` (`verification_submission_id`, `document_id`),
  CONSTRAINT `fk_14_revision` FOREIGN KEY (`revision_id`) REFERENCES `accreditation_led_assessment_revisions` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_14_document` FOREIGN KEY (`document_id`) REFERENCES `accreditation_documents` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_14_submission_pair` FOREIGN KEY (`verification_submission_id`, `document_id`) REFERENCES `accreditation_submission_documents` (`submission_id`, `document_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_14_linker` FOREIGN KEY (`linked_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accreditation_led_evidence_exceptions
CREATE TABLE `accreditation_led_evidence_exceptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `revision_id` BIGINT UNSIGNED NOT NULL,
  `evidence_id` BIGINT UNSIGNED NULL,
  `exception_type` VARCHAR(48) NOT NULL,
  `reason` TEXT NOT NULL,
  `alternative_evidence_description` LONGTEXT NULL,
  `alternative_artifact_ref` VARCHAR(1024) NULL,
  `alternative_artifact_sha256` CHAR(64) NULL,
  `policy_reference` VARCHAR(255) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'requested',
  `requested_by` BIGINT UNSIGNED NOT NULL,
  `requested_at` DATETIME(6) NOT NULL,
  `decided_by` BIGINT UNSIGNED NULL,
  `decided_at` DATETIME(6) NULL,
  `decision_note` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_15_status` (`revision_id`, `status`),
  CONSTRAINT `fk_15_revision` FOREIGN KEY (`revision_id`) REFERENCES `accreditation_led_assessment_revisions` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_15_evidence_scope` FOREIGN KEY (`revision_id`, `evidence_id`) REFERENCES `accreditation_led_assessment_evidence` (`revision_id`, `id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_15_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_15_decider` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_15_status` CHECK (`status` IN ('requested','approved','rejected','withdrawn')),
  CONSTRAINT `ck_15_alternative` CHECK (`evidence_id` IS NOT NULL OR `alternative_evidence_description` IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accreditation_led_evidence_exception_events
CREATE TABLE `accreditation_led_evidence_exception_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exception_id` BIGINT UNSIGNED NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `action` VARCHAR(64) NOT NULL,
  `from_status` VARCHAR(20) NULL,
  `to_status` VARCHAR(20) NULL,
  `note` TEXT NULL,
  `payload_json` LONGTEXT NULL,
  `occurred_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_16_exception_time` (`exception_id`, `occurred_at`, `id`),
  CONSTRAINT `fk_16_exception` FOREIGN KEY (`exception_id`) REFERENCES `accreditation_led_evidence_exceptions` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_16_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_16_json` CHECK (`payload_json` IS NULL OR JSON_VALID(`payload_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- qa_improvement_actions
CREATE TABLE `qa_improvement_actions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `action_code` VARCHAR(80) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` LONGTEXT NOT NULL,
  `owner_unit_id` BIGINT UNSIGNED NOT NULL,
  `assigned_to` BIGINT UNSIGNED NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'open',
  `priority` VARCHAR(16) NOT NULL DEFAULT 'normal',
  `due_date` DATE NULL,
  `resolution_note` TEXT NULL,
  `effectiveness_verified_by` BIGINT UNSIGNED NULL,
  `effectiveness_verified_at` DATETIME(6) NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `deleted_at` DATETIME(6) NULL,
  `deleted_by` BIGINT UNSIGNED NULL,
  `delete_reason` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_17_uuid` (`uuid`),
  UNIQUE KEY `uq_17_code` (`action_code`),
  KEY `ix_17_deleted_at` (`deleted_at`),
  KEY `ix_17_owner_status_due` (`owner_unit_id`, `status`, `due_date`),
  CONSTRAINT `fk_17_owner` FOREIGN KEY (`owner_unit_id`) REFERENCES `qa_organizational_units` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_17_assignee` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_17_effectiveness` FOREIGN KEY (`effectiveness_verified_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_17_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_17_deleter` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_17_status` CHECK (`status` IN ('open','in_progress','resolved','closed','cancelled')),
  CONSTRAINT `ck_17_priority` CHECK (`priority` IN ('low','normal','high','critical'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- qa_improvement_action_events
CREATE TABLE `qa_improvement_action_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action_id` BIGINT UNSIGNED NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `action` VARCHAR(64) NOT NULL,
  `old_state` VARCHAR(24) NULL,
  `new_state` VARCHAR(24) NULL,
  `payload_json` LONGTEXT NULL,
  `reason` TEXT NOT NULL,
  `occurred_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_18_action_time` (`action_id`, `occurred_at`, `id`),
  CONSTRAINT `fk_18_action` FOREIGN KEY (`action_id`) REFERENCES `qa_improvement_actions` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_18_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_18_json` CHECK (`payload_json` IS NULL OR JSON_VALID(`payload_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accreditation_led_improvement_action_links
CREATE TABLE `accreditation_led_improvement_action_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `improvement_action_id` BIGINT UNSIGNED NOT NULL,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `source_revision_id` BIGINT UNSIGNED NOT NULL,
  `link_role` VARCHAR(32) NOT NULL DEFAULT 'origin',
  `linked_by` BIGINT UNSIGNED NOT NULL,
  `linked_at` DATETIME(6) NOT NULL,
  `revoked_at` DATETIME(6) NULL,
  `revoked_by` BIGINT UNSIGNED NULL,
  `revoke_reason` TEXT NULL,
  `active_source_revision_id` BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `revoked_at` IS NULL THEN `source_revision_id` ELSE NULL END) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_19_active_source` (`improvement_action_id`, `active_source_revision_id`, `link_role`),
  KEY `ix_19_project_revision` (`project_id`, `source_revision_id`),
  CONSTRAINT `fk_19_action` FOREIGN KEY (`improvement_action_id`) REFERENCES `qa_improvement_actions` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_19_revision_scope` FOREIGN KEY (`project_id`, `source_revision_id`) REFERENCES `accreditation_led_assessment_revisions` (`project_id`, `id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_19_revoker` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_19_revocation` CHECK (`revoked_at` IS NULL OR (`revoked_by` IS NOT NULL AND `revoke_reason` IS NOT NULL AND `revoked_at` >= `linked_at`)),
  CONSTRAINT `fk_19_linker` FOREIGN KEY (`linked_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accreditation_led_final_snapshots
CREATE TABLE `accreditation_led_final_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id` BIGINT UNSIGNED NOT NULL,
  `instrument_version_id` BIGINT UNSIGNED NOT NULL,
  `approved_indicator_count` SMALLINT UNSIGNED NOT NULL,
  `total_internal_score` DECIMAL(12,2) NOT NULL,
  `average_internal_score` DECIMAL(8,4) NULL,
  `manifest_json` LONGTEXT NOT NULL,
  `manifest_sha256` CHAR(64) NOT NULL,
  `archive_disk` VARCHAR(64) NULL,
  `archive_path` VARCHAR(1024) NULL,
  `archive_sha256` CHAR(64) NULL,
  `archive_status` VARCHAR(24) NOT NULL DEFAULT 'pending',
  `finalized_by` BIGINT UNSIGNED NOT NULL,
  `finalized_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_20_cycle` (`cycle_id`),
  CONSTRAINT `fk_20_cycle_version` FOREIGN KEY (`cycle_id`, `instrument_version_id`) REFERENCES `accreditation_led_assessment_cycles` (`id`, `instrument_version_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_20_finalizer` FOREIGN KEY (`finalized_by`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_20_manifest` CHECK (JSON_VALID(`manifest_json`)),
  CONSTRAINT `ck_20_archive_status` CHECK (`archive_status` IN ('pending','stored','failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Circular composite foreign keys, apply ONLY after T11 exists
ALTER TABLE `accreditation_led_assessments`
  ADD CONSTRAINT `fk_led_approved_ptr` FOREIGN KEY (`id`,`current_approved_revision_id`) REFERENCES `accreditation_led_assessment_revisions` (`assessment_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_led_working_ptr` FOREIGN KEY (`id`,`current_working_revision_id`) REFERENCES `accreditation_led_assessment_revisions` (`assessment_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT;
