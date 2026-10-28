<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - student_applications.student_uid: the 13-digit Student ID reserved for a
 *   FIRST-TIME applicant when the education fee is paid. No students row
 *   exists yet at that point, so the number lives on the application and is
 *   carried over to students.student_uid when the admission is confirmed.
 * - fee_receipts.final_receipt_*: the generated "Final Fee Receipt" PDF.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('student_applications', 'student_uid')) {
            Schema::table('student_applications', function (Blueprint $t) {
                $t->string('student_uid', 20)->nullable()->index();
            });
        }
        foreach ([
            'final_receipt_path' => fn(Blueprint $t) => $t->string('final_receipt_path')->nullable(),
            'final_receipt_generated_at' => fn(Blueprint $t) => $t->timestamp('final_receipt_generated_at')->nullable(),
            'final_receipt_generated_by' => fn(Blueprint $t) => $t->unsignedBigInteger('final_receipt_generated_by')->nullable(),
        ] as $col => $add) {
            if (!Schema::hasColumn('fee_receipts', $col)) {
                Schema::table('fee_receipts', fn(Blueprint $t) => $add($t));
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('student_applications', 'student_uid')) {
            Schema::table('student_applications', fn(Blueprint $t) => $t->dropColumn('student_uid'));
        }
        foreach (['final_receipt_path', 'final_receipt_generated_at', 'final_receipt_generated_by'] as $col) {
            if (Schema::hasColumn('fee_receipts', $col)) {
                Schema::table('fee_receipts', fn(Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
