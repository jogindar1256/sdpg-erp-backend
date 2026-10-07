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

    /**
     * Map a student's own gender/category onto fee_structures.amount_json's
     * key spelling. Same intentional mismatches handled in
     * ApplicationController::feeGenderCategory() — students.gender's
     * 'other' -> 'transgender'; students.category's default 'general' ->
     * 'gen'.
     */
    private function feeGenderCategory(?string $gender, ?string $category): array
    {
        $gender = strtolower((string) ($gender ?? 'male'));
        $gender = $gender === 'other' ? 'transgender' : $gender;

        $category = strtolower((string) ($category ?? 'general'));
        $category = $category === 'general' ? 'gen' : $category;

        return [$gender, $category];
    }

    // ─── Shared: base receipt query ──────────────────────────────────────────────
    protected function baseReceiptQuery(Request $request)
    {
        $latestReg = $this->latestRegistrationSub();

        $q = DB::table('fee_receipts as fr')
            // students is a LEFT join: a first-time applicant's receipt is
            // issued at payment, before their students row exists (it is
            // created when this receipt is verified). The applicant is then
            // identified through the admission instead.
            ->leftJoin('students as s', 's.id', '=', 'fr.student_id')
            ->leftJoin('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->leftJoin('programs as p', 'p.id', '=', 'adm.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', '=', DB::raw('COALESCE(s.user_id, adm.user_id)'))
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->select([
                'fr.id', 'fr.receipt_no', 'fr.receipt_type as fee_type', 'fr.net_amount as amount',
                'fr.transaction_id as utr_no', 'fr.bank_ref_no', 'fr.receipt_date as payment_date',
                'fr.is_verified', 'fr.status', 'fr.created_at',
                's.personal_info->first_name as first_name', 's.personal_info->middle_name as middle_name', 's.personal_info->last_name as last_name',
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
                $w->where('s.personal_info->first_name', 'ilike', "%$search%")
                  ->orWhere('s.personal_info->last_name', 'ilike', "%$search%")
                  ->orWhere('dr.name', 'ilike', "%$search%")
                  ->orWhere('adm.applicant_info->name', 'ilike', "%$search%")
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
            'classes' => DB::table('programs')->where('is_active', true)->whereNull('deleted_at')->select('id', 'short_name as name')->orderBy('short_name')->get(),
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
            'classes' => DB::table('programs')->where('is_active', true)->whereNull('deleted_at')->select('id', 'short_name as name')->orderBy('short_name')->get(),
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

            // Verifying the receipt is what turns a first-time applicant
            // into a student (students row + active admission).
            $appId = $receipt->admission_id
                ? DB::table('admissions')->where('id', $receipt->admission_id)->value('application_id')
                : null;
            if ($appId) {
                app(ApplicationController::class)->finalizeAdmissionAfterReceipt((int) $appId, (int) Auth::id());
            }
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
                's.personal_info->first_name as first_name', 's.personal_info->middle_name as middle_name', 's.personal_info->last_name as last_name',
                'dr.name as reg_name', 'dr.father_name', 'dr.mother_name',
                's.mobile', 's.personal_info->gender as gender', 's.personal_info->category as category',
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

        [$feeGender, $feeCategory] = $this->feeGenderCategory($admission->gender, $admission->category);

        $totalRequired = \App\Models\FeeStructure::requiredFeeFor(
            $admission->program_id,
            $admission->session,
            array_unique([0, (int) $admission->semester_no]),
            $admission->admission_type,
            $feeGender,
            $feeCategory
        );

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

        // fee_structures no longer has row-level gender/category to filter
        // in SQL — every particular's full breakdown lives inside one
        // configuration row's amount_json. So: pull every admission's own
        // program/semester/admission-type/gender/category, bulk-fetch the
        // matching amount_json blobs once (requiredFeeBlobMap), and sum each
        // admission's own required fee in PHP.
        $admissionsForFee = DB::table('admissions as adm')
            ->join('students as s', 's.id', '=', 'adm.student_id')
            ->where('adm.academic_year', $session)
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId))
            ->select('adm.id', 'adm.program_id', 'adm.semester_no', 'adm.admission_type', 's.personal_info->gender as gender', 's.personal_info->category as category')
            ->get();

        $feeBlobMap = \App\Models\FeeStructure::requiredFeeBlobMap($session, $progId ?: null);

        $requiredFeeByAdmission = $admissionsForFee->mapWithKeys(function ($a) use ($feeBlobMap) {
            [$g, $c] = $this->feeGenderCategory($a->gender, $a->category);
            // Category is part of the blob-map key now (fee_structures is
            // one row per course+category — see the amount_json migration
            // header), gender picks the key inside each matched blob.
            $key = $a->program_id . '|' . $a->semester_no . '|' . $a->admission_type . '|' . $c;
            $blobs = $feeBlobMap[$key] ?? [];
            return [$a->id => \App\Models\FeeStructure::sumFromGenderBlobs($blobs, $g)];
        });

        $totalRequired = $requiredFeeByAdmission->sum();

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

        // Same reasoning as $totalRequired above: no row-level gender/
        // category to join on any more, so class/semester breakdowns are
        // built from $admissionsForFee's already-computed per-admission
        // required fee (PHP-side), joined with SQL-computed collected sums
        // (fee_receipts isn't affected by the fee_structures redesign, so
        // that half stays a plain SQL aggregate).
        $collectedByProgram = DB::table('fee_receipts as fr')
            ->join('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->where('adm.academic_year', $session)
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId))
            ->where('fr.is_verified', true)
            ->selectRaw('adm.program_id, SUM(fr.net_amount) as collected')
            ->groupBy('adm.program_id')
            ->pluck('collected', 'program_id');

        $collectedBySemester = DB::table('fee_receipts as fr')
            ->join('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->where('adm.academic_year', $session)
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId))
            ->where('fr.is_verified', true)
            ->selectRaw('adm.semester_no, SUM(fr.net_amount) as collected')
            ->groupBy('adm.semester_no')
            ->pluck('collected', 'semester_no');

        $programNames = DB::table('programs')->pluck('short_name', 'id');

        $requiredByProgram = [];
        $requiredBySemester = [];
        $studentsBySemester = [];
        foreach ($admissionsForFee as $a) {
            $fee = $requiredFeeByAdmission[$a->id] ?? 0;
            $requiredByProgram[$a->program_id] = ($requiredByProgram[$a->program_id] ?? 0) + $fee;
            $requiredBySemester[$a->semester_no] = ($requiredBySemester[$a->semester_no] ?? 0) + $fee;
            $studentsBySemester[$a->semester_no] = ($studentsBySemester[$a->semester_no] ?? 0) + 1;
        }

        $byClass = collect($requiredByProgram)
            ->map(function ($required, $programId) use ($programNames, $collectedByProgram) {
                $collected = (float) ($collectedByProgram[$programId] ?? 0);
                return (object) [
                    'class_name'  => $programNames[$programId] ?? "Program #{$programId}",
                    'required'    => (float) $required,
                    'collected'   => $collected,
                    'outstanding' => max(0, (float) $required - $collected),
                ];
            })
            ->sortBy('class_name')
            ->values();

        $bySemester = collect($requiredBySemester)
            ->map(function ($required, $semNo) use ($collectedBySemester, $studentsBySemester) {
                $collected = (float) ($collectedBySemester[$semNo] ?? 0);
                return (object) [
                    'semester_no' => (int) $semNo,
                    'students'    => $studentsBySemester[$semNo] ?? 0,
                    'required'    => (float) $required,
                    'collected'   => $collected,
                ];
            })
            ->sortBy('semester_no')
            ->values();

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
                's.personal_info->first_name as first_name', 's.personal_info->middle_name as middle_name', 's.personal_info->last_name as last_name', 'dr.name as reg_name',
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
            'classes'         => DB::table('programs')->where('is_active', true)->whereNull('deleted_at')->select('id', 'short_name as name')->orderBy('short_name')->get(),
        ]);
    }
}
