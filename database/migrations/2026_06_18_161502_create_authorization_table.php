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

        // ── Add documents_verified flag to admissions (if not present) ────────
        if (Schema::hasTable('admissions')) {
            Schema::table('admissions', function (Blueprint $t) {
                if (!Schema::hasColumn('admissions', 'documents_verified'))
                    $t->boolean('documents_verified')->default(false)->after('status');
                if (!Schema::hasColumn('admissions', 'approved_by'))
                    $t->unsignedBigInteger('approved_by')->nullable()->after('documents_verified');
                if (!Schema::hasColumn('admissions', 'approved_at'))
                    $t->timestamp('approved_at')->nullable()->after('approved_by');
            });
        }

        // ── Add verified_by / verified_at to fee_receipts (if not present) ────
        if (Schema::hasTable('fee_receipts')) {
            Schema::table('fee_receipts', function (Blueprint $t) {
                if (!Schema::hasColumn('fee_receipts', 'verified_by'))
                    $t->unsignedBigInteger('verified_by')->nullable()->after('status');
                if (!Schema::hasColumn('fee_receipts', 'verified_at'))
                    $t->timestamp('verified_at')->nullable()->after('verified_by');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_logs');
        if (Schema::hasTable('admissions')) {
            Schema::table('admissions', function (Blueprint $t) {
                $t->dropColumnIfExists('documents_verified');
                $t->dropColumnIfExists('approved_by');
                $t->dropColumnIfExists('approved_at');
            });
        }
        if (Schema::hasTable('fee_receipts')) {
            Schema::table('fee_receipts', function (Blueprint $t) {
                $t->dropColumnIfExists('verified_by');
                $t->dropColumnIfExists('verified_at');
            });
        }
    }
};
