<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * students table restructure:
 *
 *  1. student_code  → student_uid  (the 13-digit Student ID; "student_id"
 *     already means students.id on ~15 other tables, so that name is avoided).
 *  2. 36 flat columns folded into five jsonb columns:
 *       personal_info      first/middle/last name, gender, date_of_birth,
 *                          category, religion, nationality,
 *                          alternate_mobile, whatsapp_no
 *       address_info       permanent_* , same_as_permanent, correspondence_*
 *       last_exam_details  last_exam_*
 *       tc_migration_info  tc_no, tc_date, tc_issued_by, migration_no, migration_date
 *       bank_details       bank_name, bank_branch, bank_ifsc, bank_account_no
 *
 * mobile, email, aadhar_no, abc_id stay as real columns (unique indexes,
 * login lookups). Dropping `gender` also drops students_gender_check.
 *
 * Data is copied into the jsonb columns BEFORE the old columns are dropped,
 * and down() restores the old columns from the jsonb. Take a database backup
 * before running this on production anyway.
 */
return new class extends Migration
{
    /** group => [field => original column DDL type] */
    private const GROUPS = [
        'personal_info' => [
            'first_name' => 'varchar(255)', 'middle_name' => 'varchar(255)', 'last_name' => 'varchar(255)',
            'gender' => 'varchar(255)', 'date_of_birth' => 'date', 'category' => 'varchar(255)',
            'religion' => 'varchar(255)', 'nationality' => 'varchar(255)',
            'alternate_mobile' => 'varchar(15)', 'whatsapp_no' => 'varchar(15)',
        ],
        'address_info' => [
            'permanent_address' => 'text', 'permanent_city' => 'varchar(255)', 'permanent_district' => 'varchar(255)',
            'permanent_state' => 'varchar(255)', 'permanent_pin' => 'varchar(10)',
            'same_as_permanent' => 'boolean',
            'correspondence_address' => 'text', 'correspondence_city' => 'varchar(255)',
            'correspondence_district' => 'varchar(255)', 'correspondence_state' => 'varchar(255)',
            'correspondence_pin' => 'varchar(10)',
        ],
        'last_exam_details' => [
            'last_exam_passed' => 'varchar(255)', 'last_exam_board' => 'varchar(255)',
            'last_exam_roll_no' => 'varchar(255)', 'last_exam_year' => 'integer',
            'last_exam_percentage' => 'numeric(5,2)', 'last_exam_division' => 'varchar(255)',
        ],
        'tc_migration_info' => [
            'tc_no' => 'varchar(255)', 'tc_date' => 'date', 'tc_issued_by' => 'varchar(255)',
            'migration_no' => 'varchar(255)', 'migration_date' => 'date',
        ],
        'bank_details' => [
            'bank_name' => 'varchar(255)', 'bank_branch' => 'varchar(255)',
            'bank_ifsc' => 'varchar(15)', 'bank_account_no' => 'varchar(255)',
        ],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('students')) {
            return;
        }

        // 1. student_code → student_uid
        if (Schema::hasColumn('students', 'student_code') && !Schema::hasColumn('students', 'student_uid')) {
            DB::statement('ALTER TABLE students RENAME COLUMN student_code TO student_uid');
            DB::statement('ALTER INDEX IF EXISTS students_student_code_index RENAME TO students_student_uid_index');
        }

        // 2. jsonb groups: add → copy → drop
        foreach (self::GROUPS as $group => $fields) {
            if (!Schema::hasColumn('students', $group)) {
                DB::statement("ALTER TABLE students ADD COLUMN {$group} jsonb NOT NULL DEFAULT '{}'::jsonb");
            }

            // Only columns that still exist are copied/dropped, so a re-run
            // after a partial failure is safe and never overwrites the jsonb
            // with nulls.
            $present = array_values(array_filter(array_keys($fields), fn($c) => Schema::hasColumn('students', $c)));
            if (!$present) {
                continue;
            }

            $pairs = implode(', ', array_map(fn($c) => "'{$c}', {$c}", $present));
            DB::statement("UPDATE students SET {$group} = {$group} || jsonb_build_object({$pairs})");

            $drops = implode(', ', array_map(fn($c) => "DROP COLUMN {$c}", $present));
            DB::statement("ALTER TABLE students {$drops}");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('students')) {
            return;
        }

        foreach (self::GROUPS as $group => $fields) {
            if (!Schema::hasColumn('students', $group)) {
                continue;
            }
            foreach ($fields as $col => $type) {
                if (!Schema::hasColumn('students', $col)) {
                    DB::statement("ALTER TABLE students ADD COLUMN {$col} {$type} NULL");
                }
                DB::statement("UPDATE students SET {$col} = NULLIF({$group}->>'{$col}', '')::{$type}");
            }
            DB::statement("ALTER TABLE students DROP COLUMN {$group}");
        }
        // The original NOT NULL / defaults on first_name, last_name,
        // permanent_*, nationality and same_as_permanent are not re-applied:
        // rows created after up() may legitimately lack them.

        if (Schema::hasColumn('students', 'student_uid') && !Schema::hasColumn('students', 'student_code')) {
            DB::statement('ALTER TABLE students RENAME COLUMN student_uid TO student_code');
            DB::statement('ALTER INDEX IF EXISTS students_student_uid_index RENAME TO students_student_code_index');
        }
    }
};
