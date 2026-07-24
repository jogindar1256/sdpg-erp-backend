<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('admissions', 'payment_status')) {
            DB::statement("ALTER TABLE admissions ALTER COLUMN payment_status SET DEFAULT 'pending'");

            DB::table('admissions')
                ->where('payment_status', 'paid')
                ->whereNull('paid_at')
                ->whereNull('razorpay_payment_id')
                ->update(['payment_status' => 'pending']);
        }

        if (Schema::hasColumn('students', 'reg_source')) {
            Schema::table('students', function (Blueprint $t) {
                $t->dropColumn('reg_source');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('admissions', 'payment_status')) {
            DB::statement("ALTER TABLE admissions ALTER COLUMN payment_status SET DEFAULT 'paid'");
        }

        if (!Schema::hasColumn('students', 'reg_source')) {
            Schema::table('students', function (Blueprint $t) {
                $t->string('reg_source', 10)->default('college');
            });
        }
    }
};
