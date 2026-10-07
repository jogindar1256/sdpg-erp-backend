<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admissions become the office's working record from the moment a form is
 * submitted, instead of only appearing after the fee is paid.
 *
 *   submit            → admissions row, status 'pending'  (no student yet)
 *   office approves   → 'approved'
 *   reject / hold     → 'rejected' / 'on_hold' (already supported)
 *   fee paid + college verifies the receipt → students row created,
 *                       admissions.student_id filled, status 'active'
 *
 * Schema changes:
 *   admissions.student_id            NOT NULL → nullable (pending rows have no student)
 *   admissions.user_id               new — the applicant's users.id
 *   admissions.direct_registration_id new — the registration it came from
 *   admissions.applicant_info        new jsonb — name/father/mobile… snapshot
 *                                    taken at submit, for lists that have no
 *                                    students row to read from yet
 *   admissions.status                check constraint gains 'pending', 'approved'
 *   fee_receipts.student_id          NOT NULL → nullable (receipt is issued
 *                                    at payment, before the student row exists)
 *
 * Backfill: every regular / semester-upgrade application that is already
 * submitted but has no admissions row gets one.
 */
return new class extends Migration
{
    private const STATUSES_NEW = "'active','cancelled','on_hold','passed_out','transferred','rejected','pending','approved'";
    private const STATUSES_OLD = "'active','cancelled','on_hold','passed_out','transferred','rejected'";

    public function up(): void
    {
        if (!Schema::hasTable('admissions')) {
            return;
        }

        DB::statement('ALTER TABLE admissions ALTER COLUMN student_id DROP NOT NULL');
        if (Schema::hasTable('fee_receipts')) {
            DB::statement('ALTER TABLE fee_receipts ALTER COLUMN student_id DROP NOT NULL');
        }

        if (!Schema::hasColumn('admissions', 'user_id')) {
            DB::statement('ALTER TABLE admissions ADD COLUMN user_id bigint NULL REFERENCES users(id) ON DELETE SET NULL');
            DB::statement('CREATE INDEX IF NOT EXISTS admissions_user_id_index ON admissions (user_id)');
        }
        if (!Schema::hasColumn('admissions', 'direct_registration_id')) {
            DB::statement('ALTER TABLE admissions ADD COLUMN direct_registration_id bigint NULL');
        }
        if (!Schema::hasColumn('admissions', 'applicant_info')) {
            DB::statement("ALTER TABLE admissions ADD COLUMN applicant_info jsonb NOT NULL DEFAULT '{}'::jsonb");
        }

        $this->replaceStatusCheck(self::STATUSES_NEW);

        // user_id for admissions that already exist (from their student).
        DB::statement(
            'UPDATE admissions a SET user_id = s.user_id
             FROM students s WHERE s.id = a.student_id AND a.user_id IS NULL'
        );

        // Backfill pending/approved/held/rejected admissions for applications
        // that were submitted before this change.
        DB::statement(<<<'SQL'
            INSERT INTO admissions (
                organization_id, student_id, user_id, direct_registration_id, program_id, application_id,
                academic_year, semester_no, admission_type, admission_no, admission_date,
                is_verified, status, applicant_info, created_at, updated_at
            )
            SELECT
                sa.organization_id,
                NULL::bigint,  -- student is attached on confirmation, never before
                COALESCE(sa.user_id, s.user_id),
                sa.direct_registration_id,
                sa.program_id,
                sa.id,
                sa.academic_year,
                sa.semester_no,
                CASE sa.application_type WHEN 'semester_upgrade' THEN 'upgrade' ELSE 'regular' END,
                sa.academic_year || '-' || LPAD(sa.id::text, 6, '0'),
                COALESCE(sa.declaration_at, sa.created_at, NOW())::date,
                false,
                CASE sa.status
                    WHEN 'approved' THEN 'approved'
                    WHEN 'on_hold'  THEN 'on_hold'
                    WHEN 'rejected' THEN 'rejected'
                    ELSE 'pending'
                END,
                jsonb_strip_nulls(jsonb_build_object(
                    'name', dr.name,
                    'father_name', dr.father_name,
                    'mother_name', dr.mother_name,
                    'dob', dr.dob,
                    'gender', dr.gender,
                    'category', dr.category,
                    'mobile', dr.mobile,
                    'email', dr.email,
                    'aadhar_no', dr.aadhar_no,
                    'state', dr.domestic_state,
                    'registration_no', dr.registration_no
                )),
                COALESCE(sa.created_at, NOW()),
                COALESCE(sa.updated_at, NOW())
            FROM student_applications sa
            LEFT JOIN students s ON s.id = sa.student_id
            LEFT JOIN direct_registrations dr ON dr.id = sa.direct_registration_id
            WHERE sa.deleted_at IS NULL
              AND sa.application_type IN ('regular', 'semester_upgrade')
              AND sa.status IN ('submitted', 'under_review', 'approved', 'on_hold', 'rejected')
              AND NOT EXISTS (SELECT 1 FROM admissions a WHERE a.application_id = sa.id)
              AND NOT EXISTS (
                  SELECT 1 FROM admissions a2
                  WHERE a2.admission_no = sa.academic_year || '-' || LPAD(sa.id::text, 6, '0')
              )
        SQL);

        // admissions.payment_status was never set when the education fee was
        // paid, so the "Paid Fee" counter read 0. Bring it in line.
        DB::statement(<<<'SQL'
            UPDATE admissions a
               SET payment_status = 'paid', fee_status = 'Paid', paid_at = COALESCE(a.paid_at, sa.paid_at)
              FROM student_applications sa
             WHERE sa.id = a.application_id
               AND sa.fee_paid = true
               AND a.payment_status <> 'paid'
        SQL);
    }

    public function down(): void
    {
        if (!Schema::hasTable('admissions')) {
            return;
        }

        // Rows that only exist because of this workflow (no student yet)
        // cannot satisfy the old NOT NULL student_id.
        DB::statement('DELETE FROM admissions WHERE student_id IS NULL');
        DB::statement("UPDATE admissions SET status = 'active' WHERE status IN ('pending', 'approved')");
        $this->replaceStatusCheck(self::STATUSES_OLD);

        foreach (['applicant_info', 'direct_registration_id', 'user_id'] as $col) {
            if (Schema::hasColumn('admissions', $col)) {
                DB::statement("ALTER TABLE admissions DROP COLUMN {$col}");
            }
        }
        DB::statement('ALTER TABLE admissions ALTER COLUMN student_id SET NOT NULL');
        // fee_receipts.student_id is left nullable: receipts issued while the
        // new workflow was live may legitimately have none.
    }

    /** Drop whatever CHECK currently guards admissions.status and add a new one. */
    private function replaceStatusCheck(string $allowed): void
    {
        DB::statement(<<<'SQL'
            DO $$
            DECLARE r record;
            BEGIN
                FOR r IN
                    SELECT con.conname
                    FROM pg_constraint con
                    JOIN pg_class rel ON rel.oid = con.conrelid
                    JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = ANY(con.conkey)
                    WHERE rel.relname = 'admissions'
                      AND con.contype = 'c'
                      AND att.attname = 'status'
                LOOP
                    EXECUTE format('ALTER TABLE admissions DROP CONSTRAINT %I', r.conname);
                END LOOP;
            END $$;
        SQL);
        DB::statement("ALTER TABLE admissions ADD CONSTRAINT admissions_status_check CHECK (status IN ({$allowed}))");
    }
};
