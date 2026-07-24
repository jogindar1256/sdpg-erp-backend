<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // students — add OTP verification + source flags
        Schema::table('students', function (Blueprint $t) {
            if (!Schema::hasColumn('students', 'phone_verified')) {
                $t->boolean('phone_verified')->default(false)->after('mobile');
            }
            if (!Schema::hasColumn('students', 'email_verified')) {
                $t->boolean('email_verified')->default(false)->after('phone_verified');
            }
            if (!Schema::hasColumn('students', 'reg_source')) {
                // 'college' = staff-created | 'self' = student self-registered
                $t->string('reg_source', 10)->default('college')->after('email_verified');
            }
        });

        // admissions — add payment tracking for self-registration
        Schema::table('admissions', function (Blueprint $t) {
            if (!Schema::hasColumn('admissions', 'payment_status')) {
                // existing college-created admissions default to 'paid' (fee collected offline)
                $t->string('payment_status', 10)->default('paid')->after('status');
            }
            if (!Schema::hasColumn('admissions', 'razorpay_order_id')) {
                $t->string('razorpay_order_id', 100)->nullable()->after('payment_status');
            }
            if (!Schema::hasColumn('admissions', 'razorpay_payment_id')) {
                $t->string('razorpay_payment_id', 100)->nullable()->after('razorpay_order_id');
            }
            if (!Schema::hasColumn('admissions', 'paid_at')) {
                $t->timestamp('paid_at')->nullable()->after('razorpay_payment_id');
            }
            if (!Schema::hasColumn('admissions', 'temp_password_plain')) {
                // cleared immediately after welcome email is sent
                $t->string('temp_password_plain', 30)->nullable()->after('paid_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $t) {
            $t->dropColumn(['phone_verified', 'email_verified', 'reg_source']);
        });
        Schema::table('admissions', function (Blueprint $t) {
            $t->dropColumn([
                'payment_status', 'razorpay_order_id',
                'razorpay_payment_id', 'paid_at', 'temp_password_plain',
            ]);
        });
    }
};
