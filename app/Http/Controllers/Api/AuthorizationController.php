<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthorizationController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // SHARED: build base student/admission query with common filters
    // ─────────────────────────────────────────────────────────────────────────
    private function baseAdmissionQuery(Request $request)
    {
        $q = DB::table('admissions as a')
            ->join('students as s',      's.id',        '=', 'a.student_id')
            ->join('programs as p',      'p.id',        '=', 'a.program_id')
            ->join('classes as c',       'c.id',        '=', 'a.class_id')
            ->leftJoin('registrations as r', 'r.admission_id', '=', 'a.id')
            ->leftJoin('fee_receipts as fr', function ($join) {
                $join->on('fr.admission_id', '=', 'a.id')
                     ->where('fr.fee_type', '=', 'Admission');
            })
            ->leftJoin('fee_receipts as rr', function ($join) {
                $join->on('rr.admission_id', '=', 'a.id')
                     ->where('rr.fee_type', '=', 'Registration');
            })
            ->select(
                'a.id as admission_id',
                'a.student_id',
                'a.application_no',
                'a.reg_no',
                'a.university_roll_no as uni_roll_no',
                'a.admission_mode',
                'a.session',
                'a.status as adm_status',
                'a.subject_basic',
                'a.subject_drop',
                'a.subject_practical',
                'a.tc_status',
                'a.migration_status',
                'a.fine_amount',
                'a.required_fee',
                'a.documents_verified',
                's.name',
                's.father_name',
                's.mother_name',
                's.dob',
                's.gender',
                's.category',
                's.mobile',
                's.aadhar_no',
                's.state',
                's.is_blocked',
                'p.name as subject_name',
                'c.name as class_name',
                'a.semester_no',
                DB::raw("COALESCE(fr.amount, 0) as adm_paid_fee"),
                DB::raw("COALESCE(fr.utr_no, '') as adm_utr_no"),
                DB::raw("COALESCE(fr.status, 'Pending') as adm_fee_status"),
                DB::raw("COALESCE(rr.amount, 0) as reg_paid_fee"),
                DB::raw("COALESCE(rr.utr_no, '') as reg_utr_no"),
                DB::raw("COALESCE(rr.status, 'Pending') as reg_fee_status"),
                DB::raw("COALESCE(rr.paid_date, null) as reg_fee_paid_date"),
                'r.application_form_no'
            );

        // Session filter
        if ($s = $request->input('session')) {
            $q->where('a.session', $s);
        }
        // Class filter
        if ($cid = $request->input('class_id')) {
            $q->where('a.class_id', $cid);
        }
        // Semester filter
        if ($sem = $request->input('semester')) {
            $q->where('a.semester_no', $sem);
        }
        // Date range
        if ($from = $request->input('date_from')) {
            $q->whereDate('a.created_at', '>=', $from);
        }
        if ($to = $request->input('date_to')) {
            $q->whereDate('a.created_at', '<=', $to);
        }
        // Status filter
        if ($status = $request->input('status')) {
            $q->where('a.status', $status);
        }
        // Search text
        if ($search = $request->input('search')) {
            $q->where(function ($qb) use ($search) {
                $qb->where('a.application_no',     'like', "%$search%")
                   ->orWhere('a.reg_no',            'like', "%$search%")
                   ->orWhere('a.university_roll_no','like', "%$search%")
                   ->orWhere('s.mobile',            'like', "%$search%")
                   ->orWhere('s.name',              'like', "%$search%");
            });
        }

        return $q;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. ADMISSION VERIFICATION — Odd semesters (1,3,5,7)
    // GET /authorizations/admission-verification
    // ─────────────────────────────────────────────────────────────────────────
    public function admissionVerificationIndex(Request $request)
    {
        $q = $this->baseAdmissionQuery($request)
            ->whereIn('a.semester_no', [1, 3, 5, 7]);

        $total   = (clone $q)->count();
        $pending = (clone $q)->where('a.status', 'Pending')->count();
        $approved = (clone $q)->where('a.status', 'Approved')->count();
        $rejected = (clone $q)->where('a.status', 'Rejected')->count();

        $records = $q->orderBy('a.created_at', 'desc')
                     ->paginate($request->input('per_page', 20));

        return response()->json([
            'stats'   => compact('total', 'pending', 'approved', 'rejected'),
            'records' => $records,
        ]);
    }

    // GET /authorizations/admission-verification/{admissionId}
    public function admissionVerificationShow(int $admissionId)
    {
        $rec = DB::table('admissions as a')
            ->join('students as s',  's.id', '=', 'a.student_id')
            ->join('programs as p',  'p.id', '=', 'a.program_id')
            ->join('classes as c',   'c.id', '=', 'a.class_id')
            ->leftJoin('registrations as r', 'r.admission_id', '=', 'a.id')
            ->leftJoin('fee_receipts as fr', 'fr.admission_id', '=', 'a.id')
            ->leftJoin('admission_documents as ad', 'ad.admission_id', '=', 'a.id')
            ->where('a.id', $admissionId)
            ->select('a.*', 's.name', 's.father_name', 's.mother_name', 's.dob',
                     's.gender', 's.category', 's.mobile', 's.aadhar_no',
                     's.state', 'p.name as subject_name', 'c.name as class_name',
                     'r.application_form_no', DB::raw("JSON_AGG(DISTINCT fr.*) as fee_details"),
                     DB::raw("JSON_AGG(DISTINCT ad.*) as documents"))
            ->groupBy('a.id','s.id','p.id','c.id','r.id')
            ->first();

        return response()->json($rec);
    }

    // POST /authorizations/admission-verification/{admissionId}/action
    public function admissionVerificationAction(Request $request, int $admissionId)
    {
        $request->validate([
            'action'      => 'required|in:Approved,Rejected,RollBack',
            'documents_verified' => 'nullable|boolean',
            'remarks'     => 'nullable|string',
        ]);

        $action = $request->input('action');
        $newStatus = match($action) {
            'Approved'  => 'Approved',
            'Rejected'  => 'Rejected',
            'RollBack'  => 'Pending',
        };

        DB::table('admissions')->where('id', $admissionId)->update([
            'status'             => $newStatus,
            'documents_verified' => $request->input('documents_verified', false),
            'approved_by'        => auth()->id(),
            'approved_at'        => now(),
            'updated_at'         => now(),
        ]);

        // Log the action
        DB::table('authorization_logs')->insert([
            'admission_id' => $admissionId,
            'action'       => $action,
            'action_type'  => 'AdmissionVerification',
            'performed_by' => auth()->id(),
            'remarks'      => $request->input('remarks'),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return response()->json(['message' => "Admission $action successfully."]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. SEMESTER REGISTRATION APPROVAL — Even semesters (2,4,6,8)
    // GET /authorizations/semester-approval
    // ─────────────────────────────────────────────────────────────────────────
    public function semesterApprovalIndex(Request $request)
    {
        $q = $this->baseAdmissionQuery($request)
            ->whereIn('a.semester_no', [2, 4, 6, 8]);

        $total    = (clone $q)->count();
        $pending  = (clone $q)->where('a.status', 'Pending')->count();
        $approved = (clone $q)->where('a.status', 'Approved')->count();
        $rejected = (clone $q)->where('a.status', 'Rejected')->count();

        $records = $q->orderBy('a.created_at', 'desc')
                     ->paginate($request->input('per_page', 20));

        return response()->json([
            'stats'   => compact('total', 'pending', 'approved', 'rejected'),
            'records' => $records,
        ]);
    }

    // POST /authorizations/semester-approval/{admissionId}/action — reuse same logic
    public function semesterApprovalAction(Request $request, int $admissionId)
    {
        return $this->admissionVerificationAction($request, $admissionId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. FEE RECEIPT VERIFICATION
    // GET /authorizations/fee-receipt
    // ─────────────────────────────────────────────────────────────────────────
    public function feeReceiptIndex(Request $request)
    {
        $q = DB::table('fee_receipts as fr')
            ->join('admissions as a',  'a.id',  '=', 'fr.admission_id')
            ->join('students as s',    's.id',  '=', 'a.student_id')
            ->join('classes as c',     'c.id',  '=', 'a.class_id')
            ->leftJoin('users as u',   'u.id',  '=', 'fr.issued_by')
            ->select(
                'fr.id',
                'fr.receipt_no',
                'fr.amount',
                'fr.fee_type',
                'fr.status',
                'fr.paid_date',
                'fr.utr_no',
                'fr.issued_at',
                'a.student_id',
                'a.application_no',
                'a.semester_no',
                's.name',
                's.father_name',
                's.category',
                'c.name as class_name',
                'u.name as issued_by_name'
            );

        if ($sess = $request->input('session'))   $q->where('a.session', $sess);
        if ($cid  = $request->input('class_id'))  $q->where('a.class_id', $cid);
        if ($sem  = $request->input('semester'))  $q->where('a.semester_no', $sem);
        if ($from = $request->input('date_from')) $q->whereDate('fr.created_at', '>=', $from);
        if ($to   = $request->input('date_to'))   $q->whereDate('fr.created_at', '<=', $to);

        $records = $q->orderBy('fr.created_at', 'desc')
                     ->paginate($request->input('per_page', 20));

        return response()->json($records);
    }

    // POST /authorizations/fee-receipt/{id}/verify
    public function feeReceiptVerify(Request $request, int $id)
    {
        $request->validate(['action' => 'required|in:Verified,Rejected']);

        DB::table('fee_receipts')->where('id', $id)->update([
            'status'      => $request->input('action') === 'Verified' ? 'Paid' : 'Rejected',
            'verified_by' => auth()->id(),
            'verified_at' => now(),
            'updated_at'  => now(),
        ]);

        DB::table('authorization_logs')->insert([
            'action'       => $request->input('action'),
            'action_type'  => 'FeeReceiptVerification',
            'reference_id' => $id,
            'performed_by' => auth()->id(),
            'remarks'      => $request->input('remarks'),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return response()->json(['message' => "Receipt {$request->input('action')} successfully."]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. MISC. ACTIVITY VERIFICATION (Amendment Log Approvals)
    // GET /authorizations/misc-activity
    // ─────────────────────────────────────────────────────────────────────────
    public function miscActivityIndex(Request $request)
    {
        $q = DB::table('amendment_logs as al')
            ->join('students as s',    's.id',   '=', 'al.student_id')
            ->leftJoin('admissions as a', 'a.id', '=', 'al.admission_id')
            ->leftJoin('classes as c',   'c.id',  '=', 'a.class_id')
            ->select(
                'al.id',
                'al.ref_no',
                'al.action_type',
                'al.status',
                'al.changed_data',
                'al.modified_by',
                'al.created_at',
                'al.student_id',
                's.name',
                's.father_name',
                's.mobile',
                DB::raw("COALESCE(a.university_roll_no, '') as uni_roll_no"),
                DB::raw("COALESCE(a.semester_no::text, '') as semester_no"),
                DB::raw("COALESCE(c.name, '') as class_name")
            );

        if ($status = $request->input('status')) $q->where('al.status', $status);
        if ($type   = $request->input('activity')) $q->where('al.action_type', $type);
        if ($from   = $request->input('date_from')) $q->whereDate('al.created_at', '>=', $from);
        if ($to     = $request->input('date_to'))   $q->whereDate('al.created_at', '<=', $to);
        if ($search = $request->input('search')) {
            $q->where(function ($qb) use ($search) {
                $qb->where('al.ref_no',      'like', "%$search%")
                   ->orWhere('s.name',       'like', "%$search%")
                   ->orWhere('a.university_roll_no', 'like', "%$search%");
            });
        }

        $total   = (clone $q)->count();
        $pending = (clone $q)->where('al.status', 'Pending')->count();

        $records = $q->orderBy('al.created_at', 'desc')
                     ->paginate($request->input('per_page', 20));

        return response()->json([
            'stats'   => compact('total', 'pending'),
            'records' => $records,
        ]);
    }

    // POST /authorizations/misc-activity/{id}/action
    public function miscActivityAction(Request $request, int $id)
    {
        $request->validate(['action' => 'required|in:Approved,Rejected,RollBack']);

        $newStatus = match($request->input('action')) {
            'Approved' => 'Approved',
            'Rejected' => 'Rejected',
            'RollBack' => 'Pending',
        };

        DB::table('amendment_logs')->where('id', $id)->update([
            'status'      => $newStatus,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'updated_at'  => now(),
        ]);

        // If Approved, apply the actual change from changed_data
        if ($newStatus === 'Approved') {
            $log = DB::table('amendment_logs')->find($id);
            $this->applyAmendmentLog($log);
        }

        DB::table('authorization_logs')->insert([
            'action'       => $request->input('action'),
            'action_type'  => 'MiscActivityVerification',
            'reference_id' => $id,
            'performed_by' => auth()->id(),
            'remarks'      => $request->input('remarks'),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return response()->json(['message' => "Activity {$request->input('action')} successfully."]);
    }

    // Apply approved amendment to the actual tables
    private function applyAmendmentLog(object $log): void
    {
        $data = json_decode($log->changed_data, true);
        if (!$data || !$log->student_id) return;

        switch ($log->action_type) {
            case 'ModifyData':
                DB::table('students')->where('id', $log->student_id)->update($data);
                break;
            case 'SubjectChange':
                if ($log->admission_id) {
                    DB::table('admissions')->where('id', $log->admission_id)
                        ->update(array_intersect_key($data, array_flip(['subject_basic','subject_drop','subject_practical'])));
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
                        ->update(['is_blocked' => $data['action'] === 'block']);
                }
                break;
            case 'AdmissionCancel':
                if ($log->admission_id) {
                    DB::table('admissions')->where('id', $log->admission_id)->update(['status' => 'Cancelled']);
                }
                break;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. BLOCK / UNBLOCK USER (Authorization version — direct action)
    // GET /authorizations/block-unblock?query=...
    // ─────────────────────────────────────────────────────────────────────────
    public function blockUnblockSearch(Request $request)
    {
        $search = $request->input('query', '');
        $session = $request->input('session');

        $q = DB::table('admissions as a')
            ->join('students as s', 's.id', '=', 'a.student_id')
            ->join('classes as c',  'c.id', '=', 'a.class_id')
            ->select(
                'a.id as admission_id', 'a.student_id', 'a.application_no',
                'a.university_roll_no', 'a.session', 'a.semester_no',
                's.name', 's.father_name', 's.mother_name', 's.spouse_name',
                's.gender', 's.category', 's.mobile', 's.aadhar_no', 's.is_blocked',
                'c.name as class_name'
            )
            ->where(function ($qb) use ($search) {
                $qb->where('a.application_no',      'like', "%$search%")
                   ->orWhere('a.reg_no',             'like', "%$search%")
                   ->orWhere('a.university_roll_no', 'like', "%$search%");
            });

        if ($session) $q->where('a.session', $session);

        $result = $q->first();

        return response()->json($result ? ['data' => $result] : ['data' => null, 'message' => 'Not found']);
    }

    // POST /authorizations/block-unblock
    public function blockUnblockAction(Request $request)
    {
        $request->validate([
            'student_id' => 'required|integer',
            'action'     => 'required|in:block,unblock',
            'reason'     => 'nullable|string',
        ]);

        DB::table('students')->where('id', $request->input('student_id'))
            ->update([
                'is_blocked' => $request->input('action') === 'block',
                'updated_at' => now(),
            ]);

        // Also log to amendment_logs for audit
        DB::table('amendment_logs')->insert([
            'student_id'   => $request->input('student_id'),
            'action_type'  => 'BlockUnblock',
            'changed_data' => json_encode(['action' => $request->input('action'), 'reason' => $request->input('reason')]),
            'modified_by'  => auth()->id(),
            'status'       => 'Completed',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $msg = $request->input('action') === 'block' ? 'User blocked.' : 'User unblocked.';
        return response()->json(['message' => $msg]);
    }
}
