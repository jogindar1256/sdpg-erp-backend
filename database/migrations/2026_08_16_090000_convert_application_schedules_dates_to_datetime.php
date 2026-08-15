<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The office wants a specific start/close TIME of day for admission windows,
// not just a date (e.g. admissions open 2026-08-20 09:00 AM and close
// 2026-09-05 05:00 PM). Postgres `date` columns silently truncate any time
// component, so this widens both columns to `timestamp` in place. Existing
// rows keep their date with time 00:00:00 — that's a real gap (nobody set a
// time for old records), not a bug; the admin should revisit old schedules
// if the exact opening time matters for them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_schedules', function (Blueprint $table) {
            $table->timestamp('start_admission')->nullable(false)->change();
            $table->timestamp('close_admission')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('application_schedules', function (Blueprint $table) {
            $table->date('start_admission')->nullable(false)->change();
            $table->date('close_admission')->nullable(false)->change();
        });
    }
};
