<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fee condition rules (office "शुल्क नियमावली"):
 *
 * - Rule 5: a "Misc" term fee structure lives beside the education-fee
 *   structure of the same course / session / semester / admission type /
 *   category and gets its own Misc. receipt. fee_structure_unique allowed
 *   one row per that key, so it now also keys on "is this the Misc term"
 *   (an expression index) — two education-fee rows still can't coexist.
 * - Rule 4: "Pay in College" defaults to true — a particular is collected
 *   unless it is set to false. Existing rows keep their saved value.
 */
return new class extends Migration {
    private const KEY = '"organization_id", "program_id", "semester_no", "academic_year", "admission_type", "category", "sdpgc_student", "ddu_affiliated"';

    public function up(): void
    {
        if (Schema::hasTable('fee_structures')) {
            DB::statement('ALTER TABLE fee_structures DROP CONSTRAINT IF EXISTS fee_structure_unique');
            DB::statement('DROP INDEX IF EXISTS fee_structure_unique');
            DB::statement('CREATE UNIQUE INDEX fee_structure_unique ON fee_structures ('
                . self::KEY . ", (COALESCE(term, '') = 'Misc'))");
        }
        if (Schema::hasColumn('fee_structure_head_settings', 'pay_in_college')) {
            DB::statement('ALTER TABLE fee_structure_head_settings ALTER COLUMN pay_in_college SET DEFAULT true');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fee_structures')) {
            // Fails while Misc rows exist next to an education-fee row — remove them first.
            DB::statement('DROP INDEX IF EXISTS fee_structure_unique');
            DB::statement('ALTER TABLE fee_structures ADD CONSTRAINT fee_structure_unique UNIQUE (' . self::KEY . ')');
        }
        if (Schema::hasColumn('fee_structure_head_settings', 'pay_in_college')) {
            DB::statement('ALTER TABLE fee_structure_head_settings ALTER COLUMN pay_in_college SET DEFAULT false');
        }
    }
};
