<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * payment_ledger was a parallel payment-tracking table built before the
 * `payments` table (see 2026_10_18_100000_create_payments_table). Nothing
 * ever wrote to it outside PaymentLedgerController itself, and the
 * frontend's "Student Ledger" pages call FeesController::ledgerIndex /
 * AdmissionController::ledger, not this one — it was dead on arrival.
 * Safe to drop unconditionally for a fresh install; this is only here so
 * a database that already ran the old create migration gets it removed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('payment_ledger');
    }

    public function down(): void
    {
        // Intentionally no-op — the table it created is gone for good.
    }
};
