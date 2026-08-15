<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fee Structure Management redesign (fee-structure settings page) added two
 * PG/B.Ed.-only toggles from the reference UI: "SDPGC Student" and
 * "DDU / Affiliated College" — both describe which UG pass-out source a
 * fee structure row applies to, so a PG/B.Ed. program can carry distinct
 * fee amounts for students who passed UG from SDPGC itself vs. a
 * DDU-affiliated college. They're real identity columns (not decoration),
 * so they're added to the row's uniqueness key alongside the existing
 * program/fee-head/semester/year/admission-type combination.
 *
 * NOT NULL + default(false) is deliberate: Postgres treats every NULL as
 * distinct for uniqueness purposes, so a nullable pair here would let
 * every save on a UG-level program (which never sets these) silently
 * insert a duplicate row instead of updating the existing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_structures', function (Blueprint $table) {
            $table->boolean('sdpgc_student')->default(false)->after('term');
            $table->boolean('ddu_affiliated')->default(false)->after('sdpgc_student');
        });

        Schema::table('fee_structures', function (Blueprint $table) {
            $table->dropUnique('fee_structure_unique');
        });

        Schema::table('fee_structures', function (Blueprint $table) {
            $table->unique(
                ['program_id', 'fee_head_id', 'semester_no', 'academic_year', 'admission_type', 'sdpgc_student', 'ddu_affiliated'],
                'fee_structure_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('fee_structures', function (Blueprint $table) {
            $table->dropUnique('fee_structure_unique');
        });

        Schema::table('fee_structures', function (Blueprint $table) {
            $table->unique(
                ['program_id', 'fee_head_id', 'semester_no', 'academic_year', 'admission_type'],
                'fee_structure_unique'
            );
        });

        Schema::table('fee_structures', function (Blueprint $table) {
            $table->dropColumn(['sdpgc_student', 'ddu_affiliated']);
        });
    }
};
