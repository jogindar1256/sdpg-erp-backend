<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fee Receipt Generate → Generate ID checks the fee paid against the Fee
 * Structure before the Final Fee Receipt is prepared:
 *  - paid more: the excess is kept in return_payments and settled from
 *    Financial Activity → Return to Student;
 *  - paid less: the amount still due is kept on
 *    student_applications.fee_due_amount (student portal shows "Fee
 *    Pending"), and the student pays it online as a 'fee_balance' receipt.
 */
return new class extends Migration {
    private const CHECK = 'fee_receipts_receipt_type_check';

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
                $t->decimal('paid_amount', 10, 2);                       // paid when raised
                $t->decimal('revised_amount', 10, 2);                    // the fee per Fee Structure
                $t->jsonb('revised_breakdown')->nullable();
                $t->text('reason')->nullable();
                $t->string('status', 20)->default('pending')->index();   // pending | returned
                $t->string('mode', 30)->nullable();                      // cash | neft | upi | cheque
                $t->string('ref_no', 100)->nullable();
                $t->date('returned_on')->nullable();
                $t->unsignedBigInteger('returned_by')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasColumn('student_applications', 'fee_due_amount')) {
            Schema::table('student_applications', fn(Blueprint $t) => $t->decimal('fee_due_amount', 10, 2)->nullable());
        }

        // receipt_type was created as an enum (a CHECK constraint on Postgres):
        // allow 'fee_balance', keeping whatever values it already allows.
        $values = $this->allowedReceiptTypes();
        if ($values !== null && !in_array('fee_balance', $values, true)) {
            $this->setReceiptTypes([...$values, 'fee_balance']);
        }
    }

    public function down(): void
    {
        $values = $this->allowedReceiptTypes();
        if ($values !== null && !DB::table('fee_receipts')->where('receipt_type', 'fee_balance')->exists()) {
            $this->setReceiptTypes(array_values(array_diff($values, ['fee_balance'])));
        }
        if (Schema::hasColumn('student_applications', 'fee_due_amount')) {
            Schema::table('student_applications', fn(Blueprint $t) => $t->dropColumn('fee_due_amount'));
        }
        Schema::dropIfExists('return_payments');
    }

    /** The values the receipt_type CHECK allows, or null when there is no such constraint. */
    private function allowedReceiptTypes(): ?array
    {
        $def = DB::table('pg_constraint')
            ->where('conname', self::CHECK)
            ->whereRaw("conrelid = 'fee_receipts'::regclass")
            ->selectRaw('pg_get_constraintdef(oid) AS def')
            ->value('def');
        if (!$def) {
            return null;
        }
        preg_match_all("/'([^']+)'/", $def, $m);
        return array_values(array_unique($m[1]));
    }

    private function setReceiptTypes(array $values): void
    {
        $list = implode(', ', array_map(fn($v) => DB::getPdo()->quote($v), $values));
        DB::statement('ALTER TABLE fee_receipts DROP CONSTRAINT IF EXISTS ' . self::CHECK);
        DB::statement('ALTER TABLE fee_receipts ADD CONSTRAINT ' . self::CHECK . " CHECK (receipt_type::text IN ({$list}))");
    }
};
