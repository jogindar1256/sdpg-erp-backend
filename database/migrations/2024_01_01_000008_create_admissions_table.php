<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('student_applications')->cascadeOnDelete();
            $table->string('academic_year', 10);
            $table->integer('semester_no');
            $table->enum('admission_type', ['regular', 'back_paper', 'upgrade', 'lateral']);

            // Unique admission identifier
            $table->string('admission_no')->unique();
            $table->date('admission_date');

            // Authorization
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->enum('status', [
                'active',
                'cancelled',
                'on_hold',
                'passed_out',
                'transferred',
            ])->default('active');

            $table->text('cancel_reason')->nullable();
            $table->date('cancel_date')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'academic_year', 'status']);
            $table->index(['student_id', 'program_id', 'academic_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admissions');
    }
};
