<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesStudentIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * REBUILT against the real schema. The original file joined `classes` and
 * `registrations` (neither exists — the real registration table is
 * `direct_registrations`) and `admission_documents` (doesn't exist — real
 * table is `student_application_documents`), and treated `admissions` as if
 * applicants sat in it in a "Pending" state waiting for approval.
 *
 * That last part is now structurally wrong on purpose: per the business
 * rule implemented in ApplicationController::updateStatus(), an `admissions`
 * row is only ever created AT approval time (after the education fee is
 * paid). So "admission verification" / "semester approval" here operate on
 * the real pending queue — student_applications with status
 * submitted/under_review — and the actual approve/reject/admissions-
 * creation/student-confirmation logic is delegated to
 * ApplicationController::updateStatus() rather than duplicated, so there is
 * one source of truth for what "approved" means.
 *
 * Fee-receipt verification here delegates to FeesController for the same
 * reason — it already queries fee_receipts correctly.
 *
 * amendment_logs was designed but never migrated (see
 * 2026_06_18_130439_create_amendment_table.php — commented out). Applied via
 * 2026_07_20_100000_create_amendment_logs_table.php so misc-activity and
 * block/unblock have a real audit trail to write to.
 */
class AuthorizationController extends Controller
{
    use ResolvesStudentIdentity;

    // ─────────────────────────────────────────────────────────────────────────
    // SHARED: base application queue (student_applications, not admissions —
    // see class docblock for why).
    // ─────────────────────────────────────────────────────────────────────────
    private function baseApplicationQueue(Request $request, array $types)
    {
        $latestReg = $this->latestRegistrationSub();

        $q = DB::table('student_applications as sa')
            ->join('students as s', 's.id', '=', 'sa.student_id')
            ->join('programs as p', 'p.id', '=', 'sa.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->leftJoin('admissions as a', 'a.application_id', '=', 'sa.id')
            ->leftJoin('fee_receipts as fr', 'fr.id', '=', 'sa.fee_receipt_id')
            ->whereIn('sa.application_type', $types)
            ->whereNull('sa.deleted_at')
            ->select(
                'sa.id as application_id',
                'sa.student_id',
                'sa.application_no',
                'sa.academic_year as session',
                'sa.semester_no',
                'sa.status',
                'sa.fee_paid',
                'sa.remarks',
                'sa.created_at',
                's.first_name', 's.middle_name', 's.last_name',
                'dr.name as reg_name', 'dr.father_name', 'dr.mother_name', 'dr.dob',
                's.gender', 's.category', 's.mobile', 's.aadhar_no', 's.is_blocked',
                'p.short_name as class_name', 'p.full_name as program_name',
                'a.id as admission_id', 'a.admission_no', 'a.status as admission_status',
                DB::raw('COALESCE(fr.net_amount, 0) as paid_fee'),
                DB::raw("COALESCE(fr.transaction_id, '') as utr_no"),
                DB::raw("CASE WHEN sa.fee_paid THEN 'Paid' ELSE 'Pending' END as fee_status")
            );

        if ($s = $request->input('session'))    $q->where('sa.academic_year', $s);
        if ($cid = $request->input('class_id')) $q->where('sa.program_id', $cid);
        if ($sem = $request->input('semester')) $q->where('sa.semester_no', $sem);
        if ($from = $request->input('date_from')) $q->whereDate('sa.created_at', '>=', $from);
        if ($to = $request->input('date_to'))     $q->whereDate('sa.created_at', '<=', $to);

        // Frontend sends Pending/Approved/Rejected — map onto the real status enum.
        if ($status = $request->input('status')) {
            $map = ['Pending' => ['submitted', 'under_review'], 'Approved' => ['approved'], 'Rejected' => ['rejected']];
            $q->whereIn('sa.status', $map[$status] ?? [$status]);
        } else {
            // Default: only the actionable queue (not drafts, not already decided).
            $q->whereIn('sa.status', ['submitted', 'under_review']);
        }

        if ($search = $request->input('search')) {
            $q->where(function ($qb) use ($search) {
                $qb->where('sa.application_no', 'ilike', "%$search%")
                   ->orWhere('a.admission_no', 'ilike', "%$search%")
                   ->orWhere('s.mobile', 'ilike', "%$search%")
                   ->orWhere('dr.name', 'ilike', "%$search%")
                   ->orWhere('s.first_name', 'ilike', "%$search%")
                   ->orWhere('s.last_name', 'ilike', "%$search%");
            });
        }

        return $q;
    }

    private function decorate($row)
    {
        $row->name = !empty($row->reg_name) ? $row->reg_name
            : trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name])));
        return $row;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. ADMISSION VERIFICATION — fresh / lateral applications
    // GET /authorizations/admission-verification
    // ─────────────────────────────────────────────────────────────────────────
    public function admissionVerificationIndex(Request $request)
    {
        $q = $this->baseApplicationQueue($request, ['fresh', 'lateral']);

        $counts = $this->baseApplicationQueue($request, ['fresh', 'lateral'])
            ->reorder()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN sa.status IN ('submitted','under_review') THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN sa.status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN sa.status = 'rejected' THEN 1 ELSE 0 END) as rejected
            ")->first();

        $records = $q->orderByDesc('sa.created_at')
            ->paginate($request->input('per_page', 20));
        $records->getCollection()->transform(fn ($r) => $this->decorate($r));

        return response()->json([
            'stats'   => $counts,
            'records' => $records,
        ]);
    }

    // GET /authorizations/admission-verification/{applicationId}
    public function admissionVerificationShow(int $applicationId)
    {
        $rows = $this->baseApplicationQueue(new Request(['status' => null]), ['fresh', 'lateral', 'semester_upgrade', 'back_paper'])
            ->reorder()
            ->where('sa.id', $applicationId)
            ->get();

        $rec = $rows->first();
        if (!$rec) return response()->json(['message' => 'Application not found.'], 404);

        $rec = $this->decorate($rec);
        $rec->documents = DB::table('student_application_documents')
            ->where('application_id', $applicationId)
            ->get(['document_type', 'filename', 'path', 'status', 'created_at']);

        return response()->json($rec);
    }

    // POST /authorizations/admission-verification/{applicationId}/action
    public function admissionVerificationAction(Request $request, int $applicationId)
    {
        return $this->handleAction($request, $applicationId);
    }

    /** Shared approve/reject/rollback handler for both queues below. */
    private function handleAction(Request $request, int $applicationId)
    {
        $request->validate([
            'action'  => 'required|in:Approved,Rejected,RollBack',
            'remarks' => 'nullable|string',
        ]);

        $action = $request->input('action');

        if ($action === 'Approved') {
            // Delegate — this is the ONE place fee-gate + admissions-row
            // creation + student confirmation happens. Don't duplicate it.
            $approveReq = Request::create('', 'PATCH', [
                'status' => 'approved',
                'reason' => $request->input('remarks'),
            ]);
            $approveReq->setUserResolver($request->getUserResolver());
            $response = app(ApplicationController::class)->updateStatus($approveReq, $applicationId);

            if ($response->getStatusCode() >= 400) {
                return $response; // e.g. "Education fee must be paid before approval"
            }
        } else {
            $app = DB::table('student_applications')->where('id', $applicationId)->whereNull('deleted_at')->first();
            if (!$app) return response()->json(['message' => 'Application not found.'], 404);

            $newStatus = $action === 'Rejected' ? 'rejected' : ($app->fee_paid ? 'under_review' : 'submitted');

            DB::table('student_applications')->where('id', $applicationId)->update([
                'status'      => $newStatus,
                'remarks'     => $request->input('remarks') ?? $app->remarks,
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => now(),
                'updated_at'  => now(),
            ]);
        }

        DB::table('authorization_logs')->insert([
            'admission_id' => DB::table('admissions')->where('application_id', $applicationId)->value('id'),
            'action'       => $action,
            'action_type'  => 'AdmissionVerification',
            'reference_id' => $applicationId,
            'performed_by' => $request->user()?->id,
            'remarks'      => $request->input('remarks'),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return response()->json(['message' => "Application {$action} successfully."]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. SEMESTER (UPGRADE) APPROVAL
    // GET /authorizations/semester-approval
    // ─────────────────────────────────────────────────────────────────────────
    public function semesterApprovalIndex(Request $request)
    {
        $q = $this->baseApplicationQueue($request, ['semester_upgrade']);

        $counts = $this->baseApplicationQueue($request, ['semester_upgrade'])
            ->reorder()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN sa.status IN ('submitted','under_review') THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN sa.status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN sa.status = 'rejected' THEN 1 ELSE 0 END) as rejected
            ")->first();

        $records = $q->orderByDesc('sa.created_at')->paginate($request->input('per_page', 20));
        $records->getCollection()->transform(fn ($r) => $this->decorate($r));

        return response()->json(['stats' => $counts, 'records' => $records]);
    }

    // POST /authorizations/semester-approval/{applicationId}/action
    public function semesterApprovalAction(Request $request, int $applicationId)
    {
        return $this->handleAction($request, $applicationId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. FEE RECEIPT VERIFICATION — delegates to FeesController, which
    //    already queries the real fee_receipts columns correctly.
    // ─────────────────────────────────────────────────────────────────────────
    public function feeReceiptIndex(Request $request)
    {
        return app(FeesController::class)->receiptsIndex($request);
    }

    // POST /authorizations/fee-receipt/{id}/verify
    public function feeReceiptVerify(Request $request, int $id)
    {
        $request->validate(['action' => 'required|in:Verified,Rejected']);
        $act = $request->input('action') === 'Verified' ? 'verify' : 'reject';
        return app(FeesController::class)->verifyAction($request, $id, $act);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. MISC. ACTIVITY VERIFICATION (Amendment Log Approvals)
    // GET /authorizations/misc-activity
    // ─────────────────────────────────────────────────────────────────────────
    public function miscActivityIndex(Request $request)
    {
        $latestReg = $this->latestRegistrationSub();

        $q = DB::table('amendment_logs as al')
            ->join('students as s', 's.id', '=', 'al.student_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->leftJoin('admissions as a', 'a.id', '=', 'al.admission_id')
            ->leftJoin('programs as p', 'p.id', '=', 'a.program_id')
            ->select(
                'al.id', 'al.ref_no', 'al.action_type', 'al.status',
                'al.changed_data', 'al.modified_by', 'al.created_at', 'al.student_id',
                's.first_name', 's.middle_name', 's.last_name',
                'dr.name as reg_name', 'dr.father_name', 's.mobile',
                DB::raw("COALESCE(a.admission_no, '') as admission_no"),
                DB::raw("COALESCE(a.semester_no::text, '') as semester_no"),
                DB::raw("COALESCE(p.short_name, '') as class_name")
            );

        if ($status = $request->input('status'))   $q->where('al.status', $status);
        if ($type   = $request->input('activity')) $q->where('al.action_type', $type);
        if ($from   = $request->input('date_from')) $q->whereDate('al.created_at', '>=', $from);
        if ($to     = $request->input('date_to'))   $q->whereDate('al.created_at', '<=', $to);
        if ($search = $request->input('search')) {
            $q->where(function ($qb) use ($search) {
                $qb->where('al.ref_no', 'ilike', "%$search%")
                   ->orWhere('dr.name', 'ilike', "%$search%")
                   ->orWhere('s.first_name', 'ilike', "%$search%")
                   ->orWhere('a.admission_no', 'ilike', "%$search%");
            });
        }

        $total   = (clone $q)->count();
        $pending = (clone $q)->where('al.status', 'Pending')->count();

        $records = $q->orderByDesc('al.created_at')->paginate($request->input('per_page', 20));
        $records->getCollection()->transform(fn ($r) => $this->decorate($r));

        return response()->json([
            'stats'   => compact('total', 'pending'),
            'records' => $records,
        ]);
    }

    // POST /authorizations/misc-activity/{id}/action
    public function miscActivityAction(Request $request, int $id)
    {
        $request->validate(['action' => 'required|in:Approved,Rejected,RollBack']);

        $newStatus = match ($request->input('action')) {
            'Approved' => 'Approved',
            'Rejected' => 'Rejected',
            'RollBack' => 'Pending',
        };

        DB::table('amendment_logs')->where('id', $id)->update([
            'status'      => $newStatus,
            'approved_by' => (string) $request->user()?->id,
            'approved_at' => now(),
            'updated_at'  => now(),
        ]);

        if ($newStatus === 'Approved') {
            $log = DB::table('amendment_logs')->find($id);
            $this->applyAmendmentLog($log);
        }

        DB::table('authorization_logs')->insert([
            'action'       => $request->input('action'),
            'action_type'  => 'MiscActivityVerification',
            'reference_id' => $id,
            'performed_by' => $request->user()?->id,
            'remarks'      => $request->input('remarks'),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return response()->json(['message' => "Activity {$request->input('action')} successfully."]);
    }

    // Apply an approved amendment to the actual tables.
    private function applyAmendmentLog(object $log): void
    {
        $data = json_decode($log->changed_data, true);
        if (!$data || !$log->student_id) return;

        switch ($log->action_type) {
            case 'ModifyData':
                // Only ever touch real students columns — never blindly mass-assign.
                $allowed = ['religion', 'nationality', 'bank_name', 'bank_branch', 'bank_ifsc', 'bank_account_no',
                    'permanent_address', 'permanent_city', 'permanent_district', 'permanent_state', 'permanent_pin',
                    'correspondence_address', 'correspondence_city', 'correspondence_district', 'correspondence_state', 'correspondence_pin'];
                DB::table('students')->where('id', $log->student_id)
                    ->update(array_intersect_key($data, array_flip($allowed)));
                break;

            case 'SubjectChange':
                // Selected subjects live on student_applications, not admissions.
                if ($log->admission_id && isset($data['selected_subjects'])) {
                    $appId = DB::table('admissions')->where('id', $log->admission_id)->value('application_id');
                    if ($appId) {
                        DB::table('student_applications')->where('id', $appId)
                            ->update(['selected_subjects' => json_encode($data['selected_subjects']), 'updated_at' => now()]);
                    }
                }
                break;

            case 'MobileUpdate':
                if (isset($data['new_mobile'])) {
                    DB::table('students')->where('id', $log->student_id)->update(['mobile' => $data['new_mobile']]);
                }
                break;

            case 'BlockUnblock':
                if (isset($data['action'])) {
                    DB::table('students')->where('id', $log->student_id)
                        ->update([
                            'is_blocked'   => $data['action'] === 'block',
                            'block_reason' => $data['reason'] ?? null,
                        ]);
                }
                break;

            case 'AdmissionCancel':
                if ($log->admission_id) {
                    DB::table('admissions')->where('id', $log->admission_id)->update([
                        'status'       => 'cancelled',
                        'cancel_date'  => now()->toDateString(),
                        'cancel_reason'=> $data['reason'] ?? null,
                    ]);
                }
                break;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. BLOCK / UNBLOCK STUDENT
    // GET /authorizations/block-unblock?query=...
    // ─────────────────────────────────────────────────────────────────────────
    public function blockUnblockSearch(Request $request)
    {
        $search  = $request->input('query', '');
        $session = $request->input('session');
        $latestReg = $this->latestRegistrationSub();

        $q = DB::table('students as s')
            ->leftJoin('admissions as a', function ($j) {
                $j->on('a.student_id', '=', 's.id')->where('a.status', 'active');
            })
            ->leftJoin('programs as p', 'p.id', '=', 'a.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->select(
                'a.id as admission_id', 's.id as student_id', 'a.admission_no',
                'a.academic_year as session', 'a.semester_no',
                's.first_name', 's.middle_name', 's.last_name',
                'dr.name as reg_name', 'dr.father_name', 'dr.mother_name',
                's.gender', 's.category', 's.mobile', 's.aadhar_no', 's.is_blocked', 's.block_reason',
                'p.short_name as class_name'
            )
            ->where(function ($qb) use ($search) {
                $qb->where('a.admission_no', 'ilike', "%$search%")
                   ->orWhere('s.mobile', 'ilike', "%$search%")
                   ->orWhere('s.aadhar_no', 'ilike', "%$search%")
                   ->orWhere('s.abc_id', 'ilike', "%$search%")
                   ->orWhere('dr.name', 'ilike', "%$search%");
            });

        if ($session) $q->where('a.academic_year', $session);

        $result = $q->orderByDesc('a.created_at')->first();
        if ($result) $result = $this->decorate($result);

        return response()->json($result ? ['data' => $result] : ['data' => null, 'message' => 'Not found']);
    }

    // POST /authorizations/block-unblock
    public function blockUnblockAction(Request $request)
    {
        $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'action'     => 'required|in:block,unblock',
            'reason'     => 'nullable|string',
        ]);

        DB::table('students')->where('id', $request->input('student_id'))
            ->update([
                'is_blocked'   => $request->input('action') === 'block',
                'block_reason' => $request->input('action') === 'block' ? $request->input('reason') : null,
                'updated_at'   => now(),
            ]);

        DB::table('amendment_logs')->insert([
            'student_id'   => $request->input('student_id'),
            'action_type'  => 'BlockUnblock',
            'changed_data' => json_encode(['action' => $request->input('action'), 'reason' => $request->input('reason')]),
            'modified_by'  => (string) $request->user()?->id,
            'status'       => 'Completed',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $msg = $request->input('action') === 'block' ? 'Student blocked.' : 'Student unblocked.';
        return response()->json(['message' => $msg]);
    }
}
