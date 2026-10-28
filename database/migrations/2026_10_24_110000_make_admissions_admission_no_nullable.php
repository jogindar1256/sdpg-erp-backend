<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Admission No is now generated together with the Student ID once the
 * education fee is paid / the final fee receipt is generated — not when the
 * application is submitted. Until then the pending admissions row has none.
 * (The unique index stays; Postgres allows many NULLs.)
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE admissions ALTER COLUMN admission_no DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE admissions SET admission_no = academic_year || '-' || LPAD(id::text, 6, '0') WHERE admission_no IS NULL");
        DB::statement('ALTER TABLE admissions ALTER COLUMN admission_no SET NOT NULL');
    }
};
