<?php
// ─────────────────────────────────────────────────────────────────────────────
// AMENDMENT MODULE MIGRATIONS
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── amendment_logs ────────────────────────────────────────────────
        // Schema::create('amendment_logs', function (Blueprint $t) {
        //     $t->id();
        //     $t->unsignedBigInteger('student_id');
        //     $t->unsignedBigInteger('admission_id')->nullable();
        //     $t->string('action_type');        // ModifyData|SubjectChange|MobileUpdate|TCMigrationUpdate|FeeValueChange|FeeReset|BlockUnblock|AdmissionCancel|etc.
        //     $t->json('changed_data')->nullable();
        //     $t->string('ref_no')->nullable();
        //     $t->string('modified_by')->nullable();
        //     $t->string('approved_by')->nullable();
        //     $t->timestamp('approved_at')->nullable();
        //     $t->string('status', 20)->default('Pending'); // Pending|Approved|Rejected|Completed
        //     $t->timestamps();

        //     $t->foreign('student_id')->references('id')->on('students');
        //     $t->index(['student_id', 'action_type', 'status']);
        // });

        // ── student_restrictions ──────────────────────────────────────────
        Schema::create('student_restrictions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->string('reason');
            $t->text('other_reason')->nullable();
            $t->string('restriction_by')->nullable();
            $t->string('authority_name')->nullable();
            $t->string('submitted_by')->nullable();
            $t->string('approved_by')->nullable();
            $t->timestamps();

            $t->foreign('student_id')->references('id')->on('students');
        });

        // Add is_blocked + is_restricted flags to students (if not already present)
        if (Schema::hasTable('students')) {
            Schema::table('students', function (Blueprint $t) {
                if (!Schema::hasColumn('students', 'is_blocked'))
                    $t->boolean('is_blocked')->default(false)->after('mobile');
                if (!Schema::hasColumn('students', 'is_restricted'))
                    $t->boolean('is_restricted')->default(false)->after('is_blocked');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_restrictions');
        // Schema::dropIfExists('amendment_logs');
        if (Schema::hasTable('students')) {
            Schema::table('students', function (Blueprint $t) {
                $t->dropColumnIfExists('is_blocked');
                $t->dropColumnIfExists('is_restricted');
            });
        }
    }
};
