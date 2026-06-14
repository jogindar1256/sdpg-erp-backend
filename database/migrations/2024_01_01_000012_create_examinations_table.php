<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->string('academic_year', 10);
            $table->integer('semester_no');
            $table->string('exam_name');
            $table->enum('exam_type', ['semester', 'annual', 'back_paper', 'supplementary']);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('form_start_date')->nullable();
            $table->date('form_end_date')->nullable();
            $table->date('late_form_date')->nullable();
            $table->decimal('exam_fee', 8, 2)->default(0);
            $table->decimal('late_fee', 8, 2)->default(0);
            $table->enum('status', ['upcoming', 'ongoing', 'completed', 'cancelled'])->default('upcoming');
            $table->timestamps();

            $table->index(['organization_id', 'academic_year', 'semester_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examinations');
    }
};
