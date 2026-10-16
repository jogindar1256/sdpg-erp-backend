<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direct_registrations', function (Blueprint $table) {
            $table->string('spouse_name_english', 200)->nullable();
            $table->string('spouse_name_hindi', 200)->nullable();
        });

        DB::statement('ALTER TABLE counselling_reports DROP CONSTRAINT IF EXISTS counselling_reports_admission_category_check');
        DB::statement("ALTER TABLE counselling_reports ADD CONSTRAINT counselling_reports_admission_category_check CHECK (admission_category IN ('General','OBC','SC','ST','EWS','PH/PwD'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE counselling_reports DROP CONSTRAINT IF EXISTS counselling_reports_admission_category_check');
        DB::statement("ALTER TABLE counselling_reports ADD CONSTRAINT counselling_reports_admission_category_check CHECK (admission_category IN ('General','OBC','SC','ST','EWS','PH/PwD'))");

        Schema::table('direct_registrations', function (Blueprint $table) {
            $table->dropColumn(['spouse_name_english', 'spouse_name_hindi']);
        });
    }
};
