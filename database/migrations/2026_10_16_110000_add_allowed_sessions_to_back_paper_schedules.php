<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Back Paper Form Schedule "Years Allowed": the sessions whose students may
// fill this back paper form (JSON array of "YYYY-YYYY"). NULL / empty = no
// session restriction.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('back_paper_schedules', 'allowed_sessions')) {
            Schema::table('back_paper_schedules', function (Blueprint $table) {
                $table->json('allowed_sessions')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('back_paper_schedules', 'allowed_sessions')) {
            Schema::table('back_paper_schedules', function (Blueprint $table) {
                $table->dropColumn('allowed_sessions');
            });
        }
    }
};
