<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Cancelling / rejecting a fee receipt.
 *
 * The receipt row is never deleted — the money was received, so it stays as a
 * cancelled receipt marked refund "pending" until the office records the
 * refund (markRefunded). The application it paid for goes back to unpaid so
 * the student can pay again.
 *
 * Refused once the student has been admitted on that payment (receipt
 * verified, or the admission already active) — that case is Cancel Admission.
 */
class ReceiptCancellation
{
    /** @return array{0:int,1:string} [http status, message] */
    public static function cancel(int $receiptId, ?string $reason, ?int $userId): array
    {
        $receipt = DB::table('fee_receipts')->where('id', $receiptId)->first();
        if (!$receipt) {
            return [404, 'Receipt not found.'];
        }
        if ($receipt->status === 'cancelled') {
            return [422, 'This receipt is already cancelled.'];
        }

        $appIds = DB::table('payments')->where('fee_receipt_id', $receipt->id)->whereNotNull('student_application_id')
            ->pluck('student_application_id')->all();
        $apps = DB::table('student_applications')->whereIn('id', $appIds ?: [0])->get(['id', 'status', 'application_type']);
        $admissionIds = DB::table('admissions')
            ->whereIn('application_id', $apps->pluck('id')->all() ?: [0])
            ->when($receipt->admission_id, fn($q) => $q->orWhere('id', $receipt->admission_id))
            ->get(['id', 'status']);

        if ($receipt->is_verified || $admissionIds->contains(fn($a) => $a->status === 'active')) {
            return [409, 'The student is already admitted on this receipt. Use Cancel Admission instead.'];
        }

        DB::transaction(function () use ($receipt, $reason, $userId, $apps) {
            DB::table('fee_receipts')->where('id', $receipt->id)->update([
                'status' => 'cancelled',
                'cancel_reason' => $reason,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'updated_at' => now(),
            ]);
            Payments::cancelForReceipt((int) $receipt->id, $reason);
            FeeRefunds::openForReceipt($receipt, $reason, $userId);

            // The application / admission read as unpaid again automatically
            // (every reader queries `payments` live — see App\Support\Payments)
            // now that cancelForReceipt() cancelled the payment row above.
            if ($apps->isNotEmpty()) {
                // A back paper is submitted by paying — unpaid, it is a draft again.
                DB::table('student_applications')
                    ->whereIn('id', $apps->where('application_type', 'back_paper')->pluck('id')->all() ?: [0])
                    ->where('status', 'submitted')
                    ->update(['status' => 'draft']);
            }
        });

        return [200, 'Receipt cancelled. The application is unpaid again; refund is marked pending.'];
    }

    /** Record the refund of a cancelled receipt (looked up by receipt id). @return array{0:int,1:string} */
    public static function markRefunded(int $receiptId, ?string $refundRef, ?string $remarks, ?int $userId): array
    {
        $refundId = DB::table('fee_refunds')->where('fee_receipt_id', $receiptId)->value('id');
        if (!$refundId) {
            return [422, 'No refund is pending for this receipt.'];
        }
        return FeeRefunds::markRefunded((int) $refundId, (string) $refundRef, null, $remarks, $userId);
    }
}
