<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
        DELETE FROM bank_branches b1
        USING bank_branches b2
        WHERE b1.ifsc_code = b2.ifsc_code
          AND b1.branch_name IS NOT DISTINCT FROM b2.branch_name
          AND b1.city        IS NOT DISTINCT FROM b2.city
          AND b1.id > b2.id
    ");

        DB::statement('ALTER TABLE bank_branches DROP CONSTRAINT IF EXISTS bank_branches_ifsc_code_unique');
        DB::statement('DROP INDEX IF EXISTS bank_branches_ifsc_code_unique');
        DB::statement('CREATE INDEX IF NOT EXISTS bank_branches_ifsc_code_index ON bank_branches (ifsc_code)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS bank_branches_ifsc_branch_city_unique ON bank_branches (ifsc_code, branch_name, city)');
    }

    public function down(): void
    {
        Schema::table('bank_branches', function (Blueprint $table) {
            $table->dropUnique('bank_branches_ifsc_branch_city_unique');
            $table->dropIndex(['ifsc_code']);
            // Intentionally NOT restoring unique(ifsc_code): the data
            // legitimately contains shared IFSCs; restoring it would fail.
        });
    }
};
