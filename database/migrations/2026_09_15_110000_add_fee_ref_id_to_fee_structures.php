<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_structures', function (Blueprint $table) {
            // Real, DB-backed fee reference code — replaces the client-only
            // computed "Fee Ref." string the Fee Structure UI used to show
            $table->string('fee_ref_id', 40)->nullable()->after('id');
            $table->unique('fee_ref_id');
        });
    }

    public function down(): void
    {
        Schema::table('fee_structures', function (Blueprint $table) {
            $table->dropUnique(['fee_ref_id']);
            $table->dropColumn('fee_ref_id');
        });
    }
};
