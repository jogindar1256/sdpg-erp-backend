<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two real bugs found while checking Subject Selection Master and Vocational
 * Paper Master:
 *
 * 1. subject_selections.max_marks / min_marks were left over from the
 *    original table definition as NOT NULL integer columns with no default.
 *    subjectSelectionStore() never populates them (it writes max_select /
 *    min_select instead, added later) — so every single save has been
 *    throwing a NOT NULL constraint violation. That's the reported
 *    "save is not working" bug. Made nullable here since they're dead
 *    columns superseded by max_select/min_select.
 *
 * 2. vocational_papers has no group-level "how many of this group's minor
 *    papers must a student pick" concept (Max. Select / Min Select shown in
 *    the docx mockup for a Group), unlike subject_selections which already
 *    has it. Added so the group builder can be real, not cosmetic.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE subject_selections ALTER COLUMN max_marks DROP NOT NULL');
        DB::statement('ALTER TABLE subject_selections ALTER COLUMN min_marks DROP NOT NULL');

        Schema::table('vocational_papers', function (Blueprint $t) {
            if (!Schema::hasColumn('vocational_papers', 'max_select')) {
                $t->integer('max_select')->default(1)->after('group_name');
            }
            if (!Schema::hasColumn('vocational_papers', 'min_select')) {
                $t->integer('min_select')->default(1)->after('max_select');
            }
        });
    }

    public function down(): void
    {
        DB::statement("UPDATE subject_selections SET max_marks = 0 WHERE max_marks IS NULL");
        DB::statement("UPDATE subject_selections SET min_marks = 0 WHERE min_marks IS NULL");
        DB::statement('ALTER TABLE subject_selections ALTER COLUMN max_marks SET NOT NULL');
        DB::statement('ALTER TABLE subject_selections ALTER COLUMN min_marks SET NOT NULL');

        Schema::table('vocational_papers', function (Blueprint $t) {
            foreach (['max_select', 'min_select'] as $c) {
                if (Schema::hasColumn('vocational_papers', $c)) $t->dropColumn($c);
            }
        });
    }
};
