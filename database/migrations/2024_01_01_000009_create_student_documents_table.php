<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('student_applications')->nullOnDelete();

            $table->string('document_type');   // aadhar, photo, signature, marksheet_10, marksheet_12, tc, migration, caste_certificate
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_size')->nullable();
            $table->string('mime_type')->nullable();

            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_documents');
    }
};
