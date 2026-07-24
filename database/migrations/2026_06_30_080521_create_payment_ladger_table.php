<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_ledger', function (Blueprint $t) {

            $t->id();

            // ── Organisation ─────────────────────────────────────────────
            $t->unsignedBigInteger('organization_id')->nullable()->index();

            // ── Transaction Identity ──────────────────────────────────────
            // Auto-generated: TXN-2526-000001  (session_short + seq)
            $t->string('txn_no', 30)->unique();

            // ── Transaction Type ──────────────────────────────────────────
            // All possible transaction types in the ERP:
            $t->string('txn_type', 40)->comment(
                'Registration | Admission | Semester-Registration | ' .
                'Examination | Back-Paper | Practical | Upgrade | ' .
                'Fine | Late-Fee | Refund | Adjustment | Other'
            );

            // ── Payment Mode ──────────────────────────────────────────────
            $t->string('payment_mode', 30)->comment(
                'Online-Razorpay | NEFT | RTGS | IMPS | UPI | ' .
                'Cheque | DD | Cash | Adjustment'
            );

            // ── Amount ───────────────────────────────────────────────────
            $t->decimal('amount', 12, 2);
            // For refunds/adjustments: negative entry in debit column
            $t->decimal('debit', 12, 2)->default(0);   // money going OUT (refund/adj)
            $t->decimal('credit', 12, 2)->default(0);  // money coming IN (payment)
            // Running balance (updated on insert by trigger/app logic)
            $t->decimal('balance', 12, 2)->default(0)->nullable();

            // ── Status ───────────────────────────────────────────────────
            $t->string('status', 20)->default('Pending')->comment(
                'Pending | Success | Failed | Refunded | Cancelled | Disputed'
            );

            // ── Academic Context ─────────────────────────────────────────
            $t->string('session_year', 12)->nullable()->index();   // 2025-2026
            $t->tinyInteger('semester_no')->unsigned()->nullable(); // 1–8

            // ── Entity Links (all nullable — not every txn has all) ───────
            $t->unsignedBigInteger('student_id')->nullable()->index();
            $t->unsignedBigInteger('admission_id')->nullable()->index();
            $t->unsignedBigInteger('application_id')->nullable()->index();
            // For self-registration before admission_id exists
            $t->string('reg_no', 20)->nullable()->index();

            // ── Bank / Gateway Details ────────────────────────────────────
            $t->string('bank_ref_no', 100)->nullable();     // Bank reference number
            $t->string('utr_no', 100)->nullable();           // UTR / NEFT ref
            $t->string('cheque_dd_no', 50)->nullable();      // Cheque or DD number
            $t->date('cheque_dd_date')->nullable();
            $t->string('bank_name', 100)->nullable();        // Bank name for cheque/DD
            $t->string('bank_account', 200)->nullable();     // College bank account credited

            // ── Razorpay / Online Gateway ─────────────────────────────────
            $t->string('gateway_order_id', 100)->nullable();    // Razorpay order_id
            $t->string('gateway_payment_id', 100)->nullable();  // Razorpay payment_id
            $t->string('gateway_signature', 255)->nullable();   // Razorpay signature (hashed)
            $t->string('gateway_status', 30)->nullable();       // captured | failed | refunded

            // ── Receipt ───────────────────────────────────────────────────
            $t->string('receipt_no', 30)->nullable()->unique();  // MR-2526-000001
            $t->timestamp('paid_at')->nullable();

            // ── Description & Notes ──────────────────────────────────────
            $t->string('description', 300)->nullable();          // Human-readable: "Sem-3 Exam Fee — Roll 250101"
            $t->text('remarks')->nullable();                     // Admin notes

            // ── Audit ────────────────────────────────────────────────────
            $t->unsignedBigInteger('created_by')->nullable();    // Staff/system user ID
            $t->unsignedBigInteger('verified_by')->nullable();   // Who verified offline payment
            $t->timestamp('verified_at')->nullable();

            $t->timestamps();
            $t->softDeletes();                                   // Never hard-delete a ledger entry

            // ── Indexes ───────────────────────────────────────────────────
            $t->index(['txn_type', 'status']);
            $t->index(['session_year', 'txn_type']);
            $t->index(['student_id', 'session_year']);
            $t->index(['payment_mode', 'status']);
            $t->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_ledger');
    }
};