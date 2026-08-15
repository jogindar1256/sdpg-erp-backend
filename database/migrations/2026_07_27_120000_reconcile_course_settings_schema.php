<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciliation migration — re-applies schema changes that were previously
 * written as separate "alter" migrations (2026-06-25 through 2026-07-23) and
 * later folded, content-only, into their tables' base `create_*` migrations
 * during a migration-consolidation cleanup.
 *
 * That consolidation assumed every alter file being deleted had *already run*
 * against production (so deleting the file was safe — Laravel doesn't
 * "un-apply" schema on file deletion). That assumption was wrong for at least
 * one file: `2026_07_21_100000_fix_master_settings_schema_gaps.php`, proven
 * by a live 42703 "column additional_fee_applicable does not exist" error on
 * `/college/settings/course/subjects`. Since the base `create_*` migrations
 * for these tables had already run years/weeks earlier, editing their
 * content in-place did nothing for the live database — Laravel tracks
 * completed migrations by filename and will never re-run them.
 *
 * Every statement below is guarded (hasColumn / hasTable / pg_indexes check)
 * so this is safe to run no matter which of the original alter files did or
 * didn't actually execute — it will only add what's actually missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── programs (from fix_master_settings_schema_gaps) ──────────────
        if (Schema::hasTable('programs')) {
            Schema::table('programs', function (Blueprint $t) {
                if (!Schema::hasColumn('programs', 'approval_type')) {
                    $t->string('approval_type', 30)->default('Under Finance');
                }
                if (!Schema::hasColumn('programs', 'exam_mode')) {
                    $t->string('exam_mode', 20)->default('Regular');
                }
            });
        }

        // ── subjects (from fix_master_settings_schema_gaps) ──────────────
        if (Schema::hasTable('subjects')) {
            Schema::table('subjects', function (Blueprint $t) {
                if (!Schema::hasColumn('subjects', 'has_practical')) {
                    $t->boolean('has_practical')->default(false);
                }
                if (!Schema::hasColumn('subjects', 'practical_fee')) {
                    $t->decimal('practical_fee', 10, 2)->nullable();
                }
                // Subject Master.docx: "Additional Fee Applicable" + "If
                // Applicable Yes Then Fee Rs." — a second, separate fee
                // concept from the practical fee.
                if (!Schema::hasColumn('subjects', 'additional_fee_applicable')) {
                    $t->boolean('additional_fee_applicable')->default(false);
                }
                if (!Schema::hasColumn('subjects', 'additional_fee')) {
                    $t->decimal('additional_fee', 10, 2)->nullable();
                }
            });

            // paper_type: drop the Postgres CHECK constraint Laravel's
            // enum() creates and widen to varchar so 'self_finance' (what
            // the UI actually sends) is a legal value. Idempotent — dropping
            // a constraint that's already gone, or re-declaring an already-
            // varchar column, is a no-op.
            DB::statement('ALTER TABLE subjects DROP CONSTRAINT IF EXISTS subjects_paper_type_check');
            Schema::table('subjects', function (Blueprint $t) {
                $t->string('paper_type', 20)->default('regular')->change();
            });
        }

        // ── enclosure_masters (from alter_enclosure_master) ──────────────
        if (Schema::hasTable('enclosure_masters')) {
            Schema::table('enclosure_masters', function (Blueprint $t) {
                if (!Schema::hasColumn('enclosure_masters', 'condition')) {
                    $t->string('condition', 20)->nullable();
                }
                if (!Schema::hasColumn('enclosure_masters', 'enclose')) {
                    $t->boolean('enclose')->default(false);
                }
                if (!Schema::hasColumn('enclosure_masters', 'scan_copy')) {
                    $t->boolean('scan_copy')->default(false);
                }
                if (!Schema::hasColumn('enclosure_masters', 'photo_count')) {
                    $t->string('photo_count', 5)->nullable();
                }
            });

            // De-duplicate before adding the unique index, same as the
            // original alter file did.
            $dupes = DB::table('enclosure_masters')
                ->select(
                    DB::raw('MIN(id) as keep_id'),
                    'program_id', 'semester_no', 'admission_mode', 'document_name'
                )
                ->groupBy('program_id', 'semester_no', 'admission_mode', 'document_name')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($dupes as $d) {
                DB::table('enclosure_masters')
                    ->where('program_id', $d->program_id)
                    ->where('semester_no', $d->semester_no)
                    ->where('admission_mode', $d->admission_mode)
                    ->where('document_name', $d->document_name)
                    ->where('id', '!=', $d->keep_id)
                    ->delete();
            }

            $hasIndex = collect(DB::select(
                "SELECT 1 FROM pg_indexes WHERE indexname = 'enclosure_masters_unique'"
            ))->isNotEmpty();

            if (!$hasIndex) {
                Schema::table('enclosure_masters', function (Blueprint $t) {
                    $t->unique(
                        ['program_id', 'semester_no', 'admission_mode', 'document_name'],
                        'enclosure_masters_unique'
                    );
                });
            }
        }

        // ── subject_papers (from add_paper_code_to_subject_papers +
        //    add_group_label_to_subject_papers) ─────────────────────────
        if (Schema::hasTable('subject_papers')) {
            Schema::table('subject_papers', function (Blueprint $t) {
                if (!Schema::hasColumn('subject_papers', 'paper_code')) {
                    $t->string('paper_code', 50)->nullable();
                }
                if (!Schema::hasColumn('subject_papers', 'group_label')) {
                    $t->string('group_label', 50)->nullable();
                }
            });

            // group_no was NOT NULL — batch saves for non-BSc classes never
            // set a real group concept, so it must be nullable. Idempotent.
            DB::statement('ALTER TABLE subject_papers ALTER COLUMN group_no DROP NOT NULL');
        }

        // ── subject_selections (from add_group_label_and_select_to_subject_selections
        //    + fix_selection_and_vocational_schema) ─────────────────────────
        if (Schema::hasTable('subject_selections')) {
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

            // max_marks/min_marks were left NOT NULL from the original table
            // definition; subjectSelectionStore() never populates them
            // (writes max_select/min_select instead) — every save was
            // throwing a NOT NULL violation. Idempotent.
            DB::statement('ALTER TABLE subject_selections ALTER COLUMN max_marks DROP NOT NULL');
            DB::statement('ALTER TABLE subject_selections ALTER COLUMN min_marks DROP NOT NULL');
        }

        // ── vocational_papers (from fix_selection_and_vocational_schema) ──
        if (Schema::hasTable('vocational_papers')) {
            Schema::table('vocational_papers', function (Blueprint $t) {
                if (!Schema::hasColumn('vocational_papers', 'max_select')) {
                    $t->integer('max_select')->default(1);
                }
                if (!Schema::hasColumn('vocational_papers', 'min_select')) {
                    $t->integer('min_select')->default(1);
                }
            });
        }

        // ── fee_structures (from fix_master_settings_schema_gaps) ────────
        if (Schema::hasTable('fee_structures')) {3639
            Schema::table('fee_structures', function (Blueprint $t) {
                if (!Schema::hasColumn('fee_structures', 'amounts')) {
                    $t->json('amounts')->nullable();
                }
                if (!Schema::hasColumn('fee_structures', 'term')) {
                    $t->string('term', 30)->default('Admission');
                }
            });
        }
    }

    public function down(): void
    {

    }
};
