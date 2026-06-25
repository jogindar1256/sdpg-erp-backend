<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_applications', function (Blueprint $table) {
            // Part data stored as JSONB. Column names match what updatePart() writes:
            //   part_1 = Personal Details
            //   part_2 = Address & Contact
            //   part_3 = Educational Details
            //   part_4 = TC & Migration
            //   part_5 = Bank Details
            //   part_6 = Subject Selection
            //   part_7 = Upload Documents
            //   part_8 = Shapath Patr / Declaration
            $table->jsonb('part_1')->nullable()->after('form_progress');
            $table->jsonb('part_2')->nullable()->after('part_1');
            $table->jsonb('part_3')->nullable()->after('part_2');
            $table->jsonb('part_4')->nullable()->after('part_3');
            $table->jsonb('part_5')->nullable()->after('part_4');
            $table->jsonb('part_6')->nullable()->after('part_5');
            $table->jsonb('part_7')->nullable()->after('part_6');
            $table->jsonb('part_8')->nullable()->after('part_7');
        });
    }

    public function down(): void
    {
        Schema::table('student_applications', function (Blueprint $table) {
            $table->dropColumn(['part_1','part_2','part_3','part_4','part_5','part_6','part_7','part_8']);
        });
    }
};
