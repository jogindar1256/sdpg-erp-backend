<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * admissions.direct_registration_id — joined by the admission-verification
 * queue/list and the Manage Admission registration-code lookup. Separate
 * from 2026_10_29_100000 because that migration may already have run.
 *
 * CONCURRENTLY: no write lock while it builds; can't run in a transaction.
 * Skipped when an existing valid index already leads with this column.
 */
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('admissions') || !Schema::hasColumn('admissions', 'direct_registration_id')) return;

        $covered = DB::selectOne(
            "SELECT 1 FROM pg_index ix
               JOIN pg_class t ON t.oid = ix.indrelid
               JOIN pg_namespace n ON n.oid = t.relnamespace
               JOIN pg_attribute a ON a.attrelid = ix.indrelid AND a.attnum = ix.indkey[0]
              WHERE t.relname = 'admissions' AND n.nspname = current_schema()
                AND ix.indisvalid AND a.attname = 'direct_registration_id'"
        );
        if ($covered) return;

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS "admissions_direct_registration_id_index"');
        DB::statement('CREATE INDEX CONCURRENTLY "admissions_direct_registration_id_index" ON "admissions" ("direct_registration_id")');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS "admissions_direct_registration_id_index"');
    }
};
