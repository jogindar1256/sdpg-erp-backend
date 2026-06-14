<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->string('academic_year', 10);
            $table->integer('semester_no');
            $table->enum('receipt_type', ['regular_admission', 'back_paper', 'semester_upgrade', 'miscellaneous']);

            // Receipt number (auto-generated)
            $table->string('receipt_no')->unique();
            $table->date('receipt_date');

            // Payment details
            $table->decimal('total_amount', 10, 2);
            $table->decimal('late_fine', 8, 2)->default(0);
            $table->decimal('concession', 8, 2)->default(0);
            $table->decimal('net_amount', 10, 2);
            $table->enum('payment_mode', ['cash', 'dd', 'online', 'neft', 'upi', 'cheque']);
            $table->string('transaction_id')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('dd_no')->nullable();
            $table->date('dd_date')->nullable();

            // Fee breakdown (JSONB: [{fee_head_id, fee_head_name, amount}])
            $table->jsonb('fee_breakdown');

            // Authorization
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('generated_by')->constrained('users')->cascadeOnDelete();

            // PDF path after generation
            $table->string('pdf_path')->nullable();

            $table->enum('status', ['active', 'cancelled', 'refunded'])->default('active');
            $table->text('cancel_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'academic_year', 'receipt_type']);
            $table->index(['student_id', 'academic_year']);
            $table->index('receipt_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_receipts');
    }
};
