<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_head_id')->constrained()->cascadeOnDelete();
            $table->integer('semester_no');               // which semester this fee applies to (0 = all)
            $table->string('academic_year', 10);          // 2024-25
            $table->enum('admission_type', ['regular', 'back_paper', 'upgrade', 'lateral']);
            $table->decimal('amount', 10, 2);
            $table->decimal('late_fine_per_day', 8, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['program_id', 'fee_head_id', 'semester_no', 'academic_year', 'admission_type'], 'fee_structure_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_structures');
    }
};
