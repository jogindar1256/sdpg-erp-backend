<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesStudentIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use NumberFormatter;

/**
 * REBUILT against the real schema. Was joining a `classes` table that never
 * existed and reading admissions/fee_receipts columns that never existed
 * (application_no, reg_no, university_roll_no, session, required_fee,
 * fine_amount on admissions; fee_type/amount/utr_no/payment_date/created_by
 * on fee_receipts). See FeesController.php's header comment for the full
 * rundown of what the real columns actually are.
 */
class FinancialController extends Controller
{
    use ResolvesStudentIdentity;

    // ─────────────────────────────────────────────────────────────────────────
    // SHARED: lookup student by any identifier
    // ─────────────────────────────────────────────────────────────────────────
    private function findStudent(string $key): ?object
    {
        $latestReg = $this->latestRegistrationSub();

        $row = DB::table('admissions as a')
            ->join('students as s',  's.id', '=', 'a.student_id')
            ->leftJoin('programs as p', 'p.id', '=', 'a.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->select(
                'a.id as admission_id', 'a.student_id', 'a.admission_no',
                'a.academic_year as session', 'a.semester_no', 'a.program_id', 'a.admission_type',
                's.first_name', 's.middle_name', 's.last_name',
                'dr.name as reg_name', 'dr.father_name', 'dr.mother_name',
                's.gender', 's.category', 's.mobile', 's.aadhar_no', 's.abc_id',
                'p.short_name as class_name', 'p.full_name as program_name'
            )
            ->where(function ($q) use ($key) {
                $q->where('a.admission_no',  $key)
                  ->orWhere('a.student_id',  $key)
                  ->orWhere('s.mobile',      $key)
                  ->orWhere('s.aadhar_no',   $key)
                  ->orWhere('s.abc_id',      $key);
            })
            ->orderByDesc('a.created_at')
            ->first();

        if (!$row) return null;

        $row->name = !empty($row->reg_name) ? $row->reg_name
            : trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name])));

        // Required fee — computed on the fly from fee_structures (admissions
        // has nowhere to store a per-admission required/fine amount).
        $row->required_fee = (float) DB::table('fee_structures')
            ->where('program_id', $row->program_id)
            ->where('academic_year', $row->session)
            ->whereIn('semester_no', array_unique([0, (int) $row->semester_no]))
            ->where('admission_type', $row->admission_type)
            ->where('is_active', true)
            ->sum('amount');

        return $row;
    }

    // Convert number to words (INR)
    private function toWords(float $amount): string
    {
        try {
            $fmt = new NumberFormatter('en_IN', NumberFormatter::SPELLOUT);
            $rupees = (int) $amount;
            $paise  = round(($amount - $rupees) * 100);
            $words  = ucfirst($fmt->format($rupees)) . ' Rupees';
            if ($paise > 0) $words .= ' and ' . ucfirst($fmt->format($paise)) . ' Paise';
            return $words . ' Only';
        } catch (\Exception $e) {
            return 'Amount in words unavailable';
        }
    }

    /** Build the mandatory fee_receipts.fee_breakdown JSON for a single-line manual entry. */
    private function singleLineBreakdown(string $label, float $amount): array
    {
        return [['fee_head_id' => null, 'fee_head_name' => $label, 'amount' => $amount]];
    }

    private function nextReceiptNo(string $prefix): string
    {
        return $prefix . date('Y') . str_pad(
            (int) (DB::table('fee_receipts')->max('id') ?? 0) + 1, 6, '0', STR_PAD_LEFT
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. CREATE FEE TRANSFER VOUCHER
    // GET /financial/fee-transfer-voucher?search=...&session=...&category=...&gender=...
    // ─────────────────────────────────────────────────────────────────────────
    public function feeTransferVoucherIndex(Request $request)
    {
        // fee_heads has no sort_order column — order by category then name instead.
        $feeHeads = DB::table('fee_heads')
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        $student = null;
        if ($search = $request->input('search')) {
            $student = $this->findStudent($search);
        }

        $voucher = null;
        if ($student) {
            $voucher = DB::table('fee_transfer_vouchers as v')
                ->where('v.admission_id', $student->admission_id)
                ->where('v.session', $request->input('session', $student->session))
                ->first();

            if ($voucher) {
                // DB::table() query builder has no with() — load items separately.
                $voucher->items = DB::table('fee_transfer_voucher_items')
                    ->where('voucher_id', $voucher->id)
                    ->get();
            }

            $paidFees = DB::table('fee_receipts')
                ->where('admission_id', $student->admission_id)
                ->select(
                    'receipt_type as fee_type', 'net_amount as amount',
                    'bank_ref_no as bank_account', 'receipt_no'
                )
                ->get();
            $student->paid_fees = $paidFees;
        }

        return response()->json([
            'student'   => $student,
            'fee_heads' => $feeHeads,
            'voucher'   => $voucher,
        ]);
    }

    // POST /financial/fee-transfer-voucher — create/save a voucher
    public function feeTransferVoucherStore(Request $request)
    {
        $request->validate([
            'admission_id'      => 'required|integer|exists:admissions,id',
            'session'           => 'required|string',
            'items'             => 'required|array',
            'items.*.fee_type'  => 'required|string',
            'items.*.bank_account' => 'nullable|string',
            'items.*.amount'    => 'required|numeric|min:0',
            'transfer_details'  => 'nullable|array',
        ]);

        $grandTotal = collect($request->input('items'))->sum('amount');

        DB::transaction(function () use ($request, $grandTotal) {
            $voucherId = DB::table('fee_transfer_vouchers')->insertGetId([
                'admission_id'    => $request->input('admission_id'),
                'session'         => $request->input('session'),
                'grand_total'     => $grandTotal,
                'amount_in_words' => $this->toWords($grandTotal),
                'ref_no'          => $request->input('transfer_details.ref_no') ?? null,
                'transfer_amount' => $request->input('transfer_details.transfer_amount') ?? null,
                'transfer_date'   => $request->input('transfer_details.transfer_date') ?? null,
                'transfer_through'=> $request->input('transfer_details.transfer_through') ?? null,
                'instrument_no'   => $request->input('transfer_details.instrument_no') ?? null,
                'instrument_date' => $request->input('transfer_details.instrument_date') ?? null,
                'created_by'      => auth()->id(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            foreach ($request->input('items') as $item) {
                if ((float) $item['amount'] > 0) {
                    DB::table('fee_transfer_voucher_items')->insert([
                        'voucher_id'   => $voucherId,
                        'fee_type'     => $item['fee_type'],
                        'bank_account' => $item['bank_account'] ?? null,
                        'amount'       => $item['amount'],
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                }
            }
        });

        return response()->json([
            'message'         => 'Voucher created successfully.',
            'amount_in_words' => $this->toWords($grandTotal),
            'grand_total'     => $grandTotal,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. ONLINE FEE ACCEPT
    // GET /financial/online-fee-accept?query=...
    // ─────────────────────────────────────────────────────────────────────────
    public function onlineFeeAcceptSearch(Request $request)
    {
        $key = $request->input('query', '');
        if (!$key) return response()->json(['student' => null, 'history' => []]);

        $student = $this->findStudent($key);

        $history = [];
        if ($student) {
            $history = DB::table('fee_receipts as fr')
                ->where('fr.student_id', $student->student_id)
                ->select(
                    'fr.id', 'fr.receipt_type as paid_for', 'fr.net_amount as paid_fee',
                    'fr.receipt_date as pay_date', 'fr.receipt_no as pay_ref_no',
                    'fr.bank_ref_no', 'fr.transaction_id as utr_no',
                    DB::raw('CASE WHEN fr.status = \'cancelled\' THEN \'Rejected\' WHEN fr.is_verified THEN \'Verified\' ELSE \'Pending\' END as status')
                )
                ->orderByDesc('fr.created_at')
                ->get();
        }

        return response()->json(['student' => $student, 'history' => $history]);
    }

    // POST /financial/online-fee-accept — record an online payment
    public function onlineFeeAcceptStore(Request $request)
    {
        $request->validate([
            'admission_id' => 'required|integer|exists:admissions,id',
            'payment_for'  => 'required|string',
            'bank_ref_no'  => 'required|string',
            'utr_no'       => 'required|string',
            'payment_date' => 'required|date',
            'amount'       => 'required|numeric|min:1',
        ]);

        $admission = DB::table('admissions')->where('id', $request->input('admission_id'))->first();
        if (!$admission) return response()->json(['message' => 'Admission not found.'], 404);

        $receiptNo = $this->nextReceiptNo('RCP');
        $amount    = (float) $request->input('amount');

        DB::table('fee_receipts')->insert([
            'organization_id' => $admission->organization_id,
            'student_id'      => $admission->student_id,
            'admission_id'    => $admission->id,
            'academic_year'   => $admission->academic_year,
            'semester_no'     => $admission->semester_no,
            'receipt_type'    => 'miscellaneous',
            'receipt_no'      => $receiptNo,
            'receipt_date'    => $request->input('payment_date'),
            'total_amount'    => $amount,
            'net_amount'      => $amount,
            'payment_mode'    => 'online',
            'transaction_id'  => $request->input('utr_no'),
            'bank_ref_no'     => $request->input('bank_ref_no'),
            'fee_breakdown'   => json_encode($this->singleLineBreakdown($request->input('payment_for'), $amount)),
            'is_verified'     => false,
            'generated_by'    => auth()->id(),
            'status'          => 'active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('admissions')
            ->where('id', $admission->id)
            ->update(['fee_status' => 'Paid', 'updated_at' => now()]);

        return response()->json([
            'message'    => 'Payment processed successfully.',
            'receipt_no' => $receiptNo,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. UPDATE TRANSACTION
    // GET /financial/update-transaction?query=...
    // ─────────────────────────────────────────────────────────────────────────
    public function updateTransactionSearch(Request $request)
    {
        $key = $request->input('query', '');
        if (!$key) return response()->json(['student' => null, 'history' => []]);

        $student = $this->findStudent($key);

        $history = [];
        if ($student) {
            $history = DB::table('transaction_updates as tu')
                ->where('tu.admission_id', $student->admission_id)
                ->select(
                    'tu.id', 'tu.fee_update_for', 'tu.amount as update_fee',
                    'tu.paid_date as pay_date', 'tu.payment_ref_no',
                    'tu.utr_no', 'tu.bank_ref_no', 'tu.gateway_status',
                    'tu.ref_no', 'tu.update_date', 'tu.status',
                    'tu.created_by', 'tu.approved_by'
                )
                ->orderByDesc('tu.created_at')
                ->get();
        }

        return response()->json(['student' => $student, 'history' => $history]);
    }

    // POST /financial/update-transaction — create a transaction update record
    public function updateTransactionStore(Request $request)
    {
        $request->validate([
            'admission_id'    => 'required|integer|exists:admissions,id',
            'fee_update_for'  => 'required|string',
            'amount'          => 'required|numeric|min:0',
            'paid_date'       => 'required|date',
            'utr_no'          => 'required|string',
            'bank_ref_no'     => 'required|string',
            'gateway_status'  => 'nullable|string',
            'update_created_by' => 'nullable|string',
        ]);

        $refNo = 'TXU' . date('Y') . str_pad(
            (int) (DB::table('transaction_updates')->max('id') ?? 0) + 1, 6, '0', STR_PAD_LEFT
        );

        $id = DB::table('transaction_updates')->insertGetId([
            'admission_id'    => $request->input('admission_id'),
            'fee_update_for'  => $request->input('fee_update_for'),
            'amount'          => $request->input('amount'),
            'paid_date'       => $request->input('paid_date'),
            'utr_no'          => $request->input('utr_no'),
            'bank_ref_no'     => $request->input('bank_ref_no'),
            'gateway_status'  => $request->input('gateway_status'),
            'ref_no'          => $refNo,
            'update_date'     => now()->toDateString(),
            'status'          => 'Pending',
            'created_by'      => (string) ($request->input('update_created_by') ?? auth()->id()),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // Best-effort: reflect the corrected reference numbers on the most
        // recent receipt for this admission (fee_receipts has no fee_type
        // column to match "fee_update_for" against, so this matches on
        // admission_id + most recent rather than a specific fee line).
        if ($request->input('utr_no')) {
            DB::table('fee_receipts')
                ->where('admission_id', $request->input('admission_id'))
                ->orderByDesc('created_at')
                ->limit(1)
                ->update([
                    'transaction_id' => $request->input('utr_no'),
                    'bank_ref_no'    => $request->input('bank_ref_no'),
                    'receipt_date'   => $request->input('paid_date'),
                    'updated_at'     => now(),
                ]);
        }

        return response()->json([
            'message' => 'Transaction update saved. Pending approval.',
            'ref_no'  => $refNo,
        ]);
    }
}
