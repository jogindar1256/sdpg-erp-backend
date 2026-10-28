<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Registration asks Marital Status before Spouse's Name. Values mirror the
// application Part 1 select: unmarried | married | widowed. Spouse names are
// only kept when the status is "married".
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('direct_registrations') && !Schema::hasColumn('direct_registrations', 'marital_status')) {
            Schema::table('direct_registrations', function (Blueprint $table) {
                $table->string('marital_status', 20)->nullable()->after('mother_name_hindi');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('direct_registrations', 'marital_status')) {
            Schema::table('direct_registrations', function (Blueprint $table) {
                $table->dropColumn('marital_status');
            });
        }
    }
};
