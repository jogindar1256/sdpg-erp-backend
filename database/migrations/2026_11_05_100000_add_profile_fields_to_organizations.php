<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Institute Profile page (college/organization/profile) fields that had no
 * column: established year, NAAC grade, principal's mobile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            if (!Schema::hasColumn('organizations', 'established_year')) $t->string('established_year', 4)->nullable()->after('university_code');
            if (!Schema::hasColumn('organizations', 'naac_grade')) $t->string('naac_grade', 10)->nullable()->after('established_year');
            if (!Schema::hasColumn('organizations', 'principal_mobile')) $t->string('principal_mobile', 20)->nullable()->after('principal_name');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->dropColumn(['established_year', 'naac_grade', 'principal_mobile']);
        });
    }
};
