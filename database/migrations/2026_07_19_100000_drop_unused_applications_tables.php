<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the "applications" system (2026_06_18_123308_applications.php) —
 * a parallel, normalized schema to student_applications that was never
 * actually wired up: nothing anywhere ever inserts a row into `applications`,
 * so the handful of AmendmentController queries against it and its children
 * (application_tc, application_migration, application_subjects) always
 * operated on an empty/non-existent parent and never did anything. The real,
 * live application system is `student_applications` (JSONB part_1..part_8).
 *
 * AmendmentController's updateTcGet/updateTcStore/holdCancelStore have been
 * rewired to student_applications in this same change; updatePaperStore
 * (application_subjects) had no equivalent to rewire to and now returns a
 * clear "not available" response instead of silently doing nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Children first (FK to applications.id), then the parent.
        Schema::dropIfExists('application_holds');
        Schema::dropIfExists('application_documents');
        Schema::dropIfExists('application_subjects');
        Schema::dropIfExists('application_bank');
        Schema::dropIfExists('application_migration');
        Schema::dropIfExists('application_tc');
        Schema::dropIfExists('application_education');
        Schema::dropIfExists('applications');
    }

    public function down(): void
    {
        // Deliberately not recreated — these tables held no data and no
        // code path other than the removed dead code referenced them.
        // Re-run 2026_06_18_123308_applications.php manually if ever needed.
    }
};
