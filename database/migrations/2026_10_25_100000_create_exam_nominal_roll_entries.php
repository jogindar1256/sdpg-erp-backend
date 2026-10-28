<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - exam_nominal_roll_entries: which accepted exam forms have been put on the
 *   Nominal Roll ("Create Nominal"). One row per exam form.
 * - exam_forms.reversed_at: set when an accepted form is reversed, so a later
 *   re-accept can be password-gated.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('exam_nominal_roll_entries')) {
            Schema::create('exam_nominal_roll_entries', function (Blueprint $t) {
                $t->id();
                $t->foreignId('exam_form_id')->unique()->constrained('exam_forms')->cascadeOnDelete();
                $t->string('session_year', 12);
                $t->unsignedBigInteger('program_id')->nullable();
                $t->string('semester_no', 10)->nullable();
                $t->string('exam_type', 20)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();

                $t->index(['session_year', 'program_id', 'semester_no']);
            });
        }
        if (!Schema::hasColumn('exam_forms', 'reversed_at')) {
            Schema::table('exam_forms', fn(Blueprint $t) => $t->timestamp('reversed_at')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_nominal_roll_entries');
        if (Schema::hasColumn('exam_forms', 'reversed_at')) {
            Schema::table('exam_forms', fn(Blueprint $t) => $t->dropColumn('reversed_at'));
        }
    }
};
