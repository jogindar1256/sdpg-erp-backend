<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. programs.samarth_code — the SAMARTH portal's numeric course code
 *    (e.g. 202 = M.A. Sociology). Unique among live (non soft-deleted)
 *    programs. Nullable because existing rows have no code until the office
 *    fills it in from Course Master.
 *
 * 2. subject_papers.paper_type now means Theory / Practical. It used to hold
 *    "Subject Paper" / "Extra Paper", which nothing ever read. The old value
 *    is preserved in subject_papers.paper_category so nothing is lost and
 *    down() can restore it.
 *
 * Every statement is guarded so the migration is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('programs')) {
            if (!Schema::hasColumn('programs', 'samarth_code')) {
                Schema::table('programs', function (Blueprint $t) {
                    $t->string('samarth_code', 20)->nullable();
                });
            }
            // Partial index: a soft-deleted course must not block its code
            // from being reused on a re-created course.
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS programs_samarth_code_unique
                 ON programs (samarth_code) WHERE deleted_at IS NULL'
            );
        }

        if (Schema::hasTable('subject_papers')) {
            if (!Schema::hasColumn('subject_papers', 'paper_category')) {
                Schema::table('subject_papers', function (Blueprint $t) {
                    $t->string('paper_category', 30)->nullable();
                });
            }

            // Keep the legacy value before overwriting paper_type.
            DB::statement(
                "UPDATE subject_papers SET paper_category = paper_type
                 WHERE paper_category IS NULL
                   AND LOWER(paper_type) NOT IN ('theory', 'practical')"
            );
            DB::statement(
                "UPDATE subject_papers SET paper_type = 'Practical'
                 WHERE LOWER(paper_type) = 'practical' AND paper_type <> 'Practical'"
            );
            DB::statement(
                "UPDATE subject_papers SET paper_type = 'Theory'
                 WHERE paper_type IS NULL OR paper_type <> 'Practical'"
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subject_papers') && Schema::hasColumn('subject_papers', 'paper_category')) {
            DB::statement(
                'UPDATE subject_papers SET paper_type = paper_category WHERE paper_category IS NOT NULL'
            );
            Schema::table('subject_papers', function (Blueprint $t) {
                $t->dropColumn('paper_category');
            });
        }

        if (Schema::hasTable('programs')) {
            DB::statement('DROP INDEX IF EXISTS programs_samarth_code_unique');
            if (Schema::hasColumn('programs', 'samarth_code')) {
                Schema::table('programs', function (Blueprint $t) {
                    $t->dropColumn('samarth_code');
                });
            }
        }
    }
};
