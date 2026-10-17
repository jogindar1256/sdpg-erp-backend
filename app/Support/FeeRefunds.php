<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The refund register (fee_refunds). Every refund owed — a rejected /
 * cancelled receipt, or a cancelled admission whose fee was paid — gets one
 * row, 'pending' until the office records how it was refunded.
 */
class FeeRefunds
{
    /** Live (not cancelled) admission-fee receipts behind one admission. */
    public static function receiptsForAdmission(int $admissionId)
    {
        $adm = DB::table('admissions')->where('id', $admissionId)->first();
        if (!$adm) {
            return collect();
        }
        $appReceiptId = $adm->application_id
            ? \App\Support\Payments::applicationFeeReceiptId((int) $adm->application_id)
            : null;

        return DB::table('fee_receipts')
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($admissionId, $appReceiptId) {
                $q->where('admission_id', $admissionId);
                if ($appReceiptId) {
                    $q->orWhere('id', $appReceiptId);
                }
            })
            ->get();
    }

    public static function paidForAdmission(int $admissionId): float
    {
        return round((float) self::receiptsForAdmission($admissionId)->sum('net_amount'), 2);
    }

    /** Refund for a cancelled receipt (full amount). */
    public static function openForReceipt(object $receipt, ?string $reason, ?int $userId): ?int
    {
        $amount = round((float) $receipt->net_amount, 2);
        if ($amount <= 0) {
            return null;
        }
        if ($id = DB::table('fee_refunds')->where('fee_receipt_id', $receipt->id)->value('id')) {
            return (int) $id;
        }
        $appId = DB::table('payments')->where('fee_receipt_id', $receipt->id)->value('student_application_id')
            ?: ($receipt->admission_id ? DB::table('admissions')->where('id', $receipt->admission_id)->value('application_id') : null);

        return (int) DB::table('fee_refunds')->insertGetId([
            'organization_id' => $receipt->organization_id ?? null,
            'source' => 'receipt_cancel',
            'payment_id' => DB::table('payments')->where('fee_receipt_id', $receipt->id)->value('id'),
            'fee_receipt_id' => $receipt->id,
            'admission_id' => $receipt->admission_id,
            'student_application_id' => $appId,
            'student_id' => $receipt->student_id,
            'academic_year' => $receipt->academic_year,
            'paid_amount' => $amount,
            'deduction' => 0,
            'refund_amount' => $amount,
            'reason' => $reason,
            'status' => 'pending',
            'requested_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Refund for a cancelled admission: everything paid on it, less the
     * cancellation charge. Nothing paid → no row. Idempotent per admission.
     *
     * @return array{0:?int,1:float,2:float} [refund id, paid, refund amount]
     */
    public static function openForAdmissionCancel(int $admissionId, float $deduction, ?string $reason, ?int $userId): array
    {
        $paid = self::paidForAdmission($admissionId);
        $deduction = max(0, min($deduction, $paid));
        $refund = round($paid - $deduction, 2);

        if ($paid <= 0) {
            return [null, 0.0, 0.0];
        }
        $existing = DB::table('fee_refunds')->where('admission_id', $admissionId)->where('source', 'admission_cancel')->value('id');
        if ($existing) {
            return [(int) $existing, $paid, $refund];
        }

        $adm = DB::table('admissions')->where('id', $admissionId)->first();
        $id = DB::table('fee_refunds')->insertGetId([
            'organization_id' => $adm->organization_id ?? null,
            'source' => 'admission_cancel',
            'payment_id' => DB::table('payments')->where('admission_id', $admissionId)->where('status', 'paid')->orderByDesc('id')->value('id'),
            'fee_receipt_id' => null,
            'admission_id' => $admissionId,
            'student_application_id' => $adm->application_id ?? null,
            'student_id' => $adm->student_id ?? null,
            'academic_year' => $adm->academic_year ?? null,
            'paid_amount' => $paid,
            'deduction' => $deduction,
            'refund_amount' => $refund,
            'reason' => $reason,
            // Charge equal to the fee → nothing to pay back, record closed at once.
            'status' => $refund > 0 ? 'pending' : 'refunded',
            'refund_remarks' => $refund > 0 ? null : 'No refund — cancellation charge equals the fee paid.',
            'refunded_at' => $refund > 0 ? null : now(),
            'requested_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return [(int) $id, $paid, $refund];
    }

    /** @return array{0:int,1:string} */
    public static function markRefunded(int $refundId, string $ref, ?string $mode, ?string $remarks, ?int $userId): array
    {
        $row = DB::table('fee_refunds')->where('id', $refundId)->first();
        if (!$row) {
            return [404, 'Refund record not found.'];
        }
        if ($row->status !== 'pending') {
            return [422, 'This refund is already recorded.'];
        }
        DB::table('fee_refunds')->where('id', $refundId)->update([
            'status' => 'refunded',
            'refund_ref' => $ref,
            'refund_mode' => $mode,
            'refund_remarks' => $remarks,
            'refunded_by' => $userId,
            'refunded_at' => now(),
            'updated_at' => now(),
        ]);
        return [200, 'Refund recorded.'];
    }
}
