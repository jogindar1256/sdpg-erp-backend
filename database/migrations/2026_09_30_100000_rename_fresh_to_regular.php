<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── student_applications.application_type ──────────────────────
        DB::statement(<<<'SQL'
            DO $$
            DECLARE r record;
            BEGIN
                FOR r IN
                    SELECT con.conname
                    FROM pg_constraint con
                    JOIN pg_class rel ON rel.oid = con.conrelid
                    JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = ANY(con.conkey)
                    WHERE rel.relname = 'student_applications'
                      AND con.contype = 'c'
                      AND att.attname = 'application_type'
                LOOP
                    EXECUTE format('ALTER TABLE student_applications DROP CONSTRAINT %I', r.conname);
                END LOOP;
            END $$;
        SQL);

        DB::table('student_applications')
            ->where('application_type', 'fresh')
            ->update(['application_type' => 'regular']);

        DB::statement("ALTER TABLE student_applications ADD CONSTRAINT student_applications_application_type_check CHECK (application_type IN ('regular','back_paper','semester_upgrade'))");

        // Swap the partial unique index from 2026_09_29_100000 — its name
        // and predicate both referenced 'fresh'.
        DB::statement('DROP INDEX IF EXISTS student_applications_fresh_unique_active');
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS student_applications_regular_unique_active
            ON student_applications (direct_registration_id)
            WHERE application_type = 'regular'
              AND status NOT IN ('cancelled', 'rejected')
              AND deleted_at IS NULL
              AND direct_registration_id IS NOT NULL
        ");

        // ── semester_registrations.registration_type ────────────────────
        // Dead column (see doc comment) — constraint swap only, backfill
        // included defensively in case any row somehow has a non-null
        // value here despite no code path setting one.
        DB::statement(<<<'SQL'
            DO $$
            DECLARE r record;
            BEGIN
                FOR r IN
                    SELECT con.conname
                    FROM pg_constraint con
                    JOIN pg_class rel ON rel.oid = con.conrelid
                    JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = ANY(con.conkey)
                    WHERE rel.relname = 'semester_registrations'
                      AND con.contype = 'c'
                      AND att.attname = 'registration_type'
                LOOP
                    EXECUTE format('ALTER TABLE semester_registrations DROP CONSTRAINT %I', r.conname);
                END LOOP;
            END $$;
        SQL);

        DB::table('semester_registrations')
            ->whereIn('registration_type', ['fresh', 'ex_student'])
            ->update(['registration_type' => 'regular']);

        DB::statement("ALTER TABLE semester_registrations ADD CONSTRAINT semester_registrations_registration_type_check CHECK (registration_type IN ('regular','back_paper'))");
    }

    public function down(): void
    {
        // student_applications
        DB::statement('DROP INDEX IF EXISTS student_applications_regular_unique_active');
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS student_applications_fresh_unique_active
            ON student_applications (direct_registration_id)
            WHERE application_type = 'fresh'
              AND status NOT IN ('cancelled', 'rejected')
              AND deleted_at IS NULL
              AND direct_registration_id IS NOT NULL
        ");
        DB::statement('ALTER TABLE student_applications DROP CONSTRAINT IF EXISTS student_applications_application_type_check');
        DB::table('student_applications')
            ->where('application_type', 'regular')
            ->update(['application_type' => 'fresh']);
        DB::statement("ALTER TABLE student_applications ADD CONSTRAINT student_applications_application_type_check CHECK (application_type IN ('fresh','back_paper','semester_upgrade'))");

        DB::statement('ALTER TABLE semester_registrations DROP CONSTRAINT IF EXISTS semester_registrations_registration_type_check');
        DB::table('semester_registrations')
            ->where('registration_type', 'regular')
            ->update(['registration_type' => 'fresh']);
        DB::statement("ALTER TABLE semester_registrations ADD CONSTRAINT semester_registrations_registration_type_check CHECK (registration_type IN ('fresh','ex_student','back_paper'))");
    }
};
