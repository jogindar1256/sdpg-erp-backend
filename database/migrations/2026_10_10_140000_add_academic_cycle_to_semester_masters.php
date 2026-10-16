<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * semester_masters.academic_cycle — which half of the year a semester runs:
 *   JUL_DEC  July–December (ODD)
 *   JAN_JUN  January–June  (EVEN)
 * Filled from parity for existing rows; editable on Semester Master.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('semester_masters') || Schema::hasColumn('semester_masters', 'academic_cycle')) {
            return;
        }
        DB::statement("ALTER TABLE semester_masters ADD COLUMN academic_cycle varchar(10) NULL");
        DB::statement("UPDATE semester_masters SET academic_cycle = CASE WHEN semester_num % 2 = 1 THEN 'JUL_DEC' ELSE 'JAN_JUN' END");
        DB::statement("ALTER TABLE semester_masters ADD CONSTRAINT semester_masters_academic_cycle_check CHECK (academic_cycle IN ('JUL_DEC','JAN_JUN'))");
    }

    public function down(): void
    {
        if (Schema::hasColumn('semester_masters', 'academic_cycle')) {
            DB::statement('ALTER TABLE semester_masters DROP CONSTRAINT IF EXISTS semester_masters_academic_cycle_check');
            DB::statement('ALTER TABLE semester_masters DROP COLUMN academic_cycle');
        }
    }
};
