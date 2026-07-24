<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columns for the Regular-Admission numbering scheme (AdmissionNumberService):
 *   programs.course_code     — 2-digit code used in Student ID (BA=01, BSc=02 …)
 *   programs.is_self_finance — drives Fee Receipt mode 201 (self-finance) vs 101
 *   students.student_code    — the 13-digit Student ID (YY+centre+course+cat+serial)
 *   admissions.file_no       — "2425/00001"
 *   admissions.record_no     — global ever-incrementing record number
 *   (admissions.account_no already exists — reused as the Class A/C No.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $t) {
            if (!Schema::hasColumn('programs', 'course_code')) {
                $t->string('course_code', 2)->nullable();
            }
            if (!Schema::hasColumn('programs', 'is_self_finance')) {
                $t->boolean('is_self_finance')->default(false);
            }
        });

        Schema::table('students', function (Blueprint $t) {
            if (!Schema::hasColumn('students', 'student_code')) {
                $t->string('student_code', 20)->nullable()->index();
            }
        });

        Schema::table('admissions', function (Blueprint $t) {
            if (!Schema::hasColumn('admissions', 'file_no')) {
                $t->string('file_no', 20)->nullable()->index();
            }
            if (!Schema::hasColumn('admissions', 'record_no')) {
                $t->unsignedInteger('record_no')->nullable()->index();
            }
        });

        // Backfill the standard 2-digit course codes by short name.
        $map = ['BA' => '01', 'BSC' => '02', 'BED' => '03', 'MA' => '04', 'MSC' => '05'];
        foreach ($map as $short => $code) {
            DB::table('programs')
                ->whereRaw("upper(regexp_replace(short_name, '[^A-Za-z]', '', 'g')) = ?", [$short])
                ->whereNull('course_code')
                ->update(['course_code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $t) {
            foreach (['course_code', 'is_self_finance'] as $c) {
                if (Schema::hasColumn('programs', $c)) $t->dropColumn($c);
            }
        });
        Schema::table('students', function (Blueprint $t) {
            if (Schema::hasColumn('students', 'student_code')) $t->dropColumn('student_code');
        });
        Schema::table('admissions', function (Blueprint $t) {
            foreach (['file_no', 'record_no'] as $c) {
                if (Schema::hasColumn('admissions', $c)) $t->dropColumn($c);
            }
        });
    }
};
