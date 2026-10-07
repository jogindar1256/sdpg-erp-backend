<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * subjects.entry_type — 'subject' | 'stream'.
 *
 * Internal marker, never shown in the UI: rows registered under a B.Ed
 * course are streams, everything else is a subject. Set by the API from the
 * course, not sent by the client.
 *
 * Named entry_type because subjects.type already exists (compulsory /
 * optional / elective / practical / project) and drives exam-fee logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('subjects')) {
            return;
        }

        if (!Schema::hasColumn('subjects', 'entry_type')) {
            Schema::table('subjects', function (Blueprint $t) {
                $t->string('entry_type', 20)->default('subject');
            });
        }

        // Backfill: existing rows under a B.Ed course are streams.
        DB::statement(
            "UPDATE subjects SET entry_type = 'stream'
             WHERE program_id IN (
                 SELECT id FROM programs
                 WHERE level = 'BEd'
                    OR UPPER(REGEXP_REPLACE(COALESCE(short_name, ''), '[^A-Za-z]', '', 'g')) LIKE '%BED%'
             )"
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('subjects') && Schema::hasColumn('subjects', 'entry_type')) {
            Schema::table('subjects', function (Blueprint $t) {
                $t->dropColumn('entry_type');
            });
        }
    }
};
