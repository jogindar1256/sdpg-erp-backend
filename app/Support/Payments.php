<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The one place that writes the payments table.
 *
 *  openOrder()       — an online order was created at Razorpay (status created)
 *  orderFor()        — the created/paid row an order id belongs to, for ONE payable
 *  markOnlinePaid()  — signature verified → paid, with its receipt
 *  markFailed()      — checkout failed / abandoned
 *  recordOffline()   — counter money: cash, DD, cheque, NEFT/UTR, voucher
 *  cancelForReceipt()— receipt rejected / cancelled → payment cancelled
 *
 * Phase 3a made the old payment columns on direct_registrations /
 * student_applications / admissions a read-only projection of this table.
 * Phase 4 finished the job: every controller now reads payments directly
 * (via the helpers below — applicationFeePaid(), registrationFeePaid(),
 * etc.) or through a join, those legacy columns are dropped, and the
 * sync*() methods that used to maintain the projection are gone.
 */
class Payments
{
    public const PURPOSES = ['registration', 'admission', 'semester_upgrade', 'back_paper', 'misc'];
    public const MODES = ['online', 'cash', 'dd', 'cheque', 'neft', 'voucher'];

    /** purpose of an application's fee */
    public static function purposeOf(object $sa): string
    {
        return match ($sa->application_type) {
            'back_paper' => 'back_paper',
            'semester_upgrade' => 'semester_upgrade',
            default => 'admission',
        };
    }

    /** Common link columns for an application. */
    public static function forApplication(object $sa): array
    {
        return [
            'organization_id' => $sa->organization_id,
            'user_id' => $sa->user_id ?? null,
            'purpose' => self::purposeOf($sa),
            'student_application_id' => $sa->id,
            'direct_registration_id' => $sa->direct_registration_id ?? null,
            'admission_id' => DB::table('admissions')->where('application_id', $sa->id)->orderByDesc('id')->value('id'),
            'student_id' => $sa->student_id ?? null,
            'academic_year' => $sa->academic_year,
            'semester_no' => $sa->semester_no,
        ];
    }

    /** Common link columns for a registration. */
    public static function forRegistration(object $reg): array
    {
        return [
            'organization_id' => $reg->organization_id ?? null,
            'user_id' => $reg->user_id ?? null,
            'purpose' => 'registration',
            'direct_registration_id' => $reg->id,
            'academic_year' => $reg->session_year ?? null,
            'semester_no' => 1,
        ];
    }

