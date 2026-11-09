<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Semester registrations.
 *
 * A student now registers for every semester (2..n) the same way semester 1 is
 * registered: one direct_registrations row per semester, with its own
 * registration_no and its own registration-fee payment.
 *
 *   semester_no   which semester this registration is for (1 = the original/fresh one)
 *   reg_group_id  groups all of one student's registrations — equals the id of the
 *                 first (semester 1) row. Every existing row becomes its own group.
 *
 * The identity unique indexes (email / mobile / aadhar per session + reg_type) would
 * reject a semester-2 row in the same session, so they are rebuilt with semester_no.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('direct_registrations')) {
            return;
        }

        Schema::table('direct_registrations', function (Blueprint $t) {
            if (!Schema::hasColumn('direct_registrations', 'semester_no')) {
                $t->unsignedSmallInteger('semester_no')->default(1);
            }
            if (!Schema::hasColumn('direct_registrations', 'reg_group_id')) {
                $t->unsignedBigInteger('reg_group_id')->nullable()->index();
            }
        });

        DB::statement('UPDATE direct_registrations SET reg_group_id = id WHERE reg_group_id IS NULL');

        foreach (['email', 'mobile', 'aadhar'] as $k) {
            DB::statement("DROP INDEX IF EXISTS direct_registrations_{$k}_unique_active");
        }
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS direct_registrations_email_unique_active
            ON direct_registrations (email, session_year, reg_type, semester_no)
            WHERE status != 'cancelled' AND deleted_at IS NULL
        ");
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS direct_registrations_mobile_unique_active
            ON direct_registrations (mobile, session_year, reg_type, semester_no)
            WHERE status != 'cancelled' AND deleted_at IS NULL
        ");
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS direct_registrations_aadhar_unique_active
            ON direct_registrations (aadhar_no, session_year, reg_type, semester_no)
            WHERE aadhar_no IS NOT NULL AND status != 'cancelled' AND deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        // Intentionally left in place: once semester > 1 registrations exist, the
        // original (narrower) unique indexes can no longer be recreated without
        // deleting real rows, and a rollback must not delete data.
    }
};
