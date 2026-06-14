<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();

            $table->enum('amendment_type', [
                'admission_cancel',
                'subject_change',
                'data_modify',
                'tc_migration_update',
                'mobile_update',
                'paper_update',
                'fee_reset',
                'restriction',
                'block_unblock',
                'document_download',
            ]);

            $table->string('reference_no')->unique();
            $table->jsonb('old_data')->nullable();    // snapshot of data before change
            $table->jsonb('new_data')->nullable();    // new values
            $table->text('reason');

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_remarks')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'amendment_type', 'status']);
            $table->index(['student_id', 'amendment_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amendments');
    }
};
