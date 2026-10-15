<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accreditation_project_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('accreditation_projects')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('membership_role', 32)->default('viewer'); // project_manager, reviewer, pic, viewer
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['project_id', 'user_id', 'membership_role'], 'accred_member_unique');
        });
        Schema::create('accreditation_criterion_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('criterion_id')->constrained('accreditation_criteria')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('assignment_role', 24); // pic / reviewer
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['criterion_id', 'user_id', 'assignment_role'], 'accred_assignment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accreditation_criterion_assignments');
        Schema::dropIfExists('accreditation_project_members');
    }
};
