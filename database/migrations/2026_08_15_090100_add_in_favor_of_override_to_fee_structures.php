<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Fee Structure Management redesign's sidebar carries a single "Favor
 * In" selector applied to the whole filing (e.g. "this batch of amounts is
 * the College-favor fee structure for B.A. I / 2026-27"), separate from
 * fee_heads.in_favor_of (that column is the fee head's own default, edited
 * only in Fee Head Master). This nullable override lets one filing pick a
 * different favor-of than the fee head's default without mutating the
 * shared master data. feeStructureIndex falls back to the fee head's value
 * via COALESCE when a row hasn't set an override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_structures', function (Blueprint $table) {
            $table->string('in_favor_of', 20)->nullable()->after('ddu_affiliated');
        });
    }

    public function down(): void
    {
        Schema::table('fee_structures', function (Blueprint $table) {
            $table->dropColumn('in_favor_of');
        });
    }
};
