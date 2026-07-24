<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business rule: a `students` row is created at registration time (needed —
 * student_applications.student_id and admissions.student_id are both NOT
 * NULL foreign keys, so the whole application flow has nowhere to hang
 * without one). But a row existing here does NOT mean "this is a real,
 * admitted student" — it only becomes that once the education fee is paid
 * AND the college accepts/verifies the application. `is_confirmed` is that
 * gate: false = provisional (registered, maybe mid-application), true =
 * actual admitted student (ApplicationController::updateStatus sets this on
 * approval, alongside creating the admissions row).
 *
 * Also adds cancellation tracking to direct_registrations so the college can
 * cancel a registration — which is what allows the SAME mobile/aadhar/abc_id
 * to register again for the SAME program/type (otherwise it's a duplicate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'is_confirmed')) {
                $table->boolean('is_confirmed')->default(false)->after('status');
            }
            if (!Schema::hasColumn('students', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable()->after('is_confirmed');
            }
            if (!Schema::hasColumn('students', 'confirmed_application_id')) {
                $table->foreignId('confirmed_application_id')->nullable()->after('confirmed_at')
                    ->constrained('student_applications')->nullOnDelete();
            }
        });

        Schema::table('direct_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('direct_registrations', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable()->after('remarks');
            }
            if (!Schema::hasColumn('direct_registrations', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            }
            if (!Schema::hasColumn('direct_registrations', 'cancel_reason')) {
                $table->text('cancel_reason')->nullable()->after('cancelled_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'confirmed_application_id')) {
                $table->dropConstrainedForeignId('confirmed_application_id');
            }
            foreach (['is_confirmed', 'confirmed_at'] as $c) {
                if (Schema::hasColumn('students', $c)) $table->dropColumn($c);
            }
        });

        Schema::table('direct_registrations', function (Blueprint $table) {
            foreach (['cancelled_by', 'cancelled_at', 'cancel_reason'] as $c) {
                if (Schema::hasColumn('direct_registrations', $c)) $table->dropColumn($c);
            }
        });
    }
};
