<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('direct_registrations', function (Blueprint $t) {
            if (!Schema::hasColumn('direct_registrations', 'eligibility_marks_type')) {
                // 'Mark' (percentage) or 'CGPA' — what the applicant entered for the eligibility course.
                $t->string('eligibility_marks_type', 5)->nullable()->after('eligibility_roll_no');
            }
            if (!Schema::hasColumn('direct_registrations', 'eligibility_marks')) {
                $t->decimal('eligibility_marks', 5, 2)->nullable()->after('eligibility_marks_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('direct_registrations', function (Blueprint $t) {
            foreach (['eligibility_marks', 'eligibility_marks_type'] as $c) {
                if (Schema::hasColumn('direct_registrations', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
