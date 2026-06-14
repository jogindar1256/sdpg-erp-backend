<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('form_no')->unique();
            $table->date('applied_date');
            $table->jsonb('subjects_appearing');   // [{subject_id, subject_code, type: regular|back}]
            $table->decimal('fee_paid', 8, 2)->default(0);
            $table->string('payment_ref')->nullable();
            $table->enum('status', ['submitted', 'approved', 'rejected', 'cancelled'])->default('submitted');
            $table->string('admit_card_path')->nullable();
            $table->boolean('admit_card_generated')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['examination_id', 'student_id'], 'unique_exam_app');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_applications');
    }
};
