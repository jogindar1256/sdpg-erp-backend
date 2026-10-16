<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// P.G. / B.Ed. Optional papers are split into two lists: Part 6
// "Optional Paper -1" offers group 1, "Optional Paper -2" offers group 2.
// NULL for every non-Optional paper. Existing Optional papers start NULL
// and stay hidden from Part 6 until the office assigns a group.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('subject_papers', 'optional_group')) {
            Schema::table('subject_papers', function (Blueprint $table) {
                $table->smallInteger('optional_group')->nullable()->after('selection_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subject_papers', 'optional_group')) {
            Schema::table('subject_papers', function (Blueprint $table) {
                $table->dropColumn('optional_group');
            });
        }
    }
};
