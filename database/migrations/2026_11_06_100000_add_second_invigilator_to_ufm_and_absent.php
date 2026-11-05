<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P3 (UFM) / P4 (ABS): second invigilator; the ABS issued copy no. is optional. P9 UFM packet fields. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['exam_ufm', 'exam_absent'] as $table) {
            if (!Schema::hasColumn($table, 'invigilator2')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('invigilator2')->nullable());
            }
        }
        Schema::table('exam_absent', fn (Blueprint $t) => $t->string('issued_copy_no', 50)->nullable()->change());
    }

    public function down(): void
    {
        foreach (['exam_ufm', 'exam_absent'] as $table) {
            if (Schema::hasColumn($table, 'invigilator2')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('invigilator2'));
            }
        }
    }
};
