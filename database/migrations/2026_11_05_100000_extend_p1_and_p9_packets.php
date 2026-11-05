<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create P1 / Create Packet No. (P9): one record per session + exam date +
 * inning + centre. `lines` is the exam schedule of that date + inning
 * (class, subject, paper code, examinee count — and the packet number for
 * P9) as it stood when the record was saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['exam_conduct_p1', 'exam_packets'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (!Schema::hasColumn($table, 'creation_date')) $t->date('creation_date')->nullable();
                if (!Schema::hasColumn($table, 'lines')) $t->jsonb('lines')->nullable();
                if (!Schema::hasColumn($table, 'created_by')) $t->unsignedBigInteger('created_by')->nullable();
                $t->unique(['session_year', 'exam_date', 'inning_id', 'center_code'], "{$table}_unique_plan");
            });
        }
    }

    public function down(): void
    {
        foreach (['exam_conduct_p1', 'exam_packets'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique("{$table}_unique_plan");
                $t->dropColumn(['creation_date', 'lines', 'created_by']);
            });
        }
    }
};
