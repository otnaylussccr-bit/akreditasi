<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accreditation_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requirement_id')->constrained('accreditation_document_requirements')->restrictOnDelete();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->string('original_name', 512);
            $t->string('disk', 64)->default('local');
            $t->string('storage_path', 1024);
            $t->uuid('storage_uuid')->unique();
            $t->string('mime_type', 128);
            $t->string('extension', 16);
            $t->unsignedBigInteger('size_bytes');
            $t->char('sha256', 64);
            $t->unsignedInteger('revision_number');
            $t->boolean('is_active')->default(true);
            $t->timestamp('uploaded_at')->useCurrent();
            $t->timestamp('superseded_at')->nullable();
            $t->timestamps();
            $t->index(['requirement_id', 'revision_number']);
        });
        Schema::create('accreditation_review_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requirement_id')->constrained('accreditation_document_requirements')->restrictOnDelete();
            $t->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('revision_number');
            $t->string('status', 32)->default('pending_review');
            $t->timestamp('submitted_at')->useCurrent();
            $t->timestamp('decided_at')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_note')->nullable();
            $t->timestamps();
            $t->unique(['requirement_id', 'revision_number'], 'accred_submission_revision_unique');
            $t->index(['status', 'submitted_at']);
        });
        Schema::create('accreditation_submission_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('submission_id')->constrained('accreditation_review_submissions')->restrictOnDelete();
            $t->foreignId('document_id')->constrained('accreditation_documents')->restrictOnDelete();
            $t->unique(['submission_id', 'document_id'], 'accred_snapshot_unique');
        });
        Schema::create('accreditation_review_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('submission_id')->constrained('accreditation_review_submissions')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 32); // submit / verified / need_revision
            $t->text('note')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('accreditation_activity_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('accreditation_projects')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('event', 100);
            $t->string('subject_type', 160)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->ipAddress('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['project_id', 'created_at']);
            $t->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accreditation_activity_logs');
        Schema::dropIfExists('accreditation_review_events');
        Schema::dropIfExists('accreditation_submission_documents');
        Schema::dropIfExists('accreditation_review_submissions');
        Schema::dropIfExists('accreditation_documents');
    }
};
