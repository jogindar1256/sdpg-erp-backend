<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Final Fee Receipt gets its own number, issued only by Generate ID.
 * fee_receipts.receipt_no stays the PAYMENT receipt's number (created at payment).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('fee_receipts', 'final_receipt_no')) {
            Schema::table('fee_receipts', function (Blueprint $t) {
                $t->string('final_receipt_no', 20)->nullable()->unique()->after('receipt_no');
            });
        }
        // Final receipts already generated were printed with the payment receipt's number — keep it.
        DB::statement("UPDATE fee_receipts SET final_receipt_no = receipt_no WHERE final_receipt_generated_at IS NOT NULL AND final_receipt_no IS NULL");
    }

    public function down(): void
    {
        if (Schema::hasColumn('fee_receipts', 'final_receipt_no')) {
            Schema::table('fee_receipts', function (Blueprint $t) {
                $t->dropColumn('final_receipt_no');
            });
        }
    }
};
