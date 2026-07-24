<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ResolvesStudentIdentity;
use App\Models\PaymentLedger;
use DB;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PaymentLedgerController extends Controller
{
    use ResolvesStudentIdentity;

    // ── LIST ──────────────────────────────────────────────────────────────
    public function index(Request $req): JsonResponse
    {
        $latestReg = $this->latestRegistrationSub();

        $q = DB::table('payment_ledger as pl')
            ->leftJoin('students as s', 's.id', 'pl.student_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->leftJoin('admissions as a', 'a.id', 'pl.admission_id')
            ->leftJoin('programs as p', 'p.id', 'a.program_id')
            ->leftJoin('users as cu', 'cu.id', 'pl.created_by')
            ->leftJoin('users as vu', 'vu.id', 'pl.verified_by')
            ->select(
                'pl.*',
                'dr.name as student_name',
                's.first_name',
                's.middle_name',
                's.last_name',
                's.mobile',
                's.category',
                'a.reg_no',
                'a.semester_no as adm_semester',
                'p.short_name as prog_short',
                'cu.name as created_by_name',
                'vu.name as verified_by_name'
            )
            ->whereNull('pl.deleted_at')
            ->when($req->txn_type, fn($q) => $q->where('pl.txn_type', $req->txn_type))
            ->when($req->payment_mode, fn($q) => $q->where('pl.payment_mode', $req->payment_mode))
            ->when($req->status, fn($q) => $q->where('pl.status', $req->status))
            ->when($req->session_year, fn($q) => $q->where('pl.session_year', $req->session_year))
            ->when($req->semester_no, fn($q) => $q->where('pl.semester_no', $req->semester_no))
            ->when($req->from_date, fn($q) => $q->whereDate('pl.paid_at', '>=', $req->from_date))
            ->when($req->to_date, fn($q) => $q->whereDate('pl.paid_at', '<=', $req->to_date))
            ->when($req->search, fn($q) => $q->where(function ($q2) use ($req) {
                $q2->where('pl.txn_no', 'ilike', "%{$req->search}%")
                    ->orWhere('pl.receipt_no', 'ilike', "%{$req->search}%")
                    ->orWhere('pl.reg_no', 'ilike', "%{$req->search}%")
                    ->orWhere('dr.name', 'ilike', "%{$req->search}%")
                    ->orWhere('s.first_name', 'ilike', "%{$req->search}%")
                    ->orWhere('s.last_name', 'ilike', "%{$req->search}%")
                    ->orWhere('s.mobile', 'ilike', "%{$req->search}%")
                    ->orWhere('pl.utr_no', 'ilike', "%{$req->search}%")
                    ->orWhere('pl.bank_ref_no', 'ilike', "%{$req->search}%");
            }))
            ->orderByDesc('pl.created_at');

        $result = $q->paginate($req->per_page ?? 50);
        $result->getCollection()->transform(function ($row) {
            if (empty($row->student_name)) {
                $row->student_name = trim(implode(' ', array_filter([
                    $row->first_name ?? null,
                    $row->middle_name ?? null,
                    $row->last_name ?? null,
                ])));
            }
            return $row;
        });

        return response()->json($result);
    }

    // ── SUMMARY ───────────────────────────────────────────────────────────
    public function summary(Request $req): JsonResponse
    {
        $session = $req->session_year ?? date('Y') . '-' . (date('Y') + 1);

        $base = DB::table('payment_ledger')
            ->whereNull('deleted_at')
            ->where('session_year', $session);

        // Totals by type
        $byType = (clone $base)
            ->where('status', PaymentLedger::STATUS_SUCCESS)
            ->groupBy('txn_type')
            ->selectRaw('txn_type, COUNT(*) as count, SUM(credit) as total_credit, SUM(debit) as total_debit')
            ->get();

        // Totals by mode
        $byMode = (clone $base)
            ->where('status', PaymentLedger::STATUS_SUCCESS)
            ->groupBy('payment_mode')
            ->selectRaw('payment_mode, COUNT(*) as count, SUM(credit) as total')
            ->get();

        // Totals by status
        $byStatus = (clone $base)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as count, SUM(amount) as total')
            ->get();

        // Grand totals
        $grand = (clone $base)->where('status', PaymentLedger::STATUS_SUCCESS)
            ->selectRaw('SUM(credit) as total_collected, SUM(debit) as total_refunded, COUNT(*) as total_txns')
            ->first();

        // Pending offline payments awaiting verification
        $pendingOffline = (clone $base)
            ->where('status', PaymentLedger::STATUS_PENDING)
            ->whereNotIn('payment_mode', [PaymentLedger::MODE_RAZORPAY])
            ->selectRaw('COUNT(*) as count, SUM(amount) as total')
            ->first();

        return response()->json([
            'session' => $session,
            'grand' => $grand,
            'by_type' => $byType,
            'by_mode' => $byMode,
            'by_status' => $byStatus,
            'pending_offline' => $pendingOffline,
        ]);
    }

    // ── STUDENT STATEMENT ─────────────────────────────────────────────────
    public function studentStatement(int $studentId, Request $req): JsonResponse
    {
        $txns = DB::table('payment_ledger as pl')
            ->leftJoin('users as cu', 'cu.id', 'pl.created_by')
            ->select('pl.*', 'cu.name as created_by_name')
            ->where('pl.student_id', $studentId)
            ->whereNull('pl.deleted_at')
            ->when($req->session_year, fn($q) => $q->where('pl.session_year', $req->session_year))
            ->orderBy('pl.paid_at')
            ->get();

        $totalPaid = $txns->where('status', 'Success')->sum('credit');
        $totalRefunded = $txns->where('txn_type', 'Refund')->sum('debit');

        return response()->json([
            'transactions' => $txns,
            'total_paid' => $totalPaid,
            'total_refunded' => $totalRefunded,
            'net_paid' => $totalPaid - $totalRefunded,
        ]);
    }

    // ── SHOW ──────────────────────────────────────────────────────────────
    public function show(int $id): JsonResponse
    {
        $latestReg = $this->latestRegistrationSub();

        $txn = DB::table('payment_ledger as pl')
            ->leftJoin('students as s', 's.id', 'pl.student_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->leftJoin('admissions as a', 'a.id', 'pl.admission_id')
            ->leftJoin('programs as p', 'p.id', 'a.program_id')
            ->leftJoin('users as cu', 'cu.id', 'pl.created_by')
            ->leftJoin('users as vu', 'vu.id', 'pl.verified_by')
            ->select(
                'pl.*',
                'dr.name as student_name',
                'dr.father_name',
                's.first_name',
                's.middle_name',
                's.last_name',
                's.mobile',
                's.email',
                's.category',
                'a.reg_no',
                'a.semester_no as adm_semester',
                'a.academic_year',
                'p.short_name as prog_short',
                'p.full_name as prog_full',
                'cu.name as created_by_name',
                'vu.name as verified_by_name'
            )
            ->where('pl.id', $id)
            ->whereNull('pl.deleted_at')
            ->first();

        if (!$txn)
            return response()->json(['message' => 'Transaction not found.'], 404);

        if (empty($txn->student_name)) {
            $txn->student_name = trim(implode(' ', array_filter([
                $txn->first_name ?? null,
                $txn->middle_name ?? null,
                $txn->last_name ?? null,
            ])));
        }

        return response()->json($txn);
    }

    // ── STORE OFFLINE PAYMENT ─────────────────────────────────────────────
    public function storeOffline(Request $req): JsonResponse
    {
        $req->validate([
            'txn_type' => 'required|string',
            'payment_mode' => 'required|string',
            'amount' => 'required|numeric|min:1',
            'session_year' => 'required|string',
            'student_id' => 'required|exists:students,id',
            'admission_id' => 'nullable|exists:admissions,id',
            'utr_no' => 'nullable|string',
            'bank_ref_no' => 'nullable|string',
            'cheque_dd_no' => 'nullable|string',
            'cheque_dd_date' => 'nullable|date',
            'bank_name' => 'nullable|string',
            'paid_at' => 'nullable|date',
        ]);

        $txn = PaymentLedger::record([
            'organization_id' => auth()->user()?->organization_id,
            'txn_type' => $req->txn_type,
            'payment_mode' => $req->payment_mode,
            'amount' => $req->amount,
            'status' => PaymentLedger::STATUS_PENDING, // pending verification
            'session_year' => $req->session_year,
            'semester_no' => $req->semester_no,
            'student_id' => $req->student_id,
            'admission_id' => $req->admission_id,
            'application_id' => $req->application_id,
            'reg_no' => $req->reg_no,
            'bank_ref_no' => $req->bank_ref_no,
            'utr_no' => $req->utr_no,
            'cheque_dd_no' => $req->cheque_dd_no,
            'cheque_dd_date' => $req->cheque_dd_date,
            'bank_name' => $req->bank_name,
            'bank_account' => $req->bank_account,
            'paid_at' => $req->paid_at,
            'description' => $req->description,
            'remarks' => $req->remarks,
            'created_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Transaction recorded. Pending verification.',
            'txn_no' => $txn->txn_no,
            'id' => $txn->id,
        ], 201);
    }

    // ── VERIFY OFFLINE PAYMENT ────────────────────────────────────────────
    public function verify(int $id, Request $req): JsonResponse
    {
        $txn = PaymentLedger::find($id);
        if (!$txn)
            return response()->json(['message' => 'Not found.'], 404);

        if ($txn->status !== PaymentLedger::STATUS_PENDING) {
            return response()->json(['message' => "Transaction is already {$txn->status}."], 409);
        }

        $txn->markSuccess([
            'verified_by' => auth()->id(),
            'verified_at' => now(),
            'remarks' => $req->remarks ?? $txn->remarks,
        ]);

        // Also sync to fee_receipts for backward compatibility
        if ($txn->admission_id) {
            DB::table('fee_receipts')->insert([
                'admission_id' => $txn->admission_id,
                'student_id' => $txn->student_id,
                'fee_type' => $txn->txn_type,
                'amount' => $txn->amount,
                'paid_date' => $txn->paid_at?->toDateString() ?? now()->toDateString(),
                'utr_no' => $txn->utr_no,
                'bank_ref_no' => $txn->bank_ref_no,
                'receipt_no' => $txn->receipt_no,
                'status' => 'Paid',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Payment verified.', 'receipt_no' => $txn->receipt_no]);
    }

    // ── REFUND ────────────────────────────────────────────────────────────
    public function refund(int $id, Request $req): JsonResponse
    {
        $req->validate(['amount' => 'required|numeric|min:1', 'reason' => 'required|string']);

        $original = PaymentLedger::find($id);
        if (!$original)
            return response()->json(['message' => 'Original transaction not found.'], 404);
        if ($original->status !== PaymentLedger::STATUS_SUCCESS) {
            return response()->json(['message' => 'Can only refund successful transactions.'], 409);
        }
        if ($req->amount > $original->amount) {
            return response()->json(['message' => 'Refund cannot exceed original amount.'], 422);
        }

        // Create a new DEBIT entry in the ledger
        $refund = PaymentLedger::record([
            'organization_id' => $original->organization_id,
            'txn_type' => PaymentLedger::TYPE_REFUND,
            'payment_mode' => $original->payment_mode,
            'amount' => $req->amount,
            'status' => PaymentLedger::STATUS_SUCCESS,
            'session_year' => $original->session_year,
            'student_id' => $original->student_id,
            'admission_id' => $original->admission_id,
            'reg_no' => $original->reg_no,
            'description' => "Refund against {$original->txn_no}: {$req->reason}",
            'remarks' => $req->reason,
            'created_by' => auth()->id(),
        ]);

        // Mark original as refunded
        $original->update(['status' => PaymentLedger::STATUS_REFUNDED]);

        return response()->json([
            'message' => 'Refund recorded.',
            'refund_txn' => $refund->txn_no,
        ]);
    }
}
