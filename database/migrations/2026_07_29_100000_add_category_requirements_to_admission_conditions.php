<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Admission Condition settings page ("/college/settings/admission/condition")
 * only stored one required-% value per category (gen/obc/sc/st/ews), with no
 * split by gender and no Mark-vs-CGPA distinction — the office's actual paper
 * form needs Male/Female/Trans required-% per category, plus a Mark/CGPA type
 * selector per category.
 *
 * Rather than adding 20 flat columns (5 categories x 3 genders + 5 mark-type
 * flags), this follows the same pattern already used by
 * registration_fees.amounts elsewhere in this codebase: one JSON column keyed
 * by category, each holding { mark_type, male, female, trans }.
 *
 * The old required_percent_gen/obc/sc/st/ews columns are left in place
 * untouched (still have their default(0), so nothing breaks for any row that
 * predates this column) — the controller simply stops writing to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admission_conditions') && !Schema::hasColumn('admission_conditions', 'category_requirements')) {
            Schema::table('admission_conditions', function (Blueprint $table) {
                $table->json('category_requirements')->nullable()->after('required_percent_ews');
            });
        }
    }

    public function down(): void
    {
        // No-op — see the standing reconciliation-migration convention in
        // this codebase: down() is intentionally not implemented so a bad
        // rollback can't silently drop a column other code now depends on.
    }
};
