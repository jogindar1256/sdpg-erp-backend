<?php

namespace App\Http\Controllers\Api;

use App\Models\Student;
use App\Http\Concerns\ResolvesStudentIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthorizationController extends Controller
{
    use ResolvesStudentIdentity;

    // ─────────────────────────────────────────────────────────────────────────
    // SHARED: base application queue (student_applications, not admissions —
    // ─────────────────────────────────────────────────────────────────────────
    private function baseApplicationQueue(Request $request, array $types)
    {
        $latestReg = $this->latestRegistrationSub();

        // students is a LEFT join — was inner, which silently dropped every
        // 'regular' application from this queue, since a regular
        // application's student_id is null until admission approval (see
        // ApplicationController::createStudentFromApprovedApplication()).
        // That's the same bug fixed in ApplicationController::index() for
        // /college/applications — this is the admission-verification queue,
        // which explicitly includes 'regular' (see admissionVerificationIndex()
        // below), so nothing regular could have been showing up here at all.
        // drc (direct_registrations via sa.direct_registration_id) is the
        // fallback identity source for exactly that case, same as index().
        $q = DB::table('student_applications as sa')
            ->leftJoin('students as s', 's.id', '=', 'sa.student_id')
            ->join('programs as p', 'p.id', '=', 'sa.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->leftJoin('direct_registrations as drc', 'drc.id', 'sa.direct_registration_id')
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
                'sa.created_at',
                's.personal_info->first_name as first_name',
                's.personal_info->middle_name as middle_name',
                's.personal_info->last_name as last_name',
                DB::raw('COALESCE(dr.name, drc.name) as reg_name'),
                DB::raw('COALESCE(dr.father_name, drc.father_name) as father_name'),
                DB::raw('COALESCE(dr.mother_name, drc.mother_name) as mother_name'),
                DB::raw('COALESCE(dr.dob, drc.dob) as dob'),
                DB::raw("COALESCE(s.personal_info->>'gender', drc.gender) as gender"),
                DB::raw("COALESCE(s.personal_info->>'category', drc.category) as category"),
                DB::raw('COALESCE(s.mobile, drc.mobile) as mobile'),
                DB::raw('COALESCE(s.aadhar_no, drc.aadhar_no) as aadhar_no'),
                's.is_blocked',
                'p.short_name as class_name',
                'p.full_name as program_name',
                'a.id as admission_id',
                'a.admission_no',
                'a.status as admission_status',
                DB::raw('COALESCE(fr.net_amount, 0) as paid_fee'),
                DB::raw("COALESCE(fr.transaction_id, '') as utr_no"),
                DB::raw("CASE WHEN sa.fee_paid THEN 'Paid' ELSE 'Pending' END as fee_status")
            );

        if ($s = $request->input('session'))
            $q->where('sa.academic_year', $s);
        if ($cid = $request->input('class_id'))
            $q->where('sa.program_id', $cid);
        if ($sem = $request->input('semester'))
            $q->where('sa.semester_no', $sem);
        if ($from = $request->input('date_from'))
            $q->whereDate('sa.created_at', '>=', $from);
        if ($to = $request->input('date_to'))
            $q->whereDate('sa.created_at', '<=', $to);

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
                // Added drc.name/drc.mobile — was dr/s only, which a fresh
                // application (no students row, no dr match via user_id)
                // could never match at all.
                $qb->where('sa.application_no', 'ilike', "%$search%")
                    ->orWhere('a.admission_no', 'ilike', "%$search%")
                    ->orWhere('s.mobile', 'ilike', "%$search%")
                    ->orWhere('drc.mobile', 'ilike', "%$search%")
                    ->orWhere('dr.name', 'ilike', "%$search%")
                    ->orWhere('drc.name', 'ilike', "%$search%")
                    ->orWhere('s.personal_info->first_name', 'ilike', "%$search%")
                    ->orWhere('s.personal_info->last_name', 'ilike', "%$search%");
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
    // 1. ADMISSION VERIFICATION — regular applications
    // GET /authorizations/admission-verification
    // ─────────────────────────────────────────────────────────────────────────
    public function admissionVerificationIndex(Request $request)
    {
        // No status filter on this queue any more — it is always the
        // pending list. (Semester Approval still filters by status.)
        $request->request->remove('status');
        $request->query->remove('status');

        $q = $this->baseApplicationQueue($request, ['regular']);

        $counts = $this->baseApplicationQueue($request, ['regular'])
            ->reorder()
            ->select(DB::raw("
                COUNT(*) as total,
                SUM(CASE WHEN sa.status IN ('submitted','under_review') THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN sa.status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN sa.status = 'rejected' THEN 1 ELSE 0 END) as rejected
            "))->first();

        $records = $q->orderByDesc('sa.created_at')
            ->paginate($request->input('per_page', 20));
        $records->getCollection()->transform(fn($r) => $this->decorate($r));

        return response()->json([
            'stats' => $counts,
            'records' => $records,
        ]);
    }

    /**
     * Admissions waiting for approval on the verification page: odd
     * semesters only (new admissions and year upgrades) — even-semester
     * upgrades are handled by Semester Approval below.
     */
    private function pendingAdmissionQueue(Request $request)
    {
        $q = DB::table('admissions as a')
            ->whereNull('a.deleted_at')
            ->where('a.status', 'pending')
            ->whereRaw('a.semester_no % 2 = 1');

        if ($v = $request->input('session'))
            $q->where('a.academic_year', $v);
        if ($v = $request->input('class_id') ?? $request->input('course_id'))
            $q->where('a.program_id', $v);
        if ($v = $request->input('semester'))
            $q->where('a.semester_no', $v);
        if ($v = $request->input('date_from'))
            $q->whereDate('a.created_at', '>=', $v);
        if ($v = $request->input('date_to'))
            $q->whereDate('a.created_at', '<=', $v);

        return $q;
    }

    // GET /authorizations/admission-verification/next
    // The one admission to review now: oldest pending first.
    public function admissionVerificationNext(Request $request)
    {
        $pending = $this->pendingAdmissionQueue($request)->count();
        $admission = $this->pendingAdmissionQueue($request)
            ->orderBy('a.updated_at')
            ->orderBy('a.id')
            ->select('a.*')
            ->first();

        return response()->json([
            'pending_count' => $pending,
            'record' => $admission ? app(ApplicationController::class)->verificationCardData($admission) : null,
        ]);
    }

    // GET /authorizations/admission-verification/fetch?q=
    // Pull up one specific record, whatever its status, by admission no /
    // application no / registration no / mobile.
    public function admissionVerificationFetch(Request $request)
    {
        $term = trim((string) $request->input('q', ''));
        if ($term === '') {
            return response()->json(['message' => 'Enter an Application No, Admission No, Registration No or Mobile No.'], 422);
        }
        $lower = mb_strtolower($term);

        $admission = DB::table('admissions as a')
            ->join('student_applications as sa', 'sa.id', '=', 'a.application_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', '=', 'a.direct_registration_id')
            ->whereNull('a.deleted_at')
            ->whereNull('sa.deleted_at')
            ->where(function ($w) use ($lower) {
                $w->whereRaw('LOWER(a.admission_no) = ?', [$lower])
                    ->orWhereRaw('LOWER(sa.application_no) = ?', [$lower])
                    ->orWhereRaw('LOWER(dr.registration_no) = ?', [$lower])
                    ->orWhereRaw("a.applicant_info->>'mobile' = ?", [$lower])
                    ->orWhereRaw('dr.mobile = ?', [$lower]);
            })
            ->orderByRaw("CASE WHEN a.status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('a.id')
            ->select('a.*')
            ->first();

        if (!$admission) {
            return response()->json(['message' => "No submitted application found for \"{$term}\"."], 404);
        }

        return response()->json([
            'record' => app(ApplicationController::class)->verificationCardData($admission),
        ]);
    }

    // GET /authorizations/admission-verification/{applicationId}
    public function admissionVerificationShow(int $applicationId)
    {
        $rows = $this->baseApplicationQueue(new Request(['status' => null]), ['regular', 'semester_upgrade', 'back_paper'])
            ->reorder()
            ->where('sa.id', $applicationId)
            ->get();

        $rec = $rows->first();
        if (!$rec)
            return response()->json(['message' => 'Application not found.'], 404);

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
            'action' => 'required|in:Approved,Rejected,RollBack',
            'remarks' => 'nullable|string',
            'documents_checked' => 'nullable|boolean',
        ]);

        $action = $request->input('action');

        // The verification card sends documents_checked; approving without
        // ticking it is refused. Callers that don't send it are unaffected.
        if ($action === 'Approved' && $request->has('documents_checked') && !$request->boolean('documents_checked')) {
            return response()->json(['message' => 'Confirm that all attached documents have been checked before approving.'], 422);
        }
        if (in_array($action, ['Rejected', 'RollBack'], true) && $request->has('documents_checked') && trim((string) $request->input('remarks')) === '') {
            return response()->json(['message' => 'Enter the reason for roll back or reject.'], 422);
        }

        if ($action === 'Approved') {
            // Delegate to the one place approval happens — student_applications
            // only (status/approved_by/approved_at), no rejected_applications row.
            $response = app(ApplicationController::class)->approve($request, $applicationId);

            if ($response->getStatusCode() >= 400) {
                return $response; // e.g. "already approved and fee paid" lock
            }
        } elseif ($action === 'Rejected') {
            // Delegate to the shared reject/hold pipeline — writes the
            // decision detail to rejected_applications, flips
            // student_applications.status only.
            $rejectReq = Request::create('', 'POST', [
                'decision' => 'reject',
                'reason'   => $request->input('remarks') ?: 'Rejected via admission verification queue.',
            ]);
            $rejectReq->setUserResolver($request->getUserResolver());
            $response = app(ApplicationController::class)->rejectOrHold($rejectReq, $applicationId);

            if ($response->getStatusCode() >= 400) {
                return $response;
            }
        } else {
            // RollBack — reopen the queue item. This isn't a hold/reject
            // decision (no rejected_applications row), just a status revert,
            // so it never touches that table.
            $app = DB::table('student_applications')->where('id', $applicationId)->whereNull('deleted_at')->first();
            if (!$app)
                return response()->json(['message' => 'Application not found.'], 404);

            if ($app->status === 'approved' && $app->fee_paid) {
                return response()->json([
                    'message' => 'This application is already approved and the fee is paid — it cannot be moved back. Only Cancel or Hold is allowed.',
                ], 422);
            }

            $newStatus = $app->fee_paid ? 'under_review' : 'submitted';

            DB::table('student_applications')->where('id', $applicationId)->update([
                'status' => $newStatus,
                'updated_at' => now(),
            ]);
            // Back to pending — and, since updated_at moves, to the end of the queue.
            app(ApplicationController::class)->syncAdmissionForApplication($applicationId);
        }

        if ($request->has('documents_checked')) {
            DB::table('admissions')->where('application_id', $applicationId)->update([
                'documents_verified' => $action === 'Approved',
            ]);
        }

        DB::table('authorization_logs')->insert([
            'admission_id' => DB::table('admissions')->where('application_id', $applicationId)->value('id'),
            'action' => $action,
            'action_type' => 'AdmissionVerification',
            'reference_id' => $applicationId,
            'performed_by' => $request->user()?->id,
            'remarks' => $request->input('remarks'),
            'created_at' => now(),
            'updated_at' => now(),
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
            ->select(DB::raw("
                COUNT(*) as total,
                SUM(CASE WHEN sa.status IN ('submitted','under_review') THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN sa.status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN sa.status = 'rejected' THEN 1 ELSE 0 END) as rejected
            "))->first();

        $records = $q->orderByDesc('sa.created_at')->paginate($request->input('per_page', 20));
        $records->getCollection()->transform(fn($r) => $this->decorate($r));

        return response()->json(['stats' => $counts, 'records' => $records]);
    }

    // POST /authorizations/semester-approval/{applicationId}/action
    public function semesterApprovalAction(Request $request, int $applicationId)
    {
        return $this->handleAction($request, $applicationId);
    }

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
                'al.id',
                'al.ref_no',
                'al.action_type',
                'al.status',
                'al.changed_data',
                'al.modified_by',
                'al.created_at',
                'al.student_id',
                's.personal_info->first_name as first_name',
                's.personal_info->middle_name as middle_name',
                's.personal_info->last_name as last_name',
                'dr.name as reg_name',
                'dr.father_name',
                's.mobile',
                DB::raw("COALESCE(a.admission_no, '') as admission_no"),
                DB::raw("COALESCE(a.semester_no::text, '') as semester_no"),
                DB::raw("COALESCE(p.short_name, '') as class_name")
            );

        if ($status = $request->input('status'))
            $q->where('al.status', $status);
        if ($type = $request->input('activity'))
            $q->where('al.action_type', $type);
        if ($from = $request->input('date_from'))
            $q->whereDate('al.created_at', '>=', $from);
        if ($to = $request->input('date_to'))
            $q->whereDate('al.created_at', '<=', $to);
        if ($search = $request->input('search')) {
            $q->where(function ($qb) use ($search) {
                $qb->where('al.ref_no', 'ilike', "%$search%")
                    ->orWhere('dr.name', 'ilike', "%$search%")
                    ->orWhere('s.personal_info->first_name', 'ilike', "%$search%")
                    ->orWhere('a.admission_no', 'ilike', "%$search%");
            });
        }

        $total = (clone $q)->count();
        $pending = (clone $q)->where('al.status', 'Pending')->count();

        $records = $q->orderByDesc('al.created_at')->paginate($request->input('per_page', 20));
        $records->getCollection()->transform(fn($r) => $this->decorate($r));

        return response()->json([
            'stats' => compact('total', 'pending'),
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
            'status' => $newStatus,
            'approved_by' => (string) $request->user()?->id,
            'approved_at' => now(),
            'updated_at' => now(),
        ]);

        if ($newStatus === 'Approved') {
            $log = DB::table('amendment_logs')->find($id);
            $this->applyAmendmentLog($log);
        }

        DB::table('authorization_logs')->insert([
            'action' => $request->input('action'),
            'action_type' => 'MiscActivityVerification',
            'reference_id' => $id,
            'performed_by' => $request->user()?->id,
            'remarks' => $request->input('remarks'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => "Activity {$request->input('action')} successfully."]);
    }

    // Apply an approved amendment to the actual tables.
    private function applyAmendmentLog(object $log): void
    {
        $data = json_decode($log->changed_data, true);
        if (!$data || !$log->student_id)
            return;

        switch ($log->action_type) {
            case 'ModifyData':
                // Only ever touch real students columns — never blindly mass-assign.
                $allowed = [
                    'religion',
                    'nationality',
                    'bank_name',
                    'bank_branch',
                    'bank_ifsc',
                    'bank_account_no',
                    'permanent_address',
                    'permanent_city',
                    'permanent_district',
                    'permanent_state',
                    'permanent_pin',
                    'correspondence_address',
                    'correspondence_city',
                    'correspondence_district',
                    'correspondence_state',
                    'correspondence_pin'
                ];
                // Every one of these now lives inside a grouped jsonb column.
                Student::groupedUpdate($log->student_id, array_intersect_key($data, array_flip($allowed)));
                break;

            case 'SubjectChange':
                // Selected subjects live on part_6 (decode/encode everywhere)
                // on student_applications, not admissions — the old
                // top-level selected_subjects column was always null for
                if ($log->admission_id && isset($data['selected_subjects'])) {
                    $appId = DB::table('admissions')->where('id', $log->admission_id)->value('application_id');
                    if ($appId) {
                        $existingRaw = DB::table('student_applications')->where('id', $appId)->value('part_6');
                        $existing = is_string($existingRaw) ? (json_decode($existingRaw, true) ?? []) : ($existingRaw ?? []);
                        $existing['selected_subjects'] = $data['selected_subjects'];
                        DB::table('student_applications')->where('id', $appId)
                            ->update(['part_6' => json_encode($existing), 'updated_at' => now()]);
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
                            'is_blocked' => $data['action'] === 'block',
                            'block_reason' => $data['reason'] ?? null,
                        ]);
                }
                break;

            case 'AdmissionCancel':
                if ($log->admission_id) {
                    DB::table('admissions')->where('id', $log->admission_id)->update([
                        'status' => 'cancelled',
                        'cancel_date' => now()->toDateString(),
                        'cancel_reason' => $data['reason'] ?? null,
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
        $search = $request->input('query', '');
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
                'a.id as admission_id',
                's.id as student_id',
                'a.admission_no',
                'a.academic_year as session',
                'a.semester_no',
                's.personal_info->first_name as first_name',
                's.personal_info->middle_name as middle_name',
                's.personal_info->last_name as last_name',
                'dr.name as reg_name',
                'dr.father_name',
                'dr.mother_name',
                's.personal_info->gender as gender',
                's.personal_info->category as category',
                's.mobile',
                's.aadhar_no',
                's.is_blocked',
                's.block_reason',
                'p.short_name as class_name'
            )
            ->where(function ($qb) use ($search) {
                $qb->where('a.admission_no', 'ilike', "%$search%")
                    ->orWhere('s.mobile', 'ilike', "%$search%")
                    ->orWhere('s.aadhar_no', 'ilike', "%$search%")
                    ->orWhere('s.abc_id', 'ilike', "%$search%")
                    ->orWhere('dr.name', 'ilike', "%$search%");
            });

        if ($session)
            $q->where('a.academic_year', $session);

        $result = $q->orderByDesc('a.created_at')->first();
        if ($result)
            $result = $this->decorate($result);

        return response()->json($result ? ['data' => $result] : ['data' => null, 'message' => 'Not found']);
    }

    // POST /authorizations/block-unblock
    public function blockUnblockAction(Request $request)
    {
        $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'action' => 'required|in:block,unblock',
            'reason' => 'nullable|string',
        ]);

        DB::table('students')->where('id', $request->input('student_id'))
            ->update([
                'is_blocked' => $request->input('action') === 'block',
                'block_reason' => $request->input('action') === 'block' ? $request->input('reason') : null,
                'updated_at' => now(),
            ]);

        DB::table('amendment_logs')->insert([
            'student_id' => $request->input('student_id'),
            'action_type' => 'BlockUnblock',
            'changed_data' => json_encode(['action' => $request->input('action'), 'reason' => $request->input('reason')]),
            'modified_by' => (string) $request->user()?->id,
            'status' => 'Completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $msg = $request->input('action') === 'block' ? 'Student blocked.' : 'Student unblocked.';
        return response()->json(['message' => $msg]);
    }
}
