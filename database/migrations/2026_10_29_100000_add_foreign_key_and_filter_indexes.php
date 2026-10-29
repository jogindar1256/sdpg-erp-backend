<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for foreign-key and filter columns the controllers join/filter on.
 *
 * Postgres does NOT index a foreign-key column on its own — foreignId()
 * ->constrained() only adds the constraint — so every join on these columns
 * (and every cascadeOnDelete from the parent) was a sequential scan.
 *
 * - CONCURRENTLY: no write lock on the table while the index builds. It can't
 *   run inside a transaction, hence $withinTransaction = false.
 * - An index is skipped when an existing valid index already starts with the
 *   same column(s) (e.g. fee_receipts (student_id, academic_year) already
 *   covers student_id), so no duplicate indexes are created.
 * - A failed CONCURRENTLY build leaves an INVALID index behind; that one is
 *   dropped and rebuilt instead of being skipped by IF NOT EXISTS.
 */
return new class extends Migration {
    public $withinTransaction = false;

    private const INDEXES = [
        // name                                   table                   columns
        ['admissions_student_id_index',            'admissions',           ['student_id']],
        ['admissions_program_id_index',            'admissions',           ['program_id']],
        ['admissions_application_id_index',        'admissions',           ['application_id']],
        ['admissions_academic_year_semester_no_index', 'admissions',       ['academic_year', 'semester_no']],
        ['fee_receipts_admission_id_index',        'fee_receipts',         ['admission_id']],
        ['fee_receipts_academic_year_is_verified_index', 'fee_receipts',   ['academic_year', 'is_verified']],
        ['fee_receipts_created_at_index',          'fee_receipts',         ['created_at']],
        ['exam_forms_admission_id_index',          'exam_forms',           ['admission_id']],
        ['exam_form_papers_paper_code_index',      'exam_form_papers',     ['paper_code']],
        ['exam_form_papers_subject_id_index',      'exam_form_papers',     ['subject_id']],
        ['exam_seating_student_id_index',          'exam_seating',         ['student_id']],
        ['student_applications_student_id_index',  'student_applications', ['student_id']],
        ['student_applications_program_id_index',  'student_applications', ['program_id']],
        ['student_applications_user_id_index',     'student_applications', ['user_id']],
        ['students_user_id_index',                 'students',             ['user_id']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as [$name, $table, $columns]) {
            if (!Schema::hasTable($table) || !Schema::hasColumns($table, $columns)) continue;
            if ($this->alreadyCovered($table, $columns)) continue;

            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS \"{$name}\"");
            $cols = implode(', ', array_map(fn ($c) => "\"{$c}\"", $columns));
            DB::statement("CREATE INDEX CONCURRENTLY \"{$name}\" ON \"{$table}\" ({$cols})");
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as [$name]) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS \"{$name}\"");
        }
    }

    /** True when a valid index on $table already starts with exactly $columns. */
    private function alreadyCovered(string $table, array $columns): bool
    {
        $indexes = DB::select(
            "SELECT array_to_string(ARRAY(
                        SELECT a.attname FROM unnest(ix.indkey) WITH ORDINALITY k(attnum, ord)
                        JOIN pg_attribute a ON a.attrelid = ix.indrelid AND a.attnum = k.attnum
                        ORDER BY k.ord), ',') AS cols
               FROM pg_index ix
               JOIN pg_class t ON t.oid = ix.indrelid
               JOIN pg_namespace n ON n.oid = t.relnamespace
              WHERE t.relname = ? AND n.nspname = current_schema() AND ix.indisvalid",
            [$table]
        );

        $want = implode(',', $columns);
        foreach ($indexes as $ix) {
            if ($ix->cols === $want || str_starts_with($ix->cols, $want . ',')) return true;
        }
        return false;
    }
};
