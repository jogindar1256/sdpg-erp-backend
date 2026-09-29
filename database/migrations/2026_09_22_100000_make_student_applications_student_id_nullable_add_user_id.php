<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop NOT NULL directly — keeps the existing FK constraint on
        // student_id intact, only relaxes the nullability.
        DB::statement('ALTER TABLE student_applications ALTER COLUMN student_id DROP NOT NULL');

        Schema::table('student_applications', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('student_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
        // Not reversible safely — rows created while student_id was
        // nullable may hold nulls that would violate a re-applied NOT NULL
        // constraint. Restoring it requires a manual backfill first.
    }
};
