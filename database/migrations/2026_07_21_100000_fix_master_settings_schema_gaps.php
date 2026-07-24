<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $t) {
            if (!Schema::hasColumn('programs', 'approval_type')) {
                $t->string('approval_type', 30)->default('Under Finance');
            }
            if (!Schema::hasColumn('programs', 'exam_mode')) {
                $t->string('exam_mode', 20)->default('Regular');
            }
        });

        Schema::table('subjects', function (Blueprint $t) {
            if (!Schema::hasColumn('subjects', 'has_practical')) {
                $t->boolean('has_practical')->default(false);
            }
            if (!Schema::hasColumn('subjects', 'practical_fee')) {
                $t->decimal('practical_fee', 10, 2)->nullable();
            }
            // Subject Master.docx: "Additional Fee Applicable" + "If
            // Applicable Yes Then Fee Rs." — a second, separate fee concept
            // from the practical fee, never migrated.
            if (!Schema::hasColumn('subjects', 'additional_fee_applicable')) {
                $t->boolean('additional_fee_applicable')->default(false);
            }
            if (!Schema::hasColumn('subjects', 'additional_fee')) {
                $t->decimal('additional_fee', 10, 2)->nullable();
            }
        });

        // paper_type: drop the Postgres CHECK constraint Laravel's enum()
        // creates and widen to varchar so 'self_finance' (what the UI
        // actually sends) is a legal value.
        DB::statement('ALTER TABLE subjects DROP CONSTRAINT IF EXISTS subjects_paper_type_check');
        Schema::table('subjects', function (Blueprint $t) {
            $t->string('paper_type', 20)->default('regular')->change();
        });

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
                $t->integer('min_select')->default(0);
            }
        });

        Schema::table('fee_structures', function (Blueprint $t) {
            if (!Schema::hasColumn('fee_structures', 'amounts')) {
                $t->json('amounts')->nullable();
            }
            if (!Schema::hasColumn('fee_structures', 'term')) {
                $t->string('term', 30)->default('Admission');
            }
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $t) {
            foreach (['approval_type', 'exam_mode'] as $c) {
                if (Schema::hasColumn('programs', $c)) $t->dropColumn($c);
            }
        });
        Schema::table('subjects', function (Blueprint $t) {
            foreach (['has_practical', 'practical_fee', 'additional_fee_applicable', 'additional_fee'] as $c) {
                if (Schema::hasColumn('subjects', $c)) $t->dropColumn($c);
            }
        });
        Schema::table('enclosure_masters', function (Blueprint $t) {
            foreach (['condition', 'enclose', 'scan_copy', 'photo_count'] as $c) {
                if (Schema::hasColumn('enclosure_masters', $c)) $t->dropColumn($c);
            }
        });
        Schema::table('subject_selections', function (Blueprint $t) {
            foreach (['group_label', 'group_name', 'max_select', 'min_select'] as $c) {
                if (Schema::hasColumn('subject_selections', $c)) $t->dropColumn($c);
            }
        });
    }
};
