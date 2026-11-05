<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * university_college_code — the code the affiliating university assigns to
 *   the college (centre code on Student IDs, exam centre / P1 / P9 forms).
 * tagline — free line printed under the college name on receipts
 *   (e.g. a grant-in-aid note). Empty = nothing printed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            if (!Schema::hasColumn('organizations', 'university_college_code')) $t->string('university_college_code', 20)->nullable()->after('university_code');
            if (!Schema::hasColumn('organizations', 'tagline')) $t->string('tagline', 255)->nullable()->after('naac_grade');
        });

        // Keep today's behaviour for the existing install: it has always used centre code 145.
        DB::table('organizations')->whereNull('university_college_code')->update(['university_college_code' => '145']);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->dropColumn(['university_college_code', 'tagline']);
        });
    }
};
