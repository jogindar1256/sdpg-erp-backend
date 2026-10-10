<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Favor In" is no longer set per fee structure filing. The fee head's own
 * fee_heads.in_favor_of (Fee Head Master) stays and is not touched here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fee_structures', 'in_favor_of')) {
            Schema::table('fee_structures', function (Blueprint $table) {
                $table->dropColumn('in_favor_of');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('fee_structures', 'in_favor_of')) {
            Schema::table('fee_structures', function (Blueprint $table) {
                $table->string('in_favor_of', 20)->nullable()->after('ddu_affiliated');
            });
        }
    }
};
