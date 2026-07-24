<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Cleanup — these were an earlier, superseded naming scheme for the
        //    part_1..part_8 columns (see 2026_06_25_080632_add_part_columns_to_student_application).
        //    Nothing in the codebase reads/writes them anymore.
        Schema::table('student_applications', function (Blueprint $t) {
            $legacy = [
                'part_personal', 'part_education', 'part_tc', 'part_migration',
                'part_bank', 'part_subjects', 'part_documents', 'part_declaration',
            ];
            foreach ($legacy as $col) {
                if (Schema::hasColumn('student_applications', $col)) {
                    $t->dropColumn($col);
                }
            }
        });

        // ── Back-paper fee / payment tracking ─────────────────────────────────
        Schema::table('student_applications', function (Blueprint $t) {
            if (!Schema::hasColumn('student_applications', 'fee_amount')) {
                $t->decimal('fee_amount', 10, 2)->nullable()->after('remarks');
            }
            if (!Schema::hasColumn('student_applications', 'fee_paid')) {
                $t->boolean('fee_paid')->default(false)->after('fee_amount');
            }
            if (!Schema::hasColumn('student_applications', 'paid_at')) {
                $t->timestamp('paid_at')->nullable()->after('fee_paid');
            }
            if (!Schema::hasColumn('student_applications', 'payment_ref')) {
                // Razorpay payment id once captured
                $t->string('payment_ref', 100)->nullable()->after('paid_at');
            }
            if (!Schema::hasColumn('student_applications', 'razorpay_order_id')) {
                $t->string('razorpay_order_id', 100)->nullable()->after('payment_ref');
            }
            if (!Schema::hasColumn('student_applications', 'fee_receipt_id')) {
                $t->unsignedBigInteger('fee_receipt_id')->nullable()->after('razorpay_order_id');
                $t->foreign('fee_receipt_id')->references('id')->on('fee_receipts')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_applications', function (Blueprint $t) {
            if (Schema::hasColumn('student_applications', 'fee_receipt_id')) {
                $t->dropForeign(['fee_receipt_id']);
            }
            $t->dropColumn([
                'fee_amount', 'fee_paid', 'paid_at', 'payment_ref',
                'razorpay_order_id', 'fee_receipt_id',
            ]);
        });

        Schema::table('student_applications', function (Blueprint $t) {
            $t->jsonb('part_personal')->nullable();
            $t->jsonb('part_education')->nullable();
            $t->jsonb('part_tc')->nullable();
            $t->jsonb('part_migration')->nullable();
            $t->jsonb('part_bank')->nullable();
            $t->jsonb('part_subjects')->nullable();
            $t->jsonb('part_documents')->nullable();
            $t->jsonb('part_declaration')->nullable();
        });
    }
};
