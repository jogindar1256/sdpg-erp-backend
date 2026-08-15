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
        // ── student_restrictions ──────────────────────────────────────────
        // (is_blocked/is_restricted on `students` itself now live directly
        // in create_students_table.php — this file used to also alter that
        // table here, folded in as part of the migration cleanup.)
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
    }

    public function down(): void
    {
        Schema::dropIfExists('student_restrictions');
    }
};
