<?php

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
    }
}
