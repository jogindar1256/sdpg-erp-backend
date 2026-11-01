<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fee rule 10 — a fee re-calculated after payment (something in the
 * application changed):
 *  - lower than what was paid: the difference is owed back to the student,
 *    kept in return_payments and settled from Financial Activity → Return
 *    to Student;
 *  - higher: the student is told (email, SMS once a DLT id is set on the
 *    template) to pay the difference — the fee_due template below.
 */
return new class extends Migration {
    private const FEE_DUE = 'Your revised fee for Application No {#var#} is Rs {#var#} more than the fee paid. Please pay the balance at the college office. Swami Devanand Post Graduate College';

    public function up(): void
    {
        if (!Schema::hasTable('return_payments')) {
            Schema::create('return_payments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('organization_id')->nullable();
                $t->unsignedBigInteger('fee_receipt_id')->index();      // the receipt the fee was paid on
                $t->unsignedBigInteger('admission_id')->nullable()->index();
                $t->unsignedBigInteger('student_application_id')->nullable()->index();
                $t->unsignedBigInteger('student_id')->nullable()->index();
                $t->string('academic_year', 12)->nullable();
                $t->unsignedSmallInteger('semester_no')->nullable();
                $t->decimal('amount', 10, 2);                            // to return
                $t->decimal('paid_amount', 10, 2);                       // paid (net of earlier returns) when raised
                $t->decimal('revised_amount', 10, 2);                    // the re-calculated fee
                $t->jsonb('revised_breakdown')->nullable();
                $t->text('reason')->nullable();
                $t->string('status', 20)->default('pending')->index();   // pending | returned | cancelled
                $t->string('mode', 30)->nullable();                      // cash | neft | upi | cheque
                $t->string('ref_no', 100)->nullable();
                $t->date('returned_on')->nullable();
                $t->unsignedBigInteger('returned_by')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        if (Schema::hasTable('sms_templates')) {
            foreach (DB::table('organizations')->pluck('id') as $orgId) {
                $exists = DB::table('sms_templates')->where('organization_id', $orgId)->where('event_trigger', 'fee_due')->exists();
                if ($exists) continue;
                DB::table('sms_templates')->insert([
                    'organization_id' => $orgId,
                    'event_trigger' => 'fee_due',
                    'name' => 'Fee Balance Due',
                    'template' => self::FEE_DUE,
                    'dlt_template_id' => null, // email-only until registered on DLT
                    'sender_id' => DB::table('sms_templates')->where('organization_id', $orgId)->whereNotNull('sender_id')->value('sender_id'),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('return_payments');
        if (Schema::hasTable('sms_templates')) {
            DB::table('sms_templates')->where('event_trigger', 'fee_due')->delete();
        }
    }
};
