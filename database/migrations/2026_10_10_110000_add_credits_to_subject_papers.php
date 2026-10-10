<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Whole-number credit (0-99) per subject paper, shown beside Paper Name.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('subject_papers', 'credits')) {
            Schema::table('subject_papers', function (Blueprint $table) {
                $table->smallInteger('credits')->nullable()->after('paper_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subject_papers', 'credits')) {
            Schema::table('subject_papers', function (Blueprint $table) {
                $table->dropColumn('credits');
            });
        }
    }
};