    public static function openOrder(array $link, float $amount, string $orderId): int
    {
        $id = (int) DB::table('payments')->insertGetId($link + [
            'amount' => round($amount, 2),
            'mode' => 'online',
            'status' => 'created',
            'gateway_order_id' => $orderId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    /**
     * The payment an order id belongs to, only if it was created for this
     * payable ($column = student_application_id | direct_registration_id).
     * $legacyOrderId: orders made before payments existed (still on the old
     * column) are adopted into a row so in-flight checkouts keep working.
     */
    public static function orderFor(string $orderId, string $column, int $id, ?string $legacyOrderId = null, array $link = [], float $amount = 0): ?object
    {
        $row = DB::table('payments')->where('gateway_order_id', $orderId)->first();
        if ($row) {
            return (int) $row->{$column} === $id ? $row : null;
        }
        if ($legacyOrderId && hash_equals($legacyOrderId, $orderId) && $link) {
            $pid = self::openOrder($link, $amount, $orderId);
            return DB::table('payments')->where('id', $pid)->first();
        }
        return null;
    }

    public static function markOnlinePaid(int $paymentId, string $gatewayPaymentId, ?string $signature, float $amount, ?int $receiptId, ?string $receiptNo, array $extra = []): void
    {
        DB::table('payments')->where('id', $paymentId)->update($extra + [
            'status' => 'paid',
            'gateway_payment_id' => $gatewayPaymentId,
            'gateway_signature' => $signature,
            'amount' => round($amount, 2),
            'fee_receipt_id' => $receiptId,
            'receipt_no' => $receiptNo,
            'paid_at' => now(),
            'updated_at' => now(),
        ]);
        if ($receiptId) {
            DB::table('fee_receipts')->where('id', $receiptId)->update(['payment_id' => $paymentId]);
        }
    }

    public static function markFailed(string $orderId): void
    {
        DB::table('payments')->where('gateway_order_id', $orderId)->where('status', 'created')
            ->update(['status' => 'failed', 'failed_at' => now(), 'updated_at' => now()]);
    }

    /** Fail every still-open order of a payable (the student dismissed checkout). */
    public static function failOpenOrders(string $column, int $id): void
    {
        DB::table('payments')->where($column, $id)->where('status', 'created')
            ->update(['status' => 'failed', 'failed_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Counter / bank money. $offline: mode, instrument_no, instrument_date,
     * bank_name, bank_branch, bank_ref_no, remarks.
     */
    public static function recordOffline(array $link, float $amount, array $offline, ?int $receiptId, ?string $receiptNo, ?int $userId, ?string $paidAt = null): int
    {
        $mode = in_array($offline['mode'] ?? null, self::MODES, true) ? $offline['mode'] : 'cash';
        $id = (int) DB::table('payments')->insertGetId($link + [
            'amount' => round($amount, 2),
            'mode' => $mode,
            'status' => 'paid',
            'instrument_no' => $offline['instrument_no'] ?? null,
            'instrument_date' => $offline['instrument_date'] ?? null,
            'bank_name' => $offline['bank_name'] ?? null,
            'bank_branch' => $offline['bank_branch'] ?? null,
            'bank_ref_no' => $offline['bank_ref_no'] ?? null,
            'remarks' => $offline['remarks'] ?? null,
            'collected_by' => $userId,
            'fee_receipt_id' => $receiptId,
            'receipt_no' => $receiptNo,
            'paid_at' => $paidAt ?? now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($receiptId) {
            DB::table('fee_receipts')->where('id', $receiptId)->update(['payment_id' => $id]);
        }
        return $id;
    }

    /** Receipt rejected / cancelled → its payment is cancelled. Returns the payment id. */
    public static function cancelForReceipt(int $receiptId, ?string $reason): ?int
    {
        $pid = DB::table('payments')->where('fee_receipt_id', $receiptId)->value('id')
            ?? DB::table('fee_receipts')->where('id', $receiptId)->value('payment_id');
        if (!$pid) {
            return null;
        }
        DB::table('payments')->where('id', $pid)->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
            'updated_at' => now(),
        ]);
        return (int) $pid;
    }

    public static function markVerified(int $receiptId, ?int $userId): void
    {
        DB::table('payments')->where('fee_receipt_id', $receiptId)
            ->update(['verified_by' => $userId, 'verified_at' => now(), 'updated_at' => now()]);
    }

    /** The paid payment behind a payable, latest first. */
    public static function paidFor(string $column, int $id): ?object
    {
        return DB::table('payments')->where($column, $id)->where('status', 'paid')->orderByDesc('id')->first();
    }

    // ── Read helpers — phase 4: callers read these instead of the old
    // columns directly, since the columns themselves are gone. Every one
    // mirrors the exact logic its sync*() counterpart above used to write.

    /** The paid admission/semester-upgrade/back-paper payment for an application, if any. */
    public static function paidApplicationPayment(int $appId): ?object
    {
        return DB::table('payments')->where('student_application_id', $appId)
            ->whereIn('purpose', self::APP_PURPOSES)->where('status', 'paid')->orderByDesc('id')->first();
    }

    public static function applicationFeePaid(int $appId): bool
    {
        return (bool) self::paidApplicationPayment($appId);
    }

    public static function applicationFeeReceiptId(int $appId): ?int
    {
        return self::paidApplicationPayment($appId)->fee_receipt_id ?? null;
    }

    public static function applicationPaidAt(int $appId): ?string
    {
        return self::paidApplicationPayment($appId)->paid_at ?? null;
    }

    /** The paid registration payment, if any. */
    public static function paidRegistrationPayment(int $regId): ?object
    {
        return DB::table('payments')->where('direct_registration_id', $regId)
            ->where('purpose', 'registration')->where('status', 'paid')->orderByDesc('id')->first();
    }

    /** admissions.payment_status equivalent: 'paid' only via the application's own fee. */
    public static function admissionPaymentStatus(int $admId): string
    {
        $applicationId = DB::table('admissions')->where('id', $admId)->value('application_id');
        $paid = $applicationId ? self::paidApplicationPayment((int) $applicationId) : null;
        return $paid ? 'paid' : 'pending';
    }

    /** admissions.fee_status equivalent: 'Paid' via the application fee OR any paid money on the admission (e.g. misc). */
    public static function admissionFeeStatus(int $admId): string
    {
        $applicationId = DB::table('admissions')->where('id', $admId)->value('application_id');
        $paid = $applicationId ? self::paidApplicationPayment((int) $applicationId) : null;
        $anyPaid = $paid ?: DB::table('payments')->where('admission_id', $admId)->where('status', 'paid')->orderByDesc('id')->first();
        return $anyPaid ? 'Paid' : 'Pending';
    }

    public static function registrationFeePaid(int $regId): bool
    {
        return (bool) self::paidRegistrationPayment($regId);
    }

    public static function registrationPaymentStatus(int $regId): string
    {
        $rows = DB::table('payments')->where('direct_registration_id', $regId)->where('purpose', 'registration')
            ->orderByDesc('id')->get();
        if ($rows->firstWhere('status', 'paid')) return 'paid';
        $latest = $rows->first();
        return ($latest && $latest->status === 'failed') ? 'failed' : 'pending';
    }

    /** Fee purposes that settle an application (misc never does). */
    private const APP_PURPOSES = ['admission', 'semester_upgrade', 'back_paper'];
}
