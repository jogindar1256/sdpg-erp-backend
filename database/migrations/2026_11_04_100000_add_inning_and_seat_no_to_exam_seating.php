<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Seating plans are made per exam date + inning; seats are numbered per room. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_seating', function (Blueprint $t) {
            if (!Schema::hasColumn('exam_seating', 'inning_id')) {
                $t->unsignedBigInteger('inning_id')->nullable();
            }
            if (!Schema::hasColumn('exam_seating', 'seat_no')) {
                $t->unsignedInteger('seat_no')->nullable();
            }
            $t->index(['session_year', 'exam_date', 'inning_id'], 'exam_seating_plan_idx');
        });
    }

    public function down(): void
    {
        Schema::table('exam_seating', function (Blueprint $t) {
            $t->dropIndex('exam_seating_plan_idx');
            $t->dropColumn(['inning_id', 'seat_no']);
        });
    }
};
