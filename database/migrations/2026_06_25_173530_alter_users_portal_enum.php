<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: migrate data BEFORE changing the constraint
        // so no rows violate the new constraint during the transition
        DB::table('users')
            ->whereIn('portal', ['university', 'super_admin'])
            ->update(['portal' => 'college']);

        // Step 2: drop old constraint (if any)
        DB::statement("
            ALTER TABLE users
            DROP CONSTRAINT IF EXISTS users_portal_check
        ");

        // Step 3: new constraint — only 'college' (employees) and 'student'
        DB::statement("
            ALTER TABLE users
            ADD CONSTRAINT users_portal_check
            CHECK (portal IN ('college', 'student'))
        ");
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
