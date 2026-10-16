<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Semester Master "Research Paper" (Yes/No): when Yes, the U.G. application
// Part 6 shows the Research Paper row for that semester. Default No.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('semester_masters', 'research_paper')) {
            Schema::table('semester_masters', function (Blueprint $table) {
                $table->boolean('research_paper')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('semester_masters', 'research_paper')) {
            Schema::table('semester_masters', function (Blueprint $table) {
                $table->dropColumn('research_paper');
            });
        }
    }
};
