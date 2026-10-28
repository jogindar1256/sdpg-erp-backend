<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('direct_registrations', 'caste_cert_district')) {
            Schema::table('direct_registrations', function (Blueprint $t) {
                $t->string('caste_cert_district', 100)->nullable()->after('caste_cert_state');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('direct_registrations', 'caste_cert_district')) {
            Schema::table('direct_registrations', function (Blueprint $t) {
                $t->dropColumn('caste_cert_district');
            });
        }
    }
};
