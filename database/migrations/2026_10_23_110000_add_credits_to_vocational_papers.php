<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Vocational & Co-Curriculum Paper Master: per-paper credits, same column
// shape as subject_papers.credits.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vocational_papers') && !Schema::hasColumn('vocational_papers', 'credits')) {
            Schema::table('vocational_papers', function (Blueprint $table) {
                $table->smallInteger('credits')->nullable()->after('paper_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vocational_papers', 'credits')) {
            Schema::table('vocational_papers', function (Blueprint $table) {
                $table->dropColumn('credits');
            });
        }
    }
};
