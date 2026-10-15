<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_programs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('accreditation_instruments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->string('accreditation_body', 160)->nullable();
            $table->string('version', 80)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('accreditation_projects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->foreignId('study_program_id')->constrained('study_programs')->restrictOnDelete();
            $table->foreignId('instrument_id')->nullable()->constrained('accreditation_instruments')->restrictOnDelete();
            $table->date('final_deadline');
            $table->string('status', 32)->default('active');
            $table->string('source_file_name', 512)->nullable();
            $table->timestamp('structure_locked_at')->nullable();
            $table->timestamps();
            $table->index(['study_program_id', 'status'], 'accred_project_program_status_idx');
        });

        Schema::create('accreditation_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('accreditation_projects')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->char('color_header', 7);
            $table->char('color_body', 7);
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->unique(['project_id', 'code'], 'accred_criteria_project_code_uq');
            $table->unique(['project_id', 'position'], 'accred_criteria_project_pos_uq');
        });

        Schema::create('accreditation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('criterion_id')->constrained('accreditation_criteria')->restrictOnDelete();
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
            $table->unique(['criterion_id', 'number'], 'accred_item_criterion_number_uq');
            $table->index(['criterion_id', 'position'], 'accred_item_criterion_pos_idx');
        });

        Schema::create('accreditation_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('accreditation_items')->restrictOnDelete();
            $table->unsignedSmallInteger('number');
            $table->text('title');
            $table->boolean('is_required')->default(true);
            $table->string('workflow_status', 32)->default('not_started');
            $table->unsignedInteger('current_review_revision')->default(0);
            $table->timestamps();
            $table->unique(['item_id', 'number'], 'accred_req_item_number_uq');
            $table->index(['item_id', 'workflow_status'], 'accred_req_item_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accreditation_document_requirements');
        Schema::dropIfExists('accreditation_items');
        Schema::dropIfExists('accreditation_criteria');
        Schema::dropIfExists('accreditation_projects');
        Schema::dropIfExists('accreditation_instruments');
        Schema::dropIfExists('study_programs');
    }
};
