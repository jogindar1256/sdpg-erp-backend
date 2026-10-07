<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up to 2026_10_05_100000_group_students_columns: gender,
 * date_of_birth and category also move into students.personal_info.
 *
 * That migration now handles these three itself, so on a database where it
 * has not run yet this one finds nothing to do. It exists for a database
 * where the earlier version (which left the three as real columns) already ran.
 */
return new class extends Migration
{
    private const FIELDS = ['gender' => 'varchar(255)', 'date_of_birth' => 'date', 'category' => 'varchar(255)'];

    public function up(): void
    {
        if (!Schema::hasTable('students') || !Schema::hasColumn('students', 'personal_info')) {
            return;
        }
        $present = array_values(array_filter(array_keys(self::FIELDS), fn($c) => Schema::hasColumn('students', $c)));
        if (!$present) {
            return;
        }
        $pairs = implode(', ', array_map(fn($c) => "'{$c}', {$c}", $present));
        DB::statement("UPDATE students SET personal_info = personal_info || jsonb_build_object({$pairs})");
        // Dropping gender also drops the students_gender_check constraint.
        DB::statement('ALTER TABLE students ' . implode(', ', array_map(fn($c) => "DROP COLUMN {$c}", $present)));
    }

    public function down(): void
    {
        if (!Schema::hasTable('students') || !Schema::hasColumn('students', 'personal_info')) {
            return;
        }
        foreach (self::FIELDS as $col => $type) {
            if (!Schema::hasColumn('students', $col)) {
                DB::statement("ALTER TABLE students ADD COLUMN {$col} {$type} NULL");
            }
            DB::statement("UPDATE students SET {$col} = NULLIF(personal_info->>'{$col}', '')::{$type}");
        }
    }
};
