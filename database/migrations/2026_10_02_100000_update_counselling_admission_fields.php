<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── admission_category: Regular/Private -> category vocabulary ──
        DB::statement(<<<'SQL'
            DO $$
            DECLARE r record;
            BEGIN
                FOR r IN
                    SELECT con.conname
                    FROM pg_constraint con
                    JOIN pg_class rel ON rel.oid = con.conrelid
                    JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = ANY(con.conkey)
                    WHERE rel.relname = 'counselling_reports'
                      AND con.contype = 'c'
                      AND att.attname = 'admission_category'
                LOOP
                    EXECUTE format('ALTER TABLE counselling_reports DROP CONSTRAINT %I', r.conname);
                END LOOP;
            END $$;
        SQL);

        // Old Regular/Private values don't mean anything under the new
        // vocabulary — reset rather than leave a stale mismatched value.
        DB::table('counselling_reports')
            ->whereIn('admission_category', ['Regular', 'Private'])
            ->update(['admission_category' => 'General']);

        DB::statement("ALTER TABLE counselling_reports ADD CONSTRAINT counselling_reports_admission_category_check CHECK (admission_category IN ('General','OBC','SC','ST','EWS'))");

        // ── admission_type: new Regular/Private column ──────────────────
        Schema::table('counselling_reports', function (Blueprint $table) {
            $table->string('admission_type')->default('Regular')->after('admission_category');
        });
        DB::statement("ALTER TABLE counselling_reports ADD CONSTRAINT counselling_reports_admission_type_check CHECK (admission_type IN ('Regular','Private'))");

        // ── drop allotment_no ("Enclose Cut off Mark Sheet / Allotment Letter") ──
        Schema::table('counselling_reports', function (Blueprint $table) {
            $table->dropColumn('allotment_no');
        });
    }

    public function down(): void
    {
        Schema::table('counselling_reports', function (Blueprint $table) {
            $table->string('allotment_no')->nullable();
        });

        DB::statement('ALTER TABLE counselling_reports DROP CONSTRAINT IF EXISTS counselling_reports_admission_type_check');
        Schema::table('counselling_reports', function (Blueprint $table) {
            $table->dropColumn('admission_type');
        });

        DB::statement('ALTER TABLE counselling_reports DROP CONSTRAINT IF EXISTS counselling_reports_admission_category_check');
        DB::table('counselling_reports')->update(['admission_category' => 'Regular']);
        DB::statement("ALTER TABLE counselling_reports ADD CONSTRAINT counselling_reports_admission_category_check CHECK (admission_category IN ('Regular','Private'))");
    }
};
