<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Fee If 01 / 02 / 03 Practical Sub." amounts on the Fee Structure
 * page. Until now they lived only in the browser and were lost on refresh.
 *
 * They belong to the fee structure as a whole — one course + session +
 * semester + exam mode (+ the PG/B.Ed. pass-out flags) — not to a gender or
 * a category. fee_structures has one row PER CATEGORY, so storing them there
 * would mean repeating the same three numbers on every category row. Hence
 * a table of their own: one row per configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fee_structure_practical_fees')) {
            return;
        }

        Schema::create('fee_structure_practical_fees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('program_id')->constrained()->cascadeOnDelete();
            $t->string('academic_year', 10);
            $t->integer('semester_no');
            $t->string('admission_type', 20);
            $t->boolean('sdpgc_student')->default(false);
            $t->boolean('ddu_affiliated')->default(false);
            $t->decimal('fee_1', 10, 2)->default(0); // fee if 1 practical subject
            $t->decimal('fee_2', 10, 2)->default(0); // fee if 2 practical subjects
            $t->decimal('fee_3', 10, 2)->default(0); // fee if 3 practical subjects
            $t->timestamps();

            $t->unique(
                ['program_id', 'academic_year', 'semester_no', 'admission_type', 'sdpgc_student', 'ddu_affiliated'],
                'fee_structure_practical_fees_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_structure_practical_fees');
    }
};
