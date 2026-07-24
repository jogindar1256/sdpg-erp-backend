<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert subject_selections from a number-based group (group_no) to the
 * letter-based group system (A, B, C …) with group-level SELECT counts.
 *
 *   group_label  — 'A','B','C' … (primary group identifier for the UI)
 *   group_name   — optional human label ("Arts Group A")
 *   max_select   — how many subjects a student may pick from the group
 *   min_select   — how many they must pick
 *
 * group_no / max_marks / min_marks are kept untouched for backward
 * compatibility with the registration & admission flows (per instruction).
 * max_marks / min_marks are made nullable so the new builder can save
 * groups without per-subject marks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subject_selections', function (Blueprint $t) {
            if (!Schema::hasColumn('subject_selections', 'group_label')) {
                $t->string('group_label', 2)->nullable();
            }
            if (!Schema::hasColumn('subject_selections', 'group_name')) {
                $t->string('group_name')->nullable();
            }
            if (!Schema::hasColumn('subject_selections', 'max_select')) {
                $t->integer('max_select')->default(1);
            }
            if (!Schema::hasColumn('subject_selections', 'min_select')) {
                $t->integer('min_select')->default(1);
            }
        });

        // Marks are no longer required by the new group builder.
        DB::statement('ALTER TABLE subject_selections ALTER COLUMN max_marks DROP NOT NULL');
        DB::statement('ALTER TABLE subject_selections ALTER COLUMN min_marks DROP NOT NULL');

        // Backfill letters from the existing numeric groups (1 -> A, 2 -> B …).
        DB::statement("UPDATE subject_selections SET group_label = chr(64 + group_no)
                       WHERE group_label IS NULL AND group_no BETWEEN 1 AND 26");
    }

    public function down(): void
    {
        Schema::table('subject_selections', function (Blueprint $t) {
            foreach (['group_label', 'group_name', 'max_select', 'min_select'] as $c) {
                if (Schema::hasColumn('subject_selections', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
        // max_marks/min_marks are left nullable — restoring NOT NULL could fail
        // on existing null rows.
    }
};
