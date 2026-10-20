<?php

namespace Tests;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected string $testLocale = 'en';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => $this->testLocale]);
        app()->setLocale($this->testLocale);
        Carbon::setLocale($this->testLocale);

        if (config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Tests must use the isolated SQLite in-memory database.');
        }

        $this->createTestingSchema();
    }

    private function createTestingSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamp('deactivated_at')->nullable();
            $table->unsignedBigInteger('deactivated_by')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('administrative_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name');
            $table->string('event', 100);
            $table->string('subject_type', 160);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('study_programs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->boolean('is_active');
            $table->timestamps();
        });

        Schema::create('accreditation_instruments', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->string('accreditation_body', 160)->nullable();
            $table->string('version', 80)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('accreditation_projects', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->unsignedBigInteger('study_program_id');
            $table->unsignedBigInteger('instrument_id')->nullable();
            $table->date('final_deadline');
            $table->string('status', 32);
            $table->string('source_file_name', 512)->nullable();
            $table->timestamp('structure_locked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('accreditation_criteria', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('code', 32);
            $table->string('name');
            $table->char('color_header', 7);
            $table->char('color_body', 7);
            $table->unsignedSmallInteger('position');
            $table->timestamps();
        });

        Schema::create('accreditation_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('criterion_id');
            $table->unsignedSmallInteger('number');
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('source_row')->nullable();
            $table->text('title');
            $table->date('source_deadline')->nullable();
            $table->date('target_deadline')->nullable();
            $table->string('source_day', 32)->nullable();
            $table->text('source_pic')->nullable();
            $table->string('source_status', 64)->nullable();
            $table->longText('source_note')->nullable();
            $table->longText('source_aux_note')->nullable();
            $table->longText('source_detail_text')->nullable();
            $table->timestamps();
        });

        Schema::create('accreditation_document_requirements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->unsignedSmallInteger('number');
            $table->text('title');
            $table->boolean('is_required');
            $table->string('workflow_status', 32);
            $table->unsignedInteger('current_review_revision');
            $table->timestamps();
        });

        Schema::create('accreditation_project_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id');
            $table->string('membership_role', 32);
            $table->boolean('is_active');
            $table->timestamps();
            $table->unique(['project_id', 'user_id', 'membership_role']);
        });

        Schema::create('accreditation_criterion_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('criterion_id');
            $table->unsignedBigInteger('user_id');
            $table->string('assignment_role', 24);
            $table->boolean('is_active');
            $table->timestamps();
            $table->unique(['criterion_id', 'user_id', 'assignment_role']);
        });

        Schema::create('accreditation_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('requirement_id');
            $table->unsignedBigInteger('uploaded_by');
            $table->string('original_name', 512);
            $table->string('disk', 64);
            $table->string('storage_path', 1024);
            $table->uuid('storage_uuid')->unique();
            $table->string('mime_type', 128);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedInteger('revision_number');
            $table->boolean('is_active');
            $table->timestamp('uploaded_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('accreditation_review_submissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('requirement_id');
            $table->unsignedBigInteger('submitted_by');
            $table->unsignedInteger('revision_number');
            $table->string('status', 32);
            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->unique(['requirement_id', 'revision_number']);
        });

        Schema::create('accreditation_submission_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('submission_id');
            $table->unsignedBigInteger('document_id');
            $table->unique(['submission_id', 'document_id']);
        });

        Schema::create('accreditation_review_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('submission_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('action', 32);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('accreditation_review_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('review_event_id');
            $table->uuid('storage_uuid')->unique();
            $table->string('storage_path', 1024);
            $table->string('original_filename', 255);
            $table->string('mime_type', 191);
            $table->string('extension', 20);
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->timestamp('uploaded_at');
            $table->timestamps();
            $table->index(['review_event_id', 'uploaded_at']);
        });

        Schema::create('accreditation_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event', 100);
            $table->string('subject_type', 160)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('qa_organizational_units', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('unit_code', 80)->unique();
            $table->string('unit_name');
            $table->string('unit_type', 48);
            $table->unsignedBigInteger('parent_unit_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('source_decision_ref')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('delete_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('qa_study_program_unit_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('study_program_id');
            $table->unsignedBigInteger('unit_id');
            $table->string('relationship_type', 32);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('decision_ref');
            $table->unsignedBigInteger('created_by');
            $table->timestamp('created_at');
        });

        Schema::create('accreditation_led_instrument_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('instrument_id');
            $table->string('version_code', 80);
            $table->string('assessment_pathway', 64);
            $table->string('education_level', 32);
            $table->string('source_title');
            $table->string('source_reference');
            $table->date('source_effective_date')->nullable();
            $table->string('source_filename', 512);
            $table->string('source_disk', 64);
            $table->string('source_storage_path', 1024);
            $table->char('source_pdf_sha256', 64);
            $table->char('rubric_manifest_sha256', 64)->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['instrument_id', 'version_code']);
        });

        Schema::create('accreditation_led_indicators', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('instrument_version_id');
            $table->unsignedSmallInteger('indicator_number');
            $table->string('criterion_code', 24);
            $table->string('title', 512);
            $table->longText('description')->nullable();
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('page_start')->nullable();
            $table->unsignedSmallInteger('page_end')->nullable();
            $table->boolean('is_assessable')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->unique(['instrument_version_id', 'indicator_number']);
            $table->unique(['instrument_version_id', 'position']);
        });

        Schema::create('accreditation_led_rubrics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('indicator_id');
            $table->string('category_code', 32);
            $table->string('category_label', 80);
            $table->decimal('internal_score', 6, 2);
            $table->longText('description');
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('page_start')->nullable();
            $table->unsignedSmallInteger('page_end')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['indicator_id', 'category_code']);
            $table->unique(['indicator_id', 'position']);
        });

        Schema::create('accreditation_led_assessment_cycles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('instrument_id');
            $table->unsignedBigInteger('instrument_version_id');
            $table->unsignedInteger('cycle_number');
            $table->string('cycle_name');
            $table->string('status', 24)->default('draft');
            $table->json('organization_snapshot_json')->nullable();
            $table->char('organization_snapshot_sha256', 64)->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('finalized_by')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['project_id', 'cycle_number']);
        });

        Schema::create('accreditation_led_cycle_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cycle_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('action', 64);
            $table->string('old_state', 32)->nullable();
            $table->string('new_state', 32)->nullable();
            $table->json('event_payload_json')->nullable();
            $table->text('reason');
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('occurred_at');
        });

        Schema::create('accreditation_led_assessor_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cycle_id');
            $table->unsignedBigInteger('instrument_version_id');
            $table->unsignedBigInteger('indicator_id');
            $table->unsignedBigInteger('user_id');
            $table->string('assignment_role', 24);
            $table->unsignedBigInteger('assigned_by');
            $table->timestamp('assigned_at');
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
        });

        Schema::create('accreditation_led_assessments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cycle_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('instrument_version_id');
            $table->unsignedBigInteger('indicator_id');
            $table->unsignedBigInteger('current_approved_revision_id')->nullable();
            $table->unsignedBigInteger('current_working_revision_id')->nullable();
            $table->timestamps();
            $table->unique(['cycle_id', 'indicator_id']);
        });

        Schema::create('accreditation_led_assessment_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assessment_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('indicator_id');
            $table->unsignedInteger('revision_number');
            $table->unsignedBigInteger('rubric_id')->nullable();
            $table->longText('justification')->nullable();
            $table->longText('gap_analysis')->nullable();
            $table->longText('recommendation')->nullable();
            $table->string('status', 24)->default('draft');
            $table->unsignedBigInteger('created_by');
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamps();
            $table->unique(['assessment_id', 'revision_number']);
        });

        Schema::create('accreditation_led_review_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('revision_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('action', 64);
            $table->string('from_state', 24)->nullable();
            $table->string('to_state', 24)->nullable();
            $table->text('decision_note')->nullable();
            $table->json('payload_json')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('occurred_at');
        });

        Schema::create('accreditation_led_indicator_item_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cycle_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('instrument_version_id');
            $table->unsignedBigInteger('indicator_id');
            $table->unsignedBigInteger('criterion_id');
            $table->unsignedBigInteger('item_id');
            $table->string('mapping_role', 32)->default('supporting');
            $table->unsignedBigInteger('created_by');
            $table->timestamp('created_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->text('revoke_reason')->nullable();
        });

        Schema::create('accreditation_led_assessment_evidence', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('revision_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('verification_submission_id')->nullable();
            $table->unsignedInteger('document_revision_number');
            $table->char('document_sha256', 64);
            $table->string('evidence_role', 32);
            $table->text('note')->nullable();
            $table->unsignedBigInteger('linked_by');
            $table->timestamp('linked_at');
            $table->unique(['revision_id', 'document_id']);
        });
    }
}
