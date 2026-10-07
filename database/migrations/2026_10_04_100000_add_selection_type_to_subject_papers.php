<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * subject_papers.selection_type — Compulsory / Optional / Teaching Subject.
 * Only meaningful for PG and B.Ed courses ("Teaching Subject" is B.Ed only);
 * NULL for every other course and for papers saved before this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subject_papers') && !Schema::hasColumn('subject_papers', 'selection_type')) {
            Schema::table('subject_papers', function (Blueprint $t) {
                $t->string('selection_type', 30)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subject_papers') && Schema::hasColumn('subject_papers', 'selection_type')) {
            Schema::table('subject_papers', function (Blueprint $t) {
                $t->dropColumn('selection_type');
            });
        }
    }
};
