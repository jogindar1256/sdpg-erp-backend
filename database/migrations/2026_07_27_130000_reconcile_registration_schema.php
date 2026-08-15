<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Second reconciliation pass — same reasoning as
 * 2026_07_27_120000_reconcile_course_settings_schema.php, extended to the
 * registration/admission/student side of the schema: users, students,
 * student_applications, admissions, programs, direct_registrations,
 * bank_branches. All of these had "alter" migrations deleted during the
 * earlier consolidation on the (unverifiable from this environment)
 * assumption that they'd already run against production. Every statement
 * here is guarded so it's a no-op wherever the column/constraint already
 * exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── users (portal enum: college|student only) ────────────────────
        if (Schema::hasTable('users')) {
            // Normalize any stray legacy rows before tightening the constraint.
            DB::table('users')->whereIn('portal', ['university', 'super_admin'])
                ->update(['portal' => 'college']);

            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_portal_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_portal_check CHECK (portal IN ('college', 'student'))");
        }

        // ── programs (from add_admission_numbering_columns +
        //    added_column_in_direct_registration_and_program) ─────────────
        if (Schema::hasTable('programs')) {
            Schema::table('programs', function (Blueprint $t) {
                if (!Schema::hasColumn('programs', 'course_code')) {
                    $t->string('course_code', 2)->nullable();
                }
                if (!Schema::hasColumn('programs', 'is_self_finance')) {
                    $t->boolean('is_self_finance')->default(false);
                }
                if (!Schema::hasColumn('programs', 'full_name')) {
                    $t->string('full_name')->nullable();
                }
            });

            DB::table('programs')->whereNull('full_name')->update(['full_name' => DB::raw('name')]);

            $map = ['BA' => '01', 'BSC' => '02', 'BED' => '03', 'MA' => '04', 'MSC' => '05'];
            foreach ($map as $short => $code) {
                DB::table('programs')
                    ->whereRaw("upper(regexp_replace(short_name, '[^A-Za-z]', '', 'g')) = ?", [$short])
                    ->whereNull('course_code')
                    ->update(['course_code' => $code]);
            }
        }

        // ── students (from self_registration_colums, add_admission_numbering_columns,
        //    add_ddurn_family_id_to_students, add_confirmation_fields...) ──
        if (Schema::hasTable('students')) {
            Schema::table('students', function (Blueprint $t) {
                if (!Schema::hasColumn('students', 'phone_verified')) {
                    $t->boolean('phone_verified')->default(false);
                }
                if (!Schema::hasColumn('students', 'email_verified')) {
                    $t->boolean('email_verified')->default(false);
                }
                if (!Schema::hasColumn('students', 'student_code')) {
                    $t->string('student_code', 20)->nullable()->index();
                }
                if (!Schema::hasColumn('students', 'abc_id')) {
                    $t->string('abc_id')->nullable();
                }
                if (!Schema::hasColumn('students', 'ddurn')) {
                    $t->string('ddurn', 50)->nullable();
                }
                if (!Schema::hasColumn('students', 'family_id')) {
                    $t->string('family_id', 50)->nullable();
                }
                if (!Schema::hasColumn('students', 'is_confirmed')) {
                    $t->boolean('is_confirmed')->default(false);
                }
                if (!Schema::hasColumn('students', 'confirmed_at')) {
                    $t->timestamp('confirmed_at')->nullable();
                }
                if (!Schema::hasColumn('students', 'confirmed_application_id')) {
                    $t->unsignedBigInteger('confirmed_application_id')->nullable();
                }
            });

            // reg_source was added then deliberately removed (business
            // decision reversed) — drop it if it's still lingering live.
            if (Schema::hasColumn('students', 'reg_source')) {
                Schema::table('students', function (Blueprint $t) {
                    $t->dropColumn('reg_source');
                });
            }
        }

        // ── student_applications (from add_part_column_to_students,
        //    add_part_columns_to_student_application, cleanup_and_add_backpaper_columns) ──
        if (Schema::hasTable('student_applications')) {
            Schema::table('student_applications', function (Blueprint $t) {
                foreach (['part_1', 'part_2', 'part_3', 'part_4', 'part_5', 'part_6', 'part_7', 'part_8'] as $col) {
                    if (!Schema::hasColumn('student_applications', $col)) {
                        $t->jsonb($col)->nullable();
                    }
                }
                if (!Schema::hasColumn('student_applications', 'fee_amount')) {
                    $t->decimal('fee_amount', 10, 2)->nullable();
                }
                if (!Schema::hasColumn('student_applications', 'fee_paid')) {
                    $t->boolean('fee_paid')->default(false);
                }
                if (!Schema::hasColumn('student_applications', 'paid_at')) {
                    $t->timestamp('paid_at')->nullable();
                }
                if (!Schema::hasColumn('student_applications', 'payment_ref')) {
                    $t->string('payment_ref', 100)->nullable();
                }
                if (!Schema::hasColumn('student_applications', 'razorpay_order_id')) {
                    $t->string('razorpay_order_id', 100)->nullable();
                }
                if (!Schema::hasColumn('student_applications', 'fee_receipt_id')) {
                    $t->unsignedBigInteger('fee_receipt_id')->nullable();
                }
            });

            // Legacy naming scheme — confirmed nothing in the codebase reads
            // or writes these anymore. Drop if still lingering live.
            Schema::table('student_applications', function (Blueprint $t) {
                foreach ([
                    'part_personal', 'part_education', 'part_tc', 'part_migration',
                    'part_bank', 'part_subjects', 'part_documents', 'part_declaration',
                ] as $col) {
                    if (Schema::hasColumn('student_applications', $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }

        // ── admissions (from self_registration_colums, add_admission_numbering_columns,
        //    fix_direct_registration_columns) ──────────────────────────────
        if (Schema::hasTable('admissions')) {
            Schema::table('admissions', function (Blueprint $t) {
                if (!Schema::hasColumn('admissions', 'payment_status')) {
                    $t->string('payment_status', 10)->default('pending');
                }
                if (!Schema::hasColumn('admissions', 'razorpay_order_id')) {
                    $t->string('razorpay_order_id', 100)->nullable();
                }
                if (!Schema::hasColumn('admissions', 'razorpay_payment_id')) {
                    $t->string('razorpay_payment_id', 100)->nullable();
                }
                if (!Schema::hasColumn('admissions', 'paid_at')) {
                    $t->timestamp('paid_at')->nullable();
                }
                if (!Schema::hasColumn('admissions', 'temp_password_plain')) {
                    $t->string('temp_password_plain', 30)->nullable();
                }
                if (!Schema::hasColumn('admissions', 'file_no')) {
                    $t->string('file_no', 20)->nullable()->index();
                }
                if (!Schema::hasColumn('admissions', 'record_no')) {
                    $t->unsignedInteger('record_no')->nullable()->index();
                }
            });

            // Correct the default going forward (idempotent — no-op if
            // already 'pending') and fix any rows wrongly marked paid with
            // no actual payment evidence.
            DB::statement("ALTER TABLE admissions ALTER COLUMN payment_status SET DEFAULT 'pending'");
            DB::table('admissions')
                ->where('payment_status', 'paid')
                ->whereNull('paid_at')
                ->whereNull('razorpay_payment_id')
                ->update(['payment_status' => 'pending']);
        }

        // ── direct_registrations (from added_column_in_direct_registration_and_program,
        //    add_confirmation_fields...) ────────────────────────────────────
        if (Schema::hasTable('direct_registrations')) {
            Schema::table('direct_registrations', function (Blueprint $t) {
                if (!Schema::hasColumn('direct_registrations', 'student_id')) {
                    $t->unsignedBigInteger('student_id')->nullable()->index();
                }
                if (!Schema::hasColumn('direct_registrations', 'fee_amount')) {
                    $t->decimal('fee_amount', 10, 2)->default(0);
                }
                if (!Schema::hasColumn('direct_registrations', 'course_group')) {
                    $t->string('course_group', 50)->nullable();
                }
                if (!Schema::hasColumn('direct_registrations', 'receipt_no')) {
                    $t->string('receipt_no', 40)->nullable()->index();
                }
                if (!Schema::hasColumn('direct_registrations', 'pdf_path')) {
                    $t->string('pdf_path')->nullable();
                }
                if (!Schema::hasColumn('direct_registrations', 'cancelled_by')) {
                    $t->unsignedBigInteger('cancelled_by')->nullable();
                }
                if (!Schema::hasColumn('direct_registrations', 'cancelled_at')) {
                    $t->timestamp('cancelled_at')->nullable();
                }
                if (!Schema::hasColumn('direct_registrations', 'cancel_reason')) {
                    $t->text('cancel_reason')->nullable();
                }
            });
        }

        // ── bank_branches (from alter_bank_branches_table) ────────────────
        if (Schema::hasTable('bank_branches')) {
            // De-dup exact (ifsc_code, branch_name, city) matches before
            // enforcing the composite unique index.
            DB::statement("
                DELETE FROM bank_branches b1
                USING bank_branches b2
                WHERE b1.ifsc_code = b2.ifsc_code
                  AND b1.branch_name IS NOT DISTINCT FROM b2.branch_name
                  AND b1.city        IS NOT DISTINCT FROM b2.city
                  AND b1.id > b2.id
            ");

            // Old single-column unique(ifsc_code) no longer holds — real
            // bank data legitimately has multiple branches per IFSC.
            DB::statement('ALTER TABLE bank_branches DROP CONSTRAINT IF EXISTS bank_branches_ifsc_code_unique');
            DB::statement('DROP INDEX IF EXISTS bank_branches_ifsc_code_unique');
            DB::statement('CREATE INDEX IF NOT EXISTS bank_branches_ifsc_code_index ON bank_branches (ifsc_code)');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS bank_branches_ifsc_branch_city_unique ON bank_branches (ifsc_code, branch_name, city)');
        }
    }

    public function down(): void
    {
        // Intentionally a no-op — see 2026_07_27_120000_reconcile_course_settings_schema.php.
    }
};
