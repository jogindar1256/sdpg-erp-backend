<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * students.abc_id already exists (2024_01_01_000006_create_students_table) —
 * guarded here anyway. students.ddurn and students.family_id do not exist
 * yet, even though AmendmentController and ApplicationController::upgradeSelf
 * already query `s.ddurn` (a pre-existing gap this closes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $t) {
            if (!Schema::hasColumn('students', 'abc_id')) {
                $t->string('abc_id')->nullable();
            }
            if (!Schema::hasColumn('students', 'ddurn')) {
                $t->string('ddurn', 50)->nullable()->after('abc_id');
            }
            if (!Schema::hasColumn('students', 'family_id')) {
                $t->string('family_id', 50)->nullable()->after('ddurn');
            }
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $t) {
            foreach (['ddurn', 'family_id'] as $col) {
                if (Schema::hasColumn('students', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
