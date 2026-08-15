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

            // Approval workflow (distinct from the is_verified/verified_by
            // document-verification pair above)
            $table->boolean('documents_verified')->default(false);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            // Fee / payment tracking
            // Final default is 'pending' — an earlier migration set it to
            // 'paid' for college-created admissions, then a follow-up
            // migration corrected the default (and backfilled any rows that
            // were wrongly marked paid with no actual payment) to 'pending'.
            $table->string('payment_status', 10)->default('pending');
            $table->string('fee_status', 20)->default('Pending');
            $table->string('razorpay_order_id', 100)->nullable();
            $table->string('razorpay_payment_id', 100)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('temp_password_plain', 30)->nullable(); // cleared after welcome email is sent

            // Admission-numbering scheme (AdmissionNumberService)
            $table->string('file_no', 20)->nullable()->index();      // "2425/00001"
            $table->unsignedInteger('record_no')->nullable()->index(); // global ever-incrementing record number

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
