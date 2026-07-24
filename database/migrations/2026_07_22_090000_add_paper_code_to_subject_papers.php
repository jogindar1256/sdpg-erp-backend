<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Subject Paper Master screen mockup shows a "Paper Code" column
 * (Paper Code | Paper Name | Action) that has no backing column on
 * subject_papers — only paper_name existed. Adding it so the real screen
 * can actually save what it displays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subject_papers', function (Blueprint $t) {
            if (!Schema::hasColumn('subject_papers', 'paper_code')) {
                $t->string('paper_code', 50)->nullable()->after('subject_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subject_papers', function (Blueprint $t) {
            if (Schema::hasColumn('subject_papers', 'paper_code')) {
                $t->dropColumn('paper_code');
            }
        });
    }
};
