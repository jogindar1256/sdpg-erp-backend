<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {

        // student_subject_groups
        Schema::create('student_subject_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained('admissions')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->string('session_year');
            $table->string('semester_no');
            $table->integer('group_no')->default(1);
            $table->timestamps();
            $table->unique(['admission_id', 'subject_id', 'session_year', 'semester_no']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
