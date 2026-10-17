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

        // semester_registrations was dropped (2026_10_17_120000) and its
        // create migration removed — its constraint swap no longer applies.
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
    }
};
