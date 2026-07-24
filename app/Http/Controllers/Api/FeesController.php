<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesStudentIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class FeesController extends Controller
{
    use ResolvesStudentIdentity;

    /** Compose a display name from a row carrying dr.name + s.first/middle/last_name. */
    private function composeName(?string $regName, ?string $first, ?string $middle, ?string $last): string
    {
        if (!empty($regName)) return $regName;
        return trim(implode(' ', array_filter([$first, $middle, $last])));
    }

    /** Frontend-facing status label from the real is_verified/status columns. */
    private function statusLabel(bool $isVerified, string $status): string
    {
        if ($status === 'cancelled') return 'Rejected';
        return $isVerified ? 'Verified' : 'Pending';
    }

    // ─── Shared: base receipt query ──────────────────────────────────────────────
    protected function baseReceiptQuery(Request $request)
    {
        $latestReg = $this->latestRegistrationSub();

        $q = DB::table('fee_receipts as fr')
            ->join('students as s', 's.id', '=', 'fr.student_id')
            ->leftJoin('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->leftJoin('programs as p', 'p.id', '=', 'adm.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->select([
                'fr.id', 'fr.receipt_no', 'fr.receipt_type as fee_type', 'fr.net_amount as amount',
                'fr.transaction_id as utr_no', 'fr.bank_ref_no', 'fr.receipt_date as payment_date',
                'fr.is_verified', 'fr.status', 'fr.created_at',
                's.first_name', 's.middle_name', 's.last_name',
                'dr.name as reg_name', 'dr.father_name',
                'p.short_name as class_name', 'fr.semester_no',
                'fr.admission_id', 'adm.admission_no',
                DB::raw('(SELECT name FROM users WHERE id = fr.generated_by LIMIT 1) as issued_by'),
            ]);

        if ($s = $request->session)      $q->where('fr.academic_year', $s);
        if ($c = $request->class_id)     $q->where('adm.program_id', $c);
        if ($sem = $request->semester)   $q->where('fr.semester_no', $sem);
        if ($t = $request->fee_type)     $q->where('fr.receipt_type', $t);
        if ($st = $request->status) {
            if ($st === 'Verified')      $q->where('fr.is_verified', true);
            elseif ($st === 'Pending')   $q->where('fr.is_verified', false)->where('fr.status', '!=', 'cancelled');
            elseif ($st === 'Rejected')  $q->where('fr.status', 'cancelled');
        }
        if ($df = $request->date_from)   $q->whereDate('fr.receipt_date', '>=', $df);
        if ($dt = $request->date_to)     $q->whereDate('fr.receipt_date', '<=', $dt);

        if ($search = $request->search) {
            $q->where(function ($w) use ($search) {
                $w->where('s.first_name', 'ilike', "%$search%")
                  ->orWhere('s.last_name', 'ilike', "%$search%")
                  ->orWhere('dr.name', 'ilike', "%$search%")
                  ->orWhere('adm.admission_no', 'ilike', "%$search%")
                  ->orWhere('fr.transaction_id', 'ilike', "%$search%")
                  ->orWhere('fr.receipt_no', 'ilike', "%$search%");
            });
        }

        return $q;
    }

    private function decorate($row)
    {
        $row->student_name = $this->composeName($row->reg_name, $row->first_name, $row->middle_name, $row->last_name);
        $row->status_label  = $this->statusLabel((bool) $row->is_verified, $row->status);
        return $row;
    }

    // ─── All Fee Receipts: GET /fees/receipts ────────────────────────────────────
    public function receiptsIndex(Request $request): JsonResponse
    {
        $q = $this->baseReceiptQuery($request)->orderByDesc('fr.created_at');

        $perPage = 20;
        $total   = $q->count();
        $data    = $q->forPage($request->page ?? 1, $perPage)->get()
            ->map(fn ($r) => $this->decorate($r));

        $summaryRows = $this->baseReceiptQuery($request)->get();
        $summary = [
            'total'        => $summaryRows->count(),
            'verified'     => $summaryRows->where('is_verified', true)->count(),
            'pending'      => $summaryRows->where('is_verified', false)->where('status', '!=', 'cancelled')->count(),
            'total_amount' => (float) $summaryRows->where('is_verified', true)->sum('amount'),
        ];

        return response()->json([
            'data'    => $data,
            'meta'    => ['total' => $total, 'last_page' => (int) ceil($total / $perPage), 'per_page' => $perPage],
            'summary' => $summary,
            'classes' => DB::table('programs')->where('is_active', true)->select('id', 'short_name as name')->orderBy('short_name')->get(),
        ]);
    }

    // ─── Verify Fee Receipts: GET /fees/verify ───────────────────────────────────
    public function verifyIndex(Request $request): JsonResponse
    {
        $q = $this->baseReceiptQuery($request)
            ->where('fr.is_verified', false)
            ->where('fr.status', '!=', 'cancelled')
            ->orderBy('fr.created_at');

        $data = $q->get()->map(fn ($r) => $this->decorate($r));

        $todayRows = DB::table('fee_receipts as fr')
            ->when($request->session, fn ($w, $s) => $w->where('fr.academic_year', $s))
            ->get();

        $summary = [
            'pending'        => $todayRows->where('is_verified', false)->where('status', '!=', 'cancelled')->count(),
            'verified_today' => $todayRows->where('is_verified', true)->filter(fn ($r) => $r->verified_at && substr($r->verified_at, 0, 10) === now()->toDateString())->count(),
            'pending_amount' => (float) $todayRows->where('is_verified', false)->where('status', '!=', 'cancelled')->sum('net_amount'),
        ];

        return response()->json([
            'data'    => $data,
            'summary' => $summary,
            'classes' => DB::table('programs')->where('is_active', true)->select('id', 'short_name as name')->orderBy('short_name')->get(),
        ]);
    }

    // ─── Verify: POST /fees/verify/{id}/{act} ────────────────────────────────────
    public function verifyAction(Request $request, int $id, string $act): JsonResponse
    {
        $receipt = DB::table('fee_receipts')->where('id', $id)->first();
        if (!$receipt) return response()->json(['message' => 'Receipt not found.'], 404);

        if (!in_array($act, ['verify', 'reject'])) {
            return response()->json(['message' => 'Invalid action.'], 422);
        }

        if ($act === 'verify') {
            DB::table('fee_receipts')->where('id', $id)->update([
                'is_verified' => true,
                'verified_by' => Auth::id(),
                'verified_at' => now(),
                'updated_at'  => now(),
            ]);
            $status = 'Verified';
        } else {
            DB::table('fee_receipts')->where('id', $id)->update([
                'status'        => 'cancelled',
                'cancel_reason' => $request->remarks,
                'verified_by'   => Auth::id(),
                'verified_at'   => now(),
                'updated_at'    => now(),
            ]);
            $status = 'Rejected';
        }

        // authorization_logs is real and matches these columns as-is.
        DB::table('authorization_logs')->insert([
            'action_type'  => 'FeeReceiptVerification',
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

        $latestReg = $this->latestRegistrationSub();

        $admission = DB::table('admissions as adm')
            ->join('students as s', 's.id', '=', 'adm.student_id')
            ->leftJoin('programs as p', 'p.id', '=', 'adm.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->select([
                'adm.id as admission_id', 'adm.admission_no', 'adm.program_id',
                'adm.academic_year as session', 'adm.semester_no', 'adm.admission_type',
                'adm.fee_status', 'adm.student_id',
                's.first_name', 's.middle_name', 's.last_name',
                'dr.name as reg_name', 'dr.father_name', 'dr.mother_name',
                's.mobile', 's.gender', 's.category',
                'p.short_name as class_name',
            ])
            ->where(function ($w) use ($query) {
                $w->where('adm.admission_no', $query)
                  ->orWhere('s.mobile', $query)
                  ->orWhere('s.aadhar_no', $query)
                  ->orWhere('s.abc_id', $query);
            })
            ->orderByDesc('adm.created_at')
            ->first();

        if (!$admission) return response()->json(['student' => null]);

        $totalRequired = DB::table('fee_structures')
            ->where('program_id', $admission->program_id)
            ->where('academic_year', $admission->session)
            ->whereIn('semester_no', array_unique([0, (int) $admission->semester_no]))
            ->where('admission_type', $admission->admission_type)
            ->where('is_active', true)
            ->sum('amount');

        $receipts = DB::table('fee_receipts as fr')
            ->where('fr.student_id', $admission->student_id)
            ->select([
                'fr.id', 'fr.receipt_type as description', 'fr.net_amount as amount', 'fr.status', 'fr.is_verified',
                'fr.receipt_no', 'fr.receipt_date as date', 'fr.created_at',
                DB::raw("'credit' as entry_type"),
                DB::raw('(SELECT name FROM users WHERE id = fr.generated_by LIMIT 1) as created_by'),
            ])
            ->orderBy('fr.created_at')
            ->get();

        $student = [
            'admission_id'       => $admission->admission_id,
            'admission_no'       => $admission->admission_no,
            'session'            => $admission->session,
            'semester_no'        => $admission->semester_no,
            'fee_status'         => $admission->fee_status,
            'student_id'         => $admission->student_id,
            'name'               => $this->composeName($admission->reg_name, $admission->first_name, $admission->middle_name, $admission->last_name),
            'father_name'        => $admission->father_name,
            'mother_name'        => $admission->mother_name,
            'mobile'             => $admission->mobile,
            'gender'             => $admission->gender,
            'category'           => $admission->category,
            'class_name'         => $admission->class_name,
            'total_required_fee' => (float) $totalRequired,
        ];

        return response()->json([
            'student' => $student,
            'ledger'  => $receipts,
        ]);
    }

    // ─── Financial Summary: GET /fees/summary ────────────────────────────────────
    public function summaryIndex(Request $request): JsonResponse
    {
        $session  = $request->session ?? date('Y') . '-' . (date('Y') + 1);
        $progId   = $request->class_id; // frontend field name kept as class_id; means program_id now

        $admQuery = DB::table('admissions as adm')
            ->where('adm.academic_year', $session)
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId));

        $totalRequired = DB::table('admissions as adm')
            ->join('fee_structures as fs', function ($j) {
                $j->on('fs.program_id', '=', 'adm.program_id')
                  ->on('fs.semester_no', '=', 'adm.semester_no')
                  ->where('fs.is_active', true);
            })
            ->where('adm.academic_year', $session)
            ->whereColumn('fs.academic_year', 'adm.academic_year')
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId))
            ->sum('fs.amount');

        $totalCollected = DB::table('fee_receipts as fr')
            ->join('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->where('adm.academic_year', $session)
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId))
            ->where('fr.is_verified', true)
            ->sum('fr.net_amount');

        $totalStudents = (clone $admQuery)->count();

        $statusBreakdown = (clone $admQuery)
            ->selectRaw("
                SUM(CASE WHEN adm.fee_status='Paid' THEN 1 ELSE 0 END) as fee_paid,
                SUM(CASE WHEN adm.fee_status='Partial' THEN 1 ELSE 0 END) as fee_partial,
                SUM(CASE WHEN adm.fee_status='Pending' OR adm.fee_status IS NULL THEN 1 ELSE 0 END) as fee_pending
            ")->first();

        $summary = [
            'total_students'    => $totalStudents,
            'total_required'    => (float) $totalRequired,
            'total_collected'   => (float) $totalCollected,
            'total_outstanding' => max(0, (float) $totalRequired - (float) $totalCollected),
            'fee_paid'          => $statusBreakdown->fee_paid ?? 0,
            'fee_partial'       => $statusBreakdown->fee_partial ?? 0,
            'fee_pending'       => $statusBreakdown->fee_pending ?? 0,
        ];

        $byFeeType = DB::table('fee_receipts as fr')
            ->join('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->where('adm.academic_year', $session)
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId))
            ->selectRaw("
                fr.receipt_type as fee_type,
                SUM(CASE WHEN fr.is_verified THEN fr.net_amount ELSE 0 END) as collected,
                SUM(CASE WHEN NOT fr.is_verified THEN fr.net_amount ELSE 0 END) as pending,
                COUNT(*) as count
            ")
            ->groupBy('fr.receipt_type')
            ->orderByDesc('collected')
            ->get();

        $byClass = DB::table('admissions as adm')
            ->join('programs as p', 'p.id', '=', 'adm.program_id')
            ->leftJoin('fee_structures as fs', function ($j) {
                $j->on('fs.program_id', '=', 'adm.program_id')
                  ->on('fs.semester_no', '=', 'adm.semester_no')
                  ->whereColumn('fs.academic_year', 'adm.academic_year')
                  ->where('fs.is_active', true);
            })
            ->leftJoin('fee_receipts as fr', function ($j) {
                $j->on('fr.admission_id', '=', 'adm.id')->where('fr.is_verified', true);
            })
            ->where('adm.academic_year', $session)
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId))
            ->selectRaw("
                p.short_name as class_name,
                COALESCE(SUM(DISTINCT fs.amount),0) as required,
                COALESCE(SUM(fr.net_amount),0) as collected
            ")
            ->groupBy('adm.program_id', 'p.short_name')
            ->orderBy('p.short_name')
            ->get()
            ->map(function ($r) {
                $r->outstanding = max(0, (float) $r->required - (float) $r->collected);
                return $r;
            });

        $bySemester = DB::table('admissions as adm')
            ->leftJoin('fee_structures as fs', function ($j) {
                $j->on('fs.program_id', '=', 'adm.program_id')
                  ->on('fs.semester_no', '=', 'adm.semester_no')
                  ->whereColumn('fs.academic_year', 'adm.academic_year')
                  ->where('fs.is_active', true);
            })
            ->leftJoin('fee_receipts as fr', function ($j) {
                $j->on('fr.admission_id', '=', 'adm.id')->where('fr.is_verified', true);
            })
            ->where('adm.academic_year', $session)
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId))
            ->selectRaw("
                adm.semester_no,
                COUNT(DISTINCT adm.id) as students,
                COALESCE(SUM(DISTINCT fs.amount),0) as required,
                COALESCE(SUM(fr.net_amount),0) as collected
            ")
            ->groupBy('adm.semester_no')
            ->orderBy('adm.semester_no')
            ->get();

        $latestReg = $this->latestRegistrationSub();
        $recentReceipts = DB::table('fee_receipts as fr')
            ->join('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->join('students as s', 's.id', '=', 'adm.student_id')
            ->join('programs as p', 'p.id', '=', 'adm.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->where('adm.academic_year', $session)
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId))
            ->where('fr.is_verified', true)
            ->select(
                'fr.id', 'fr.receipt_type as fee_type', 'fr.net_amount as amount', 'fr.transaction_id as utr_no', 'fr.created_at',
                's.first_name', 's.middle_name', 's.last_name', 'dr.name as reg_name',
                'p.short_name as class_name', 'adm.semester_no',
                DB::raw('(SELECT name FROM users WHERE id=fr.generated_by LIMIT 1) as issued_by')
            )
            ->orderByDesc('fr.created_at')
            ->limit(20)
            ->get()
            ->map(function ($r) {
                $r->student_name = $this->composeName($r->reg_name, $r->first_name, $r->middle_name, $r->last_name);
                return $r;
            });

        return response()->json([
            'summary'         => $summary,
            'by_fee_type'     => $byFeeType,
            'by_class'        => $byClass,
            'by_semester'     => $bySemester,
            'recent_receipts' => $recentReceipts,
            'classes'         => DB::table('programs')->where('is_active', true)->select('id', 'short_name as name')->orderBy('short_name')->get(),
        ]);
    }
}
