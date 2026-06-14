<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->string('academic_year', 10);
            $table->string('application_no')->unique();
            $table->enum('application_type', ['fresh', 'back_paper', 'semester_upgrade', 'lateral']);
            $table->integer('semester_no')->default(1);

            // Subjects selected (stored as JSON array of subject_ids)
            $table->jsonb('selected_subjects')->nullable();
            $table->jsonb('selected_optional_subjects')->nullable();

            // Shapath Patr (declaration)
            $table->boolean('declaration_accepted')->default(false);
            $table->timestamp('declaration_at')->nullable();

            // Status flow
            $table->enum('status', [
                'draft',           // student filling
                'submitted',       // submitted by student
                'under_review',    // office reviewing
                'approved',        // office approved
                'rejected',        // office rejected
                'on_hold',         // admin hold
                'cancelled',       // cancelled
            ])->default('draft');

            $table->text('rejection_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Form completion tracking (parts 1-10)
            $table->jsonb('form_progress')->default('{}'); // {part1: true, part2: false ...}

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'academic_year', 'status']);
            $table->index(['student_id', 'academic_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_applications');
    }
};
