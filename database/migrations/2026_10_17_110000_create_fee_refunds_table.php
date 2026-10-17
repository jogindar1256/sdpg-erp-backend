<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refund register — one row per refund owed:
 *  - receipt_cancel:   a fee receipt rejected / cancelled before admission
 *  - admission_cancel: an admission cancelled after the fee was paid
 *                      (refund = paid − cancellation charge)
 * Stays 'pending' until the office records the refund reference.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('fee_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('source', 30); // receipt_cancel | admission_cancel
            $table->unsignedBigInteger('fee_receipt_id')->nullable()->index();
            $table->unsignedBigInteger('admission_id')->nullable()->index();
            $table->unsignedBigInteger('student_application_id')->nullable()->index();
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('academic_year', 12)->nullable();
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->decimal('deduction', 10, 2)->default(0);
            $table->decimal('refund_amount', 10, 2)->default(0);
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending')->index(); // pending | refunded
            $table->string('refund_ref', 100)->nullable();
            $table->string('refund_mode', 30)->nullable();
            $table->text('refund_remarks')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('refunded_by')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_refunds');
    }
};
