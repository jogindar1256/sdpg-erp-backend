<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 of the payments-simplification project: the `payments` table is
 * now the single source of truth for every payment fact, and every
 * controller reads it directly (via App\Support\Payments helpers or a
 * join) instead of these columns. Phase 3a (see 2026_10_18_100000_*) made
 * them a write-only-by-sync, read-only projection; Phase 4 removed every
 * remaining reader and made App\Support\Payments::sync*() a no-op. These
 * columns are now dead weight — drop them.
 *
 * down() recreates the columns (nullable, no backfill) so a rollback does
 * not error, but the data is gone: anyone rolling back must re-derive
 * these from `payments` by hand, or just not roll back.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('direct_registrations', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'paid_at', 'receipt_no', 'razorpay_order_id', 'razorpay_payment_id']);
        });

        Schema::table('student_applications', function (Blueprint $table) {
            $table->dropColumn(['fee_paid', 'paid_at', 'fee_receipt_id', 'payment_ref', 'razorpay_order_id']);
        });

        Schema::table('admissions', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'fee_status', 'razorpay_order_id', 'razorpay_payment_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::table('direct_registrations', function (Blueprint $table) {
            $table->string('payment_status')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('receipt_no')->nullable();
            $table->string('razorpay_order_id')->nullable();
            $table->string('razorpay_payment_id')->nullable();
        });

        Schema::table('student_applications', function (Blueprint $table) {
            $table->boolean('fee_paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('fee_receipt_id')->nullable();
            $table->string('payment_ref')->nullable();
            $table->string('razorpay_order_id')->nullable();
        });

        Schema::table('admissions', function (Blueprint $table) {
            $table->string('payment_status')->nullable();
            $table->string('fee_status')->nullable();
            $table->string('razorpay_order_id')->nullable();
            $table->string('razorpay_payment_id')->nullable();
            $table->timestamp('paid_at')->nullable();
        });
    }
};
