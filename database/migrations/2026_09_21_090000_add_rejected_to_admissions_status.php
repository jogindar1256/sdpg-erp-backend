<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DO $$
            DECLARE r record;
            BEGIN
                FOR r IN
                    SELECT con.conname
                    FROM pg_constraint con
                    JOIN pg_class rel ON rel.oid = con.conrelid
                    JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = ANY(con.conkey)
                    WHERE rel.relname = 'admissions'
                      AND con.contype = 'c'
                      AND att.attname = 'status'
                LOOP
                    EXECUTE format('ALTER TABLE admissions DROP CONSTRAINT %I', r.conname);
                END LOOP;
            END $$;
        SQL);

        DB::statement("ALTER TABLE admissions ADD CONSTRAINT admissions_status_check CHECK (status IN ('active','cancelled','on_hold','passed_out','transferred','rejected'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE admissions DROP CONSTRAINT IF EXISTS admissions_status_check");
        DB::statement("ALTER TABLE admissions ADD CONSTRAINT admissions_status_check CHECK (status IN ('active','cancelled','on_hold','passed_out','transferred'))");
    }
};
