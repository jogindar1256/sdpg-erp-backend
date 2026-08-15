<?php
// ─────────────────────────────────────────────────────────────────────────────
// AUTHORIZATION MODULE MIGRATIONS
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── authorization_logs ────────────────────────────────────────────────
        // Audit trail for every approval/rejection/rollback action
        Schema::create('authorization_logs', function (Blueprint $t) {
            $t->id();
            $t->string('action_type');
            // AdmissionVerification | SemesterApproval | FeeReceiptVerification
            // MiscActivityVerification | BlockUnblock

            $t->string('action');
            // Approved | Rejected | RollBack | Verified | block | unblock

            $t->unsignedBigInteger('admission_id')->nullable();
            $t->unsignedBigInteger('reference_id')->nullable(); // fee_receipts.id or amendment_logs.id
            $t->text('remarks')->nullable();
            $t->unsignedBigInteger('performed_by')->nullable(); // users.id
            $t->timestamps();

            $t->index(['action_type', 'created_at']);
            $t->index('admission_id');
        });

        // (documents_verified/approved_by/approved_at on `admissions`, and
        // verified_by/verified_at on `fee_receipts` — the latter pair
        // already existed on fee_receipts before this file, so that part was
        // always a no-op — now live directly in create_admissions_table.php
        // and create_fee_receipts_table.php respectively, folded in as part
        // of the migration cleanup.)
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_logs');
    }
};
