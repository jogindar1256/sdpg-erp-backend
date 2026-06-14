<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semester_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->string('academic_year', 10);
            $table->integer('semester_no');
            $table->enum('registration_type', ['fresh', 'ex_student', 'back_paper']);

            $table->string('registration_no')->unique();
            $table->date('registration_date');

            // Subjects registered (JSONB array of subject ids)
            $table->jsonb('registered_subjects');
            $table->jsonb('back_paper_subjects')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->unique(['student_id', 'academic_year', 'semester_no'], 'unique_sem_reg');
            $table->index(['organization_id', 'academic_year', 'semester_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('semester_registrations');
    }
};
