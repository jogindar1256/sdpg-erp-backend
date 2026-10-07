<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admissions') && !Schema::hasColumn('admissions', 'account_no')) {
            DB::statement('ALTER TABLE admissions ADD COLUMN account_no varchar(255) NULL');
        }
    }

    public function down(): void
    {
        // Left in place: the column may predate this migration on older databases.
    }
};
