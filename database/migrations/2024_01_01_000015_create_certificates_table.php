<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->enum('certificate_type', ['tc', 'migration', 'character', 'bonafide', 'degree', 'provisional', 'marksheet']);
            $table->string('certificate_no')->unique();
            $table->date('issue_date');
            $table->jsonb('certificate_data')->nullable();   // dynamic fields per type
            $table->string('pdf_path')->nullable();
            $table->boolean('is_issued')->default(false);
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->boolean('is_cancelled')->default(false);
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'certificate_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
