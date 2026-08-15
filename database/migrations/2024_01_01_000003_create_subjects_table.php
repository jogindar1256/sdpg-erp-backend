<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->integer('semester_no');               // 1 to 8
            $table->enum('type', ['compulsory', 'optional', 'elective', 'practical', 'project']);
            // Was a Postgres-enforced enum(['regular','back_paper']) — widened
            // to varchar since the Subject Master UI also sends 'self_finance'.
            $table->string('paper_type', 20)->default('regular');
            $table->integer('max_marks')->default(100);
            $table->integer('min_marks')->default(36);    // passing marks
            $table->integer('internal_marks')->default(0);
            $table->integer('credits')->default(4);
            $table->boolean('has_practical')->default(false);
            $table->decimal('practical_fee', 10, 2)->nullable();
            // Subject Master.docx: "Additional Fee Applicable" + "If
            // Applicable Yes Then Fee Rs." — a second, separate fee concept.
            $table->boolean('additional_fee_applicable')->default(false);
            $table->decimal('additional_fee', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
