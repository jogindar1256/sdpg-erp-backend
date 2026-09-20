<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('student_applications', function (Blueprint $table) {
            if (Schema::hasColumn('student_applications', 'reviewed_by')) {
                $table->dropForeign(['reviewed_by']);
            }
        });

        Schema::table('student_applications', function (Blueprint $table) {
            foreach ([
                'rejection_reason', 'remarks', 'reviewed_by', 'reviewed_at',
                'selected_subjects', 'selected_optional_subjects',
            ] as $col) {
                if (Schema::hasColumn('student_applications', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_applications', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->jsonb('selected_subjects')->nullable();
            $table->jsonb('selected_optional_subjects')->nullable();
        });
    }
};
