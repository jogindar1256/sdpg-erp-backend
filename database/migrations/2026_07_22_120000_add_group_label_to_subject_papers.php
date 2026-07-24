<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Group in Subject Paper Master is NOT an auto-incrementing counter — it's
 * a named elective combination (e.g. "Maths Group" containing Maths/Physics/
 * Chemistry, "Bio Group" containing Botany/Zoology/Chemistry), and it only
 * applies to BSc classes which offer elective subject combinations. Other
 * classes (BA, BCom, BEd...) have no grouping concept at all.
 *
 * `group_no` (integer, previously auto-incremented per save regardless of
 * subject — the bug reported by the user) is kept only for backward
 * compatibility with any already-saved rows, but is no longer written to by
 * the application. `group_label` is the real field going forward: nullable,
 * only ever set for BSc programs, and equal across every subject/paper that
 * belongs to the same elective combination.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subject_papers', function (Blueprint $table) {
            if (!Schema::hasColumn('subject_papers', 'group_label')) {
                $table->string('group_label', 50)->nullable()->after('group_no');
            }
        });

        // group_no was NOT NULL — batch saves for non-BSc classes never set
        // a real group concept, so it must become nullable.
        DB::statement('ALTER TABLE subject_papers ALTER COLUMN group_no DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::table('subject_papers', function (Blueprint $table) {
            if (Schema::hasColumn('subject_papers', 'group_label')) {
                $table->dropColumn('group_label');
            }
        });
        DB::statement("UPDATE subject_papers SET group_no = 1 WHERE group_no IS NULL");
        DB::statement('ALTER TABLE subject_papers ALTER COLUMN group_no SET NOT NULL');
    }
};
