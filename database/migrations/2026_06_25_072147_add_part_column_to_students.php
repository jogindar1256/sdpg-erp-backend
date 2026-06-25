<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_applications', function (Blueprint $table) {
            // Each part of the multi-step form is stored as a JSONB column.
            // updatePart() writes to part_<partname>, show() reads them back.
            $table->jsonb('part_personal')->nullable()->after('form_progress');
            $table->jsonb('part_education')->nullable()->after('part_personal');
            $table->jsonb('part_tc')->nullable()->after('part_education');
            $table->jsonb('part_migration')->nullable()->after('part_tc');
            $table->jsonb('part_bank')->nullable()->after('part_migration');
            $table->jsonb('part_subjects')->nullable()->after('part_bank');
            $table->jsonb('part_documents')->nullable()->after('part_subjects');
            $table->jsonb('part_declaration')->nullable()->after('part_documents');
        });
    }

    public function down(): void
    {
        Schema::table('student_applications', function (Blueprint $table) {
            $table->dropColumn([
                'part_personal', 'part_education', 'part_tc', 'part_migration',
                'part_bank', 'part_subjects', 'part_documents', 'part_declaration',
            ]);
        });
    }
};
