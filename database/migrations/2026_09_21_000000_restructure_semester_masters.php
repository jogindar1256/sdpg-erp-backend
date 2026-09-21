<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * semester_masters becomes the single source of truth for semesters.
 *
 * BEFORE: one row per parity, with a free-text list of members
 *         name = "ODD Semester", semester_nos = "1,3,5,7"
 *         → nothing read it; parity was hardcoded in 6+ places.
 *
 * AFTER:  one row PER SEMESTER (1..10)
 *         semester_num = 1,  semester_name = "ODD"
 *         semester_num = 2,  semester_name = "EVEN"
 *
 * Also drops application_schedules.semester_name — the name is now derivable
 * from semester_no, so storing it was a second copy that could disagree.
 */
return new class extends Migration {
    public function up(): void
    {
        // Config table with no inbound FKs — safe to clear and rebuild.
        // SemesterSeeder repopulates it.
        DB::table('semester_masters')->delete();

        Schema::table('semester_masters', function (Blueprint $table) {
            $table->dropColumn(['name', 'semester_nos']);
        });

        Schema::table('semester_masters', function (Blueprint $table) {
            $table->unsignedTinyInteger('semester_num')->after('id');
            $table->string('semester_name', 10)->after('semester_num'); // ODD | EVEN
            $table->unique('semester_num');
        });

        // semester_name here duplicated what semester_no already implies.
        if (Schema::hasColumn('application_schedules', 'semester_name')) {
            Schema::table('application_schedules', function (Blueprint $table) {
                $table->dropColumn('semester_name');
            });
        }
    }

    public function down(): void
    {
        Schema::table('semester_masters', function (Blueprint $table) {
            $table->dropUnique(['semester_num']);
            $table->dropColumn(['semester_num', 'semester_name']);
        });

        Schema::table('semester_masters', function (Blueprint $table) {
            $table->string('name')->default('');
            $table->string('semester_nos')->default('');
        });

        Schema::table('application_schedules', function (Blueprint $table) {
            $table->string('semester_name')->default('');
        });
    }
};
