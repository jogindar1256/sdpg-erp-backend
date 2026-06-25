<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use NumberFormatter;

class FinancialController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // SHARED: lookup student by any identifier
    // ─────────────────────────────────────────────────────────────────────────
    private function findStudent(string $key): ?object
    {
        return DB::table('admissions as a')
            ->join('students as s',  's.id', '=', 'a.student_id')
            ->join('classes as c',   'c.id', '=', 'a.class_id')
            ->leftJoin('programs as p', 'p.id', '=', 'a.program_id')
            ->select(
                'a.id as admission_id', 'a.student_id', 'a.application_no',
                'a.reg_no', 'a.university_roll_no', 'a.session',
                'a.semester_no', 'a.required_fee', 'a.fine_amount',
                's.name', 's.father_name', 's.mother_name', 's.spouse_name',
                's.gender', 's.category', 's.mobile', 's.aadhar_no',
                'c.name as class_name', 'p.name as program_name'
            )
            ->where(function ($q) use ($key) {
                $q->where('a.application_no',      $key)
                  ->orWhere('a.reg_no',             $key)
                  ->orWhere('a.university_roll_no', $key)
                  ->orWhere('a.student_id',         $key)
                  ->orWhere('s.mobile',             $key);
            })
            ->orderByDesc('a.created_at')
            ->first();
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

    // ─────────────────────────────────────────────────────────────────────────
    // 1. CREATE FEE TRANSFER VOUCHER
    // GET /financial/fee-transfer-voucher?search=...&session=...&category=...&gender=...
    // ─────────────────────────────────────────────────────────────────────────
    public function feeTransferVoucherIndex(Request $request)
    {
        // Return fee-head definitions + optional student data
        $feeHeads = DB::table('fee_heads')
            ->orderBy('sort_order')
            ->get();

        $student = null;
        if ($search = $request->input('search')) {
            $student = $this->findStudent($search);
        }

        // Fetch voucher rows for a student if found
        $voucher = null;
        if ($student) {
            $voucher = DB::table('fee_transfer_vouchers as v')
                ->where('v.admission_id', $student->admission_id)
                ->where('v.session', $request->input('session', $student->session))
                ->with('items')
                ->first();

            // Also attach fee breakdown from fee_receipts
            $paidFees = DB::table('fee_receipts')
                ->where('admission_id', $student->admission_id)
                ->select('fee_type', 'amount', 'bank_account', 'receipt_no')
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
            'admission_id'      => 'required|integer',
            'session'           => 'required|string',
            'items'             => 'required|array',
            'items.*.fee_type'  => 'required|string',
            'items.*.bank_account' => 'nullable|string',
            'items.*.amount'    => 'required|numeric|min:0',
            'transfer_details'  => 'nullable|array',
        ]);

        $grandTotal = collect($request->input('items'))->sum('amount');

        DB::transaction(function () use ($request, $grandTotal) {
            // Upsert voucher header
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

            // Insert items
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
    // GET /financial/online-fee-accept?reg_no=...
    // ─────────────────────────────────────────────────────────────────────────
    public function onlineFeeAcceptSearch(Request $request)
    {
        $key = $request->input('query', '');
        if (!$key) return response()->json(['student' => null, 'history' => []]);

        $student = $this->findStudent($key);

        $history = [];
        if ($student) {
            $history = DB::table('fee_receipts as fr')
                ->join('admissions as a', 'a.id', '=', 'fr.admission_id')
                ->join('students as s',   's.id', '=', 'a.student_id')
                ->join('classes as c',    'c.id', '=', 'a.class_id')
                ->where('a.student_id', $student->student_id)
                ->select(
                    'fr.id', 'fr.fee_type as paid_for', 'fr.amount as paid_fee',
                    'fr.paid_date as pay_date', 'fr.receipt_no as pay_ref_no',
                    'fr.bank_ref_no', 'fr.utr_no', 'fr.status',
                    'a.reg_no', 'a.semester_no', 's.name', 's.father_name',
                    'c.name as class_name'
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
            'admission_id' => 'required|integer',
            'payment_for'  => 'required|string',
            'bank_ref_no'  => 'required|string',
            'utr_no'       => 'required|string',
            'payment_date' => 'required|date',
            'amount'       => 'required|numeric|min:1',
        ]);

        $receiptNo = 'RCP' . date('Y') . str_pad(
            DB::table('fee_receipts')->max('id') + 1, 6, '0', STR_PAD_LEFT
        );

        DB::table('fee_receipts')->insert([
            'admission_id' => $request->input('admission_id'),
            'fee_type'     => $request->input('payment_for'),
            'amount'       => $request->input('amount'),
            'paid_date'    => $request->input('payment_date'),
            'bank_ref_no'  => $request->input('bank_ref_no'),
            'utr_no'       => $request->input('utr_no'),
            'receipt_no'   => $receiptNo,
            'status'       => 'Paid',
            'issued_by'    => auth()->id(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Update admission fee status
        DB::table('admissions')
            ->where('id', $request->input('admission_id'))
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
                ->join('admissions as a', 'a.id', '=', 'tu.admission_id')
                ->join('students as s',   's.id', '=', 'a.student_id')
                ->join('classes as c',    'c.id', '=', 'a.class_id')
                ->where('a.student_id', $student->student_id)
                ->select(
                    'tu.id', 'tu.fee_update_for', 'tu.amount as update_fee',
                    'tu.paid_date as pay_date', 'tu.payment_ref_no',
                    'tu.utr_no', 'tu.bank_ref_no', 'tu.gateway_status',
                    'tu.ref_no', 'tu.update_date', 'tu.status',
                    'tu.created_by', 'tu.approved_by',
                    'a.reg_no', 'a.semester_no', 's.name', 's.father_name',
                    'c.name as class_name'
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
            'admission_id'    => 'required|integer',
            'fee_update_for'  => 'required|string',
            'amount'          => 'required|numeric|min:0',
            'paid_date'       => 'required|date',
            'utr_no'          => 'required|string',
            'bank_ref_no'     => 'required|string',
            'gateway_status'  => 'nullable|string',
            'update_created_by' => 'nullable|string',
        ]);

        $refNo = 'TXU' . date('Y') . str_pad(
            DB::table('transaction_updates')->max('id') + 1, 6, '0', STR_PAD_LEFT
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
            'created_by'      => $request->input('update_created_by') ?? auth()->id(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // Also update the matching fee_receipt if found
        if ($request->input('utr_no')) {
            DB::table('fee_receipts')
                ->where('admission_id', $request->input('admission_id'))
                ->where('fee_type', $request->input('fee_update_for'))
                ->update([
                    'utr_no'     => $request->input('utr_no'),
                    'bank_ref_no'=> $request->input('bank_ref_no'),
                    'paid_date'  => $request->input('paid_date'),
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'message' => 'Transaction update saved. Pending approval.',
            'ref_no'  => $refNo,
        ]);
    }
}