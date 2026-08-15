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

            // Multi-step form data — one JSONB blob per step, written by
            // updatePart()/read back by show(). An earlier, superseded
            // naming scheme (part_personal, part_education, ...) briefly
            // existed and was fully dropped; part_1..part_8 is the only
            // live naming scheme.
            //   part_1 = Course Selection / Personal Details
            //   part_2 = Address & Communication
            //   part_3 = Educational Details
            //   part_4 = TC & Migration Details
            //   part_5 = Bank Details
            //   part_6 = Subject & Paper Selection
            //   part_7 = Upload Photo, Signature & Documents
            //   part_8 = Shapath Patr / Declaration
            $table->jsonb('part_1')->nullable();
            $table->jsonb('part_2')->nullable();
            $table->jsonb('part_3')->nullable();
            $table->jsonb('part_4')->nullable();
            $table->jsonb('part_5')->nullable();
            $table->jsonb('part_6')->nullable();
            $table->jsonb('part_7')->nullable();
            $table->jsonb('part_8')->nullable();

            // Back-paper fee / payment tracking
            $table->decimal('fee_amount', 10, 2)->nullable();
            $table->boolean('fee_paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_ref', 100)->nullable();      // Razorpay payment id once captured
            $table->string('razorpay_order_id', 100)->nullable();
            // NOTE: no DB-level FK to fee_receipts — fee_receipts is created
            // by a later migration and this table must keep its original
            // creation timestamp/order.
            $table->unsignedBigInteger('fee_receipt_id')->nullable();

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
