<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1. rejected_applications.previous_status — the application status at the
 *    moment it was put on hold, so Release Hold restores it instead of
 *    always resetting to 'submitted'.
 * 2. fee_receipts.cancelled_at / cancelled_by — who cancelled a receipt.
 *    The refund owed is tracked in fee_refunds (2026_10_17_110000).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('rejected_applications', 'previous_status')) {
            Schema::table('rejected_applications', function (Blueprint $table) {
                $table->string('previous_status', 30)->nullable()->after('status');
            });
        }

        Schema::table('fee_receipts', function (Blueprint $table) {
            if (!Schema::hasColumn('fee_receipts', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
            }
        });

    }

    public function down(): void
    {
        Schema::table('rejected_applications', function (Blueprint $table) {
            $table->dropColumn('previous_status');
        });
        Schema::table('fee_receipts', function (Blueprint $table) {
            $table->dropColumn([
                'cancelled_at', 'cancelled_by',
            ]);
        });
    }
};
