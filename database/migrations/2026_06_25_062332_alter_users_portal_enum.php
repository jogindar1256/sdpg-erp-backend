<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // PostgreSQL — change enum to: college, student, super_admin
        // (university removed — this is a college system, not a university system)
        DB::statement("
            ALTER TABLE users
            DROP CONSTRAINT IF EXISTS users_portal_check
        ");

        DB::statement("
            ALTER TABLE users
            ADD CONSTRAINT users_portal_check
            CHECK (portal IN ('college', 'student', 'super_admin'))
        ");

        // Update any existing 'university' rows to 'college' to avoid constraint failure
        DB::table('users')
            ->where('portal', 'university')
            ->update(['portal' => 'college']);
    }

    public function down(): void
    {
        // Restore original enum including 'university'
        DB::statement("
            ALTER TABLE users
            DROP CONSTRAINT IF EXISTS users_portal_check
        ");

        DB::statement("
            ALTER TABLE users
            ADD CONSTRAINT users_portal_check
            CHECK (portal IN ('college', 'student', 'university', 'super_admin'))
        ");
    }
};