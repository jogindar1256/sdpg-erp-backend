<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_heads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');                        // Tuition Fee, Library Fee, Exam Fee
            $table->string('code', 30)->unique();
            $table->enum('category', ['tuition', 'exam', 'library', 'hostel', 'transport', 'miscellaneous']);
            $table->boolean('is_refundable')->default(false);
            $table->boolean('is_mandatory')->default(true);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_heads');
    }
};
