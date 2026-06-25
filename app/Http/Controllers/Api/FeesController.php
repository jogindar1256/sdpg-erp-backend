<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class FeesController extends Controller
{
    // ─── Shared: base receipt query ──────────────────────────────────────────────
    protected function baseReceiptQuery(Request $request)
    {
        $q = DB::table('fee_receipts as fr')
            ->join('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->join('students as s', 's.id', '=', 'adm.student_id')
            ->join('classes as cl', 'cl.id', '=', 'adm.class_id')
            ->select([
                'fr.id', 'fr.receipt_no', 'fr.fee_type', 'fr.amount',
                'fr.utr_no', 'fr.bank_ref_no', 'fr.payment_date',
                'fr.status', 'fr.created_at',
                DB::raw("CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) as student_name"),
                's.father_name', 'cl.name as class_name', 'adm.semester_no',
                'fr.admission_id',
                DB::raw('(SELECT name FROM users WHERE id = fr.created_by LIMIT 1) as issued_by'),
            ]);

        if ($s = $request->session)      $q->where('adm.session', $s);
        if ($c = $request->class_id)     $q->where('adm.class_id', $c);
        if ($sem = $request->semester)   $q->where('adm.semester_no', $sem);
        if ($t = $request->fee_type)     $q->where('fr.fee_type', $t);
        if ($st = $request->status)      $q->where('fr.status', $st);
        if ($df = $request->date_from)   $q->whereDate('fr.created_at', '>=', $df);
        if ($dt = $request->date_to)     $q->whereDate('fr.created_at', '<=', $dt);

        if ($search = $request->search) {
            $q->where(function ($w) use ($search) {
                $w->whereRaw("CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) ILIKE ?", ["%$search%"])
                  ->orWhere('adm.application_no', 'ILIKE', "%$search%")
                  ->orWhere('adm.registration_no', 'ILIKE', "%$search%")
                  ->orWhere('fr.utr_no', 'ILIKE', "%$search%")
                  ->orWhere('fr.receipt_no', 'ILIKE', "%$search%");
            });
        }

        return $q;
    }

    // ─── All Fee Receipts: GET /fees/receipts ────────────────────────────────────
    public function receiptsIndex(Request $request): JsonResponse
    {
        $q = $this->baseReceiptQuery($request)->orderByDesc('fr.created_at');

        $perPage = 20;
        $total   = $q->count();
        $data    = $q->forPage($request->page ?? 1, $perPage)->get();

        $summary = DB::table('fee_receipts as fr')
            ->join('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->when($request->session, fn($w, $s) => $w->where('adm.session', $s))
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN fr.status='Verified' THEN 1 ELSE 0 END) as verified,
                SUM(CASE WHEN fr.status='Pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN fr.status='Verified' THEN fr.amount ELSE 0 END) as total_amount
            ")->first();

        return response()->json([
            'data'    => $data,
            'meta'    => ['total' => $total, 'last_page' => ceil($total / $perPage), 'per_page' => $perPage],
            'summary' => $summary,
            'classes' => DB::table('classes')->select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    // ─── Verify Fee Receipts: GET /fees/verify ───────────────────────────────────
    public function verifyIndex(Request $request): JsonResponse
    {
        $q = $this->baseReceiptQuery($request)
            ->where('fr.status', 'Pending')
            ->orderBy('fr.created_at');

        $data = $q->get();

        $summary = DB::table('fee_receipts as fr')
            ->join('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->when($request->session, fn($w, $s) => $w->where('adm.session', $s))
            ->selectRaw("
                SUM(CASE WHEN fr.status='Pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN fr.status='Verified' AND DATE(fr.updated_at)=CURRENT_DATE THEN 1 ELSE 0 END) as verified_today,
                SUM(CASE WHEN fr.status='Pending' THEN fr.amount ELSE 0 END) as pending_amount
            ")->first();

        return response()->json([
            'data'    => $data,
            'summary' => $summary,
            'classes' => DB::table('classes')->select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    // ─── Verify: POST /fees/verify/{id}/verify ───────────────────────────────────
    public function verifyAction(Request $request, int $id, string $act): JsonResponse
    {
        $receipt = DB::table('fee_receipts')->where('id', $id)->first();
        if (!$receipt) return response()->json(['message' => 'Receipt not found.'], 404);

        if (!in_array($act, ['verify', 'reject'])) {
            return response()->json(['message' => 'Invalid action.'], 422);
        }

        $status = $act === 'verify' ? 'Verified' : 'Rejected';

        DB::table('fee_receipts')->where('id', $id)->update([
            'status'      => $status,
            'verified_by' => Auth::id(),
            'verified_at' => now(),
            'remarks'     => $request->remarks,
            'updated_at'  => now(),
        ]);

        // Log to authorization_logs
        DB::table('authorization_logs')->insert([
            'action_type'  => 'FeeReceipt',
            'action'       => $status,
            'admission_id' => $receipt->admission_id,
            'reference_id' => $id,
            'remarks'      => $request->remarks,
            'performed_by' => Auth::id(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return response()->json(['message' => "Receipt {$status} successfully."]);
    }

    // ─── Student Ledger: GET /fees/ledger ────────────────────────────────────────
    public function ledgerIndex(Request $request): JsonResponse
    {
        $query = $request->query;
        if (!$query) return response()->json(['message' => 'Query required.'], 422);

        // Find student
        $admission = DB::table('admissions as adm')
            ->join('students as s', 's.id', '=', 'adm.student_id')
            ->join('classes as cl', 'cl.id', '=', 'adm.class_id')
            ->select([
                'adm.id as admission_id', 'adm.application_no', 'adm.registration_no as reg_no',
                'adm.university_roll_no', 'adm.session', 'adm.semester_no',
                'adm.fee_status', 'adm.student_id',
                DB::raw("CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) as name"),
                's.father_name', 's.mother_name', 's.mobile', 's.gender', 's.category',
                'cl.name as class_name',
            ])
            ->where(function ($w) use ($query) {
                $w->where('adm.application_no', $query)
                  ->orWhere('adm.registration_no', $query)
                  ->orWhere('adm.university_roll_no', $query)
                  ->orWhere('s.mobile', $query);
            })
            ->orderByDesc('adm.created_at')
            ->first();

        if (!$admission) return response()->json(['student' => null]);

        // Required fee from fee_structures
        $totalRequired = DB::table('fee_structures')
            ->where('class_id', DB::table('admissions')->where('id', $admission->admission_id)->value('class_id'))
            ->where('semester_no', $admission->semester_no)
            ->sum('amount');

        // Build ledger from fee_receipts
        $receipts = DB::table('fee_receipts as fr')
            ->where('fr.admission_id', $admission->admission_id)
            ->select([
                'fr.id', 'fr.fee_type as description', 'fr.amount', 'fr.status',
                'fr.receipt_no', 'fr.payment_date as date', 'fr.created_at',
                DB::raw("'credit' as entry_type"),
                DB::raw('(SELECT name FROM users WHERE id = fr.created_by LIMIT 1) as created_by'),
            ])
            ->orderBy('fr.created_at')
            ->get();

        // Add any debit entries (refunds, cancellations) from amendment_logs
        // For now return credit-only ledger
        $student = (array)$admission + ['total_required_fee' => $totalRequired];

        return response()->json([
            'student' => $student,
            'ledger'  => $receipts,
        ]);
    }

    // ─── Financial Summary: GET /fees/summary ────────────────────────────────────
    public function summaryIndex(Request $request): JsonResponse
    {
        $session  = $request->session ?? date('Y') . '-' . (date('Y') + 1);
        $classId  = $request->class_id;

        $admQuery = DB::table('admissions as adm')
            ->where('adm.session', $session)
            ->when($classId, fn($q) => $q->where('adm.class_id', $classId));

        // Total required fee from fee_structures (sum for each student's class/semester)
        $totalRequired = DB::table('admissions as adm')
            ->join('fee_structures as fs', function ($j) {
                $j->on('fs.class_id', '=', 'adm.class_id')
                  ->on('fs.semester_no', '=', 'adm.semester_no');
            })
            ->where('adm.session', $session)
            ->when($classId, fn($q) => $q->where('adm.class_id', $classId))
            ->sum('fs.amount');

        $totalCollected = DB::table('fee_receipts as fr')
            ->join('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->where('adm.session', $session)
            ->when($classId, fn($q) => $q->where('adm.class_id', $classId))
            ->where('fr.status', 'Verified')
            ->sum('fr.amount');

        $totalStudents = (clone $admQuery)->count();

        $statusBreakdown = (clone $admQuery)
            ->selectRaw("
                SUM(CASE WHEN adm.fee_status='Paid' THEN 1 ELSE 0 END) as fee_paid,
                SUM(CASE WHEN adm.fee_status='Partial' THEN 1 ELSE 0 END) as fee_partial,
                SUM(CASE WHEN adm.fee_status='Pending' OR adm.fee_status IS NULL THEN 1 ELSE 0 END) as fee_pending
            ")->first();

        $summary = [
            'total_students'     => $totalStudents,
            'total_required'     => $totalRequired,
            'total_collected'    => $totalCollected,
            'total_outstanding'  => max(0, $totalRequired - $totalCollected),
            'fee_paid'           => $statusBreakdown->fee_paid ?? 0,
            'fee_partial'        => $statusBreakdown->fee_partial ?? 0,
            'fee_pending'        => $statusBreakdown->fee_pending ?? 0,
        ];

        // By fee type
        $byFeeType = DB::table('fee_receipts as fr')
            ->join('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->where('adm.session', $session)
            ->when($classId, fn($q) => $q->where('adm.class_id', $classId))
            ->selectRaw("
                fr.fee_type,
                SUM(CASE WHEN fr.status='Verified' THEN fr.amount ELSE 0 END) as collected,
                SUM(CASE WHEN fr.status='Pending' THEN fr.amount ELSE 0 END) as pending,
                COUNT(*) as count
            ")
            ->groupBy('fr.fee_type')
            ->orderByDesc('collected')
            ->get();

        // By class
        $byClass = DB::table('admissions as adm')
            ->join('classes as cl', 'cl.id', '=', 'adm.class_id')
            ->leftJoin('fee_structures as fs', function ($j) {
                $j->on('fs.class_id', '=', 'adm.class_id')->on('fs.semester_no', '=', 'adm.semester_no');
            })
            ->leftJoin('fee_receipts as fr', function ($j) {
                $j->on('fr.admission_id', '=', 'adm.id')->where('fr.status', 'Verified');
            })
            ->where('adm.session', $session)
            ->when($classId, fn($q) => $q->where('adm.class_id', $classId))
            ->selectRaw("
                cl.name as class_name,
                COALESCE(SUM(fs.amount),0) as required,
                COALESCE(SUM(fr.amount),0) as collected,
                GREATEST(COALESCE(SUM(fs.amount),0)-COALESCE(SUM(fr.amount),0),0) as outstanding
            ")
            ->groupBy('adm.class_id', 'cl.name')
            ->orderBy('cl.name')
            ->get();

        // By semester
        $bySemester = DB::table('admissions as adm')
            ->leftJoin('fee_structures as fs', function ($j) {
                $j->on('fs.class_id', '=', 'adm.class_id')->on('fs.semester_no', '=', 'adm.semester_no');
            })
            ->leftJoin('fee_receipts as fr', function ($j) {
                $j->on('fr.admission_id', '=', 'adm.id')->where('fr.status', 'Verified');
            })
            ->where('adm.session', $session)
            ->when($classId, fn($q) => $q->where('adm.class_id', $classId))
            ->selectRaw("
                adm.semester_no,
                COUNT(DISTINCT adm.id) as students,
                COALESCE(SUM(DISTINCT fs.amount),0) as required,
                COALESCE(SUM(fr.amount),0) as collected
            ")
            ->groupBy('adm.semester_no')
            ->orderBy('adm.semester_no')
            ->get();

        // Recent 20 receipts
        $recentReceipts = DB::table('fee_receipts as fr')
            ->join('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->join('students as s', 's.id', '=', 'adm.student_id')
            ->join('classes as cl', 'cl.id', '=', 'adm.class_id')
            ->where('adm.session', $session)
            ->when($classId, fn($q) => $q->where('adm.class_id', $classId))
            ->where('fr.status', 'Verified')
            ->selectRaw("
                fr.id, fr.fee_type, fr.amount, fr.utr_no, fr.created_at,
                CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) as student_name,
                cl.name as class_name, adm.semester_no,
                (SELECT name FROM users WHERE id=fr.created_by LIMIT 1) as issued_by
            ")
            ->orderByDesc('fr.created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'summary'         => $summary,
            'by_fee_type'     => $byFeeType,
            'by_class'        => $byClass,
            'by_semester'     => $bySemester,
            'recent_receipts' => $recentReceipts,
            'classes'         => DB::table('classes')->select('id', 'name')->orderBy('name')->get(),
        ]);
    }
}
