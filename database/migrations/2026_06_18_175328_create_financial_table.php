<?php
// ─────────────────────────────────────────────────────────────────────────────
// FINANCIAL ACTIVITY MODULE MIGRATIONS
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── fee_transfer_vouchers ─────────────────────────────────────────────
        Schema::create('fee_transfer_vouchers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('admission_id');
            $t->string('session', 20);
            $t->decimal('grand_total', 12, 2)->default(0);
            $t->text('amount_in_words')->nullable();
            // Transfer details
            $t->string('ref_no')->nullable();
            $t->decimal('transfer_amount', 12, 2)->nullable();
            $t->date('transfer_date')->nullable();
            $t->string('transfer_through', 100)->nullable(); // RTGS/NEFT/Cheque/DD
            $t->string('instrument_no', 100)->nullable();
            $t->date('instrument_date')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();

            $t->foreign('admission_id')->references('id')->on('admissions');
            $t->index(['admission_id', 'session']);
        });

        // ── fee_transfer_voucher_items ────────────────────────────────────────
        Schema::create('fee_transfer_voucher_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('voucher_id');
            $t->string('fee_type', 100);           // Registration Fee, Admission Fee, DA, etc.
            $t->string('bank_account', 200)->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->timestamps();

            $t->foreign('voucher_id')->references('id')->on('fee_transfer_vouchers')->onDelete('cascade');
        });

        // ── transaction_updates ───────────────────────────────────────────────
        // Records corrections/updates made to existing payment transactions
        Schema::create('transaction_updates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('admission_id');
            $t->string('fee_update_for', 100);     // Which fee type is being updated
            $t->decimal('amount', 12, 2)->default(0);
            $t->date('paid_date');
            $t->string('utr_no', 100);
            $t->string('bank_ref_no', 100);
            $t->string('payment_ref_no', 100)->nullable();
            $t->string('gateway_status', 50)->nullable(); // Success/Failed/Pending
            $t->string('ref_no', 50)->nullable();         // TXU2025XXXXXX
            $t->date('update_date')->nullable();
            $t->string('status', 20)->default('Pending'); // Pending|Approved|Rejected
            $t->string('created_by')->nullable();
            $t->string('approved_by')->nullable();
            $t->timestamps();

            $t->foreign('admission_id')->references('id')->on('admissions');
            $t->index(['admission_id', 'status']);
        });

        // (bank_ref_no/fee_status on fee_receipts, and fee_status on
        // admissions — now live directly in create_fee_receipts_table.php
        // and create_admissions_table.php respectively, folded in as part
        // of the migration cleanup.)
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_transfer_voucher_items');
        Schema::dropIfExists('fee_transfer_vouchers');
        Schema::dropIfExists('transaction_updates');
    }
};
