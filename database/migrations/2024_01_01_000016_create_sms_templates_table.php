<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');                      // Admission Confirm, Fee Receipt, etc.
            $table->string('event_trigger');             // admission_approved, fee_paid, exam_form_submitted
            $table->text('template');                    // "Dear {student_name}, your admission..."
            $table->string('dlt_template_id')->nullable(); // TRAI DLT registered ID
            $table->string('sender_id', 10)->nullable(); // 6-char Sender ID
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('sms_templates')->nullOnDelete();
            $table->string('mobile', 15);
            $table->text('message');
            $table->string('event_trigger')->nullable();
            $table->enum('status', ['sent', 'failed', 'pending'])->default('pending');
            $table->string('provider_message_id')->nullable();
            $table->text('provider_response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('sms_templates');
    }
};
