<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * amendment_logs was designed in 2026_06_18_130439_create_amendment_table.php
 * but left commented out — meaning AuthorizationController's misc-activity
 * queue and blockUnblockAction's audit trail were writing/reading a table
 * that was never created. This applies the originally-designed schema
 * (unchanged) so that code has somewhere real to write to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('amendment_logs')) {
            return;
        }

        Schema::create('amendment_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('admission_id')->nullable();
            $t->string('action_type');        // ModifyData|SubjectChange|MobileUpdate|TCMigrationUpdate|FeeValueChange|FeeReset|BlockUnblock|AdmissionCancel|etc.
            $t->json('changed_data')->nullable();
            $t->string('ref_no')->nullable();
            $t->string('modified_by')->nullable();
            $t->string('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->string('status', 20)->default('Pending'); // Pending|Approved|Rejected|Completed
            $t->timestamps();

            $t->foreign('student_id')->references('id')->on('students');
            $t->index(['student_id', 'action_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amendment_logs');
    }
};
