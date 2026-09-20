<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Approval never writes a row here — approved_by/approved_at stay on
     * student_applications only, per explicit instruction.
     */
    public function up(): void
    {
        Schema::create('rejected_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_application_id')->constrained('student_applications')->cascadeOnDelete();

            $table->string('ref_no')->unique();
            $table->enum('decision', ['hold', 'reject']);

            // Row lifecycle. A reject row stays 'active' forever (rejection
            // is permanent — no release path). A hold row starts 'hold' and
            // flips to 'released' once released; 'active' is only ever used
            // for reject rows.
            $table->enum('status', ['active', 'hold', 'released'])->default('active');

            // Only meaningful when decision = 'hold':
            // "Highly Respected Objection" / "General Instruction Objection".
            $table->string('hold_type')->nullable();

            $table->text('reason'); // min-20-words, enforced client-side
            $table->jsonb('objections')->nullable(); // up to 3 strings

            $table->string('submitted_by')->nullable(); // staff name/designation typed in the form
            $table->timestamp('submitted_at')->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            // Release (hold only)
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->string('release_due_to')->nullable();
            $table->text('release_remarks')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('student_application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rejected_applications');
    }
};
