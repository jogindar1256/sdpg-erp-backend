<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── users — already enforced from create_users_table.php;
        if (Schema::hasTable('users')) {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique_idx ON users (email) WHERE email IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_mobile_unique_idx ON users (mobile) WHERE mobile IS NOT NULL');
        }

        // ── students — global, no cancelled carve-out (see doc comment).
        if (Schema::hasTable('students')) {
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS students_email_unique_active
                ON students (email)
                WHERE email IS NOT NULL AND deleted_at IS NULL
            ");
            DB::statement('
                CREATE UNIQUE INDEX IF NOT EXISTS students_mobile_unique_active
                ON students (mobile)
                WHERE deleted_at IS NULL
            ');
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS students_aadhar_unique_active
                ON students (aadhar_no)
                WHERE aadhar_no IS NOT NULL AND deleted_at IS NULL
            ");
        }

        // ── admissions — nothing to add; no email/mobile/aadhar_no columns
        // exist on this table (see doc comment).

        // ── direct_registrations — scoped per (session_year, reg_type),
        // cancelled rows excluded (see doc comment).
        if (Schema::hasTable('direct_registrations')) {
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS direct_registrations_email_unique_active
                ON direct_registrations (email, session_year, reg_type)
                WHERE status != 'cancelled' AND deleted_at IS NULL
            ");
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS direct_registrations_mobile_unique_active
                ON direct_registrations (mobile, session_year, reg_type)
                WHERE status != 'cancelled' AND deleted_at IS NULL
            ");
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS direct_registrations_aadhar_unique_active
                ON direct_registrations (aadhar_no, session_year, reg_type)
                WHERE aadhar_no IS NOT NULL AND status != 'cancelled' AND deleted_at IS NULL
            ");
        }

        // ── student_applications — structural link uniqueness, not a raw
        // email/mobile/aadhar column (see doc comment for why).
        if (Schema::hasTable('student_applications')) {
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS student_applications_fresh_unique_active
                ON student_applications (direct_registration_id)
                WHERE application_type = 'fresh'
                  AND status NOT IN ('cancelled', 'rejected')
                  AND deleted_at IS NULL
                  AND direct_registration_id IS NOT NULL
            ");
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS student_applications_reapply_unique_active
                ON student_applications (student_id, program_id, academic_year, application_type)
                WHERE application_type != 'fresh'
                  AND status NOT IN ('cancelled', 'rejected')
                  AND deleted_at IS NULL
                  AND student_id IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        foreach ([
            'users_email_unique_idx',
            'users_mobile_unique_idx',
            'students_email_unique_active',
            'students_mobile_unique_active',
            'students_aadhar_unique_active',
            'direct_registrations_email_unique_active',
            'direct_registrations_mobile_unique_active',
            'direct_registrations_aadhar_unique_active',
            'student_applications_fresh_unique_active',
            'student_applications_reapply_unique_active',
        ] as $idx) {
            DB::statement("DROP INDEX IF EXISTS {$idx}");
        }
    }
};
