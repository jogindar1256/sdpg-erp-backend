<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Standardizes every "gender" field on the three categories the Government
 * of India legally recognizes — Male, Female, Transgender (per NALSA v.
 * Union of India, 2014, and the Transgender Persons (Protection of Rights)
 * Act, 2019). Before this, the codebase had four different spellings in
 * live use: students.gender used lowercase 'other', counselling_reports.gender
 * used 'Trans', direct_registrations.gender was an unconstrained free string
 * (could hold anything the frontend happened to send, including "Trans" or
 * "Other"), and registration_fees.amounts JSON keys were built from
 * lowercase(gender) — so "Trans" produced a "trans_*" key.
 *
 * This migration backfills existing rows to the new spelling BEFORE
 * tightening the constraints, and rekeys the one place a label change would
 * otherwise silently break an existing lookup (registration_fees.amounts).
 * Every statement is guarded/idempotent so it's safe to run regardless of
 * what state a given environment is already in.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── students.gender: lowercase enum, 'other' → 'transgender' ───────
        if (Schema::hasTable('students') && Schema::hasColumn('students', 'gender')) {
            DB::table('students')->where('gender', 'other')->update(['gender' => 'transgender']);
            DB::statement('ALTER TABLE students DROP CONSTRAINT IF EXISTS students_gender_check');
            DB::statement("ALTER TABLE students ADD CONSTRAINT students_gender_check CHECK (gender IN ('male','female','transgender'))");
        }

        // ── counselling_reports.gender: Title-Case enum, 'Trans' → 'Transgender' ─
        if (Schema::hasTable('counselling_reports') && Schema::hasColumn('counselling_reports', 'gender')) {
            DB::table('counselling_reports')->where('gender', 'Trans')->update(['gender' => 'Transgender']);
            DB::statement('ALTER TABLE counselling_reports DROP CONSTRAINT IF EXISTS counselling_reports_gender_check');
            DB::statement("ALTER TABLE counselling_reports ADD CONSTRAINT counselling_reports_gender_check CHECK (gender IN ('Male','Female','Transgender'))");
        }

        // ── direct_registrations.gender: previously an unconstrained free
        //    string (varchar(15), nullable) — it could hold literally
        //    anything the frontend ever sent (whitespace, stray casing,
        //    single letters, typos), not just the handful of spellings we
        //    knew about. A fixed whereIn() list of guesses isn't safe here —
        //    this failed in production because real rows had values outside
        //    that list. Normalize case-insensitively and catch every known
        //    variant; anything still unrecognized is set to NULL (allowed by
        //    the constraint) rather than guessed at or left to violate it.
        if (Schema::hasTable('direct_registrations') && Schema::hasColumn('direct_registrations', 'gender')) {
            DB::statement(<<<'SQL'
                UPDATE direct_registrations
                SET gender = CASE
                    WHEN lower(trim(gender)) IN ('male', 'm') THEN 'Male'
                    WHEN lower(trim(gender)) IN ('female', 'f') THEN 'Female'
                    WHEN lower(trim(gender)) IN ('transgender', 'trans', 'other', 't', 'o') THEN 'Transgender'
                    WHEN trim(gender) = '' THEN NULL
                    WHEN lower(trim(gender)) NOT IN ('male', 'female', 'transgender') THEN NULL
                    ELSE gender
                END
                WHERE gender IS NOT NULL
            SQL);
            DB::statement('ALTER TABLE direct_registrations DROP CONSTRAINT IF EXISTS direct_registrations_gender_check');
            DB::statement("ALTER TABLE direct_registrations ADD CONSTRAINT direct_registrations_gender_check CHECK (gender IS NULL OR gender IN ('Male','Female','Transgender'))");
        }

        // ── registration_fees.amounts: rekey any "trans_*" JSON keys to
        //    "transgender_*" so previously-configured fees for the third
        //    gender category are still found by resolveRegistrationFee(),
        //    which now builds its lookup key from the new label.
        if (Schema::hasTable('registration_fees') && Schema::hasColumn('registration_fees', 'amounts')) {
            DB::table('registration_fees')->whereNotNull('amounts')->orderBy('id')->each(function ($row) {
                $amounts = is_string($row->amounts) ? json_decode($row->amounts, true) : (array) $row->amounts;
                if (!is_array($amounts)) {
                    return;
                }
                $changed = false;
                $rekeyed = [];
                foreach ($amounts as $key => $value) {
                    if (str_starts_with($key, 'trans_')) {
                        $key = 'transgender_' . substr($key, strlen('trans_'));
                        $changed = true;
                    }
                    $rekeyed[$key] = $value;
                }
                if ($changed) {
                    DB::table('registration_fees')->where('id', $row->id)->update(['amounts' => json_encode($rekeyed)]);
                }
            });
        }
    }

    public function down(): void
    {
        // No-op — see the standing reconciliation-migration convention in
        // this codebase: down() is intentionally not implemented so a bad
        // rollback can't silently resurrect a non-compliant gender value.
    }
};
