<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payments — the one record of money received (or attempted), online and
 * at the counter. One row per attempt: an online order starts 'created' and
 * becomes 'paid' / 'failed'; a cancelled / rejected receipt marks it
 * 'cancelled' (any refund owed is in fee_refunds.payment_id).
 *
 * Linked to what was paid for — the registration (direct_registration_id)
 * or the application (student_application_id) — plus admission_id /
 * student_id when they already exist at payment time.
 *
 * Phase 1 of the payment simplification: the old payment columns on
 * direct_registrations / student_applications / admissions stay for now and
 * are backfilled from here; they are removed once every read uses payments.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('purpose', 30); // registration | admission | semester_upgrade | back_paper | misc
            $t->unsignedBigInteger('direct_registration_id')->nullable()->index();
            $t->unsignedBigInteger('student_application_id')->nullable()->index();
            $t->unsignedBigInteger('admission_id')->nullable()->index();
            $t->unsignedBigInteger('student_id')->nullable()->index();
            $t->string('academic_year', 12)->nullable();
            $t->unsignedSmallInteger('semester_no')->nullable();

            $t->decimal('amount', 10, 2)->default(0);
            $t->string('mode', 20)->default('online'); // online | cash | dd | cheque | neft | voucher
            $t->string('status', 20)->default('created')->index(); // created | paid | failed | cancelled

            // Online (Razorpay)
            $t->string('gateway_order_id', 100)->nullable()->unique();
            $t->string('gateway_payment_id', 100)->nullable()->index();
            $t->string('gateway_signature', 255)->nullable();

            // Counter / bank (cash, DD, cheque, NEFT, voucher)
            $t->string('instrument_no', 100)->nullable();   // DD / cheque / UTR / voucher no.
            $t->date('instrument_date')->nullable();
            $t->string('bank_name', 150)->nullable();
            $t->string('bank_branch', 150)->nullable();
            $t->string('bank_ref_no', 100)->nullable();
            $t->unsignedBigInteger('collected_by')->nullable();

            // Receipt issued for it
            $t->unsignedBigInteger('fee_receipt_id')->nullable()->index();
            $t->string('receipt_no', 50)->nullable();

            $t->timestamp('paid_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->unsignedBigInteger('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->text('remarks')->nullable();
            $t->timestamps();
        });

        Schema::table('fee_receipts', function (Blueprint $t) {
            if (!Schema::hasColumn('fee_receipts', 'payment_id')) {
                $t->unsignedBigInteger('payment_id')->nullable()->index();
            }
        });
        if (Schema::hasTable('fee_refunds') && !Schema::hasColumn('fee_refunds', 'payment_id')) {
            Schema::table('fee_refunds', function (Blueprint $t) {
                $t->unsignedBigInteger('payment_id')->nullable()->index();
            });
        }

        $this->backfill();
    }

    private function backfill(): void
    {
        $now = now();
        $purposeOfType = ['regular' => 'admission', 'semester_upgrade' => 'semester_upgrade', 'back_paper' => 'back_paper'];
        $purposeOfReceipt = [
            'regular_admission' => 'admission', 'semester_upgrade' => 'semester_upgrade',
            'back_paper' => 'back_paper',
        ];

        // 1. Registration fees (no fee_receipts row — receipt_no lives on the registration).
        DB::table('direct_registrations')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('payment_status', 'paid')->orWhereNotNull('razorpay_order_id');
            })
            ->orderBy('id')
            ->each(function ($r) use ($now) {
                $paid = $r->payment_status === 'paid';
                DB::table('payments')->insert([
                    'organization_id' => $r->organization_id,
                    'user_id' => $r->user_id,
                    'purpose' => 'registration',
                    'direct_registration_id' => $r->id,
                    'academic_year' => $r->session_year,
                    'semester_no' => 1,
                    'amount' => (float) ($r->fee_amount ?? 0),
                    'mode' => 'online',
                    'status' => $paid ? 'paid' : ($r->payment_status === 'failed' ? 'failed' : 'created'),
                    'gateway_order_id' => $r->razorpay_order_id,
                    'gateway_payment_id' => $r->razorpay_payment_id,
                    'receipt_no' => $paid ? $r->receipt_no : null,
                    'paid_at' => $paid ? ($r->paid_at ?? $r->updated_at) : null,
                    'created_at' => $r->created_at ?? $now,
                    'updated_at' => $now,
                ]);
            });

        // 2. Every fee receipt is a payment (active → paid, cancelled → cancelled).
        DB::table('fee_receipts')->orderBy('id')->each(function ($fr) use ($now, $purposeOfReceipt) {
            $adm = $fr->admission_id ? DB::table('admissions')->where('id', $fr->admission_id)->first() : null;
            $sa = DB::table('student_applications')->where('fee_receipt_id', $fr->id)->first();
            $appId = $sa->id
                ?? (Schema::hasTable('fee_refunds') ? DB::table('fee_refunds')->where('fee_receipt_id', $fr->id)->value('student_application_id') : null)
                ?? ($adm->application_id ?? null);
            if (!$sa && $appId) {
                $sa = DB::table('student_applications')->where('id', $appId)->first();
            }
            $cancelled = $fr->status === 'cancelled';
            $id = DB::table('payments')->insertGetId([
                'organization_id' => $fr->organization_id,
                'user_id' => $sa->user_id ?? ($adm->user_id ?? null),
                'purpose' => $purposeOfReceipt[$fr->receipt_type] ?? 'misc',
                'student_application_id' => $appId,
                'direct_registration_id' => $sa->direct_registration_id ?? ($adm->direct_registration_id ?? null),
                'admission_id' => $fr->admission_id,
                'student_id' => $fr->student_id,
                'academic_year' => $fr->academic_year,
                'semester_no' => $fr->semester_no,
                'amount' => (float) $fr->net_amount,
                'mode' => in_array($fr->payment_mode, ['online', 'cash', 'dd', 'cheque', 'neft', 'voucher'], true) ? $fr->payment_mode : 'online',
                'status' => $cancelled ? 'cancelled' : 'paid',
                // The order on the application belongs to this receipt only while it is the live one.
                'gateway_order_id' => ($sa && (int) $sa->fee_receipt_id === (int) $fr->id) ? $sa->razorpay_order_id : null,
                'gateway_payment_id' => $fr->payment_mode === 'online' ? $fr->transaction_id : null,
                'instrument_no' => $fr->payment_mode !== 'online' ? ($fr->dd_no ?? $fr->transaction_id) : null,
                'instrument_date' => $fr->dd_date ?? null,
                'bank_name' => $fr->bank_name ?? null,
                'bank_ref_no' => $fr->bank_ref_no ?? null,
                'fee_receipt_id' => $fr->id,
                'receipt_no' => $fr->receipt_no,
                'paid_at' => ($sa && $sa->paid_at && (int) $sa->fee_receipt_id === (int) $fr->id) ? $sa->paid_at : ($fr->created_at ?? $now),
                'cancelled_at' => $cancelled ? ($fr->updated_at ?? $now) : null,
                'cancel_reason' => $cancelled ? $fr->cancel_reason : null,
                'verified_by' => $fr->is_verified ? $fr->verified_by : null,
                'verified_at' => $fr->is_verified ? $fr->verified_at : null,
                'created_at' => $fr->created_at ?? $now,
                'updated_at' => $now,
            ]);
            DB::table('fee_receipts')->where('id', $fr->id)->update(['payment_id' => $id]);
            if (Schema::hasTable('fee_refunds')) {
                DB::table('fee_refunds')->where('fee_receipt_id', $fr->id)->update(['payment_id' => $id]);
            }
        });

        // 3. Open (unpaid) application orders still in flight.
        DB::table('student_applications')
            ->whereNull('deleted_at')
            ->where('fee_paid', false)
            ->whereNotNull('razorpay_order_id')
            ->orderBy('id')
            ->each(function ($sa) use ($now, $purposeOfType) {
                if (DB::table('payments')->where('gateway_order_id', $sa->razorpay_order_id)->exists()) {
                    return;
                }
                DB::table('payments')->insert([
                    'organization_id' => $sa->organization_id,
                    'user_id' => $sa->user_id,
                    'purpose' => $purposeOfType[$sa->application_type] ?? 'admission',
                    'student_application_id' => $sa->id,
                    'direct_registration_id' => $sa->direct_registration_id,
                    'admission_id' => DB::table('admissions')->where('application_id', $sa->id)->value('id'),
                    'student_id' => $sa->student_id,
                    'academic_year' => $sa->academic_year,
                    'semester_no' => $sa->semester_no,
                    'amount' => (float) ($sa->fee_amount ?? 0),
                    'mode' => 'online',
                    'status' => 'created',
                    'gateway_order_id' => $sa->razorpay_order_id,
                    'created_at' => $sa->updated_at ?? $now,
                    'updated_at' => $now,
                ]);
            });

        // Admission-cancel refunds: point at the admission's paid payment.
        if (Schema::hasTable('fee_refunds')) {
            DB::table('fee_refunds')->whereNull('payment_id')->whereNotNull('admission_id')->orderBy('id')->each(function ($rf) {
                $pid = DB::table('payments')->where('admission_id', $rf->admission_id)->where('status', 'paid')->orderByDesc('id')->value('id');
                if ($pid) {
                    DB::table('fee_refunds')->where('id', $rf->id)->update(['payment_id' => $pid]);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fee_receipts', 'payment_id')) {
            Schema::table('fee_receipts', fn(Blueprint $t) => $t->dropColumn('payment_id'));
        }
        if (Schema::hasTable('fee_refunds') && Schema::hasColumn('fee_refunds', 'payment_id')) {
            Schema::table('fee_refunds', fn(Blueprint $t) => $t->dropColumn('payment_id'));
        }
        Schema::dropIfExists('payments');
    }
};
