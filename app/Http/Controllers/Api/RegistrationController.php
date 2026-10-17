<?php

namespace App\Http\Controllers\Api;

use App\Models\Student;
use App\Http\Controllers\Controller;
use App\Http\Concerns\ResolvesStudentIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RegistrationController extends Controller
{
    use ResolvesStudentIdentity;

    // ══════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════
    private function sessionYear(): string
    {
        return request('session_year', \App\Support\AcademicSession::current());
    }

    // ══════════════════════════════════════════════════════════════
    // 1. REGISTRATION (UG / PG / B.Ed)
    //    All three use the same endpoints, filtered by program level
    // ══════════════════════════════════════════════════════════════

    public function registrationStatus(Request $req)
    {
        $sessionYear = $req->session_year ?? $this->sessionYear();

        $latestApp = DB::table('student_applications')
            ->select('direct_registration_id', DB::raw('MAX(id) as sa_id'))
            ->whereNotNull('direct_registration_id')
            ->whereNull('deleted_at')
            ->groupBy('direct_registration_id');

        // Latest admission per student
        $latestAdm = DB::table('admissions')
            ->select('student_id', DB::raw('MAX(id) as adm_id'))
            ->whereNull('deleted_at')
            ->groupBy('student_id');

        // Latest active fee receipt per admission ("Educational Receipt")
        $latestRcpt = DB::table('fee_receipts')
            ->select('admission_id', DB::raw('MAX(id) as fr_id'))
            ->where('status', 'active')
            ->groupBy('admission_id');

        $regPaid = DB::table('payments')
            ->select('direct_registration_id', DB::raw('MAX(id) as id'))
            ->where('purpose', 'registration')->where('status', 'paid')
            ->groupBy('direct_registration_id');
        $appPaid = DB::table('payments')
            ->select('student_application_id', DB::raw('MAX(id) as id'))
            ->whereIn('purpose', ['admission', 'semester_upgrade', 'back_paper'])->where('status', 'paid')
            ->groupBy('student_application_id');

        $records = DB::table('direct_registrations as dr')
            ->leftJoin('programs as p', 'p.id', 'dr.program_id')
            ->leftJoin('students as s', 's.user_id', '=', 'dr.user_id')
            ->leftJoinSub($latestApp, 'la', 'la.direct_registration_id', '=', 'dr.id')
            ->leftJoin('student_applications as sa', 'sa.id', '=', 'la.sa_id')
            ->leftJoinSub($latestAdm, 'lad', 'lad.student_id', '=', 's.id')
            ->leftJoin('admissions as adm', 'adm.id', '=', 'lad.adm_id')
            ->leftJoinSub($latestRcpt, 'lr', 'lr.admission_id', '=', 'adm.id')
            ->leftJoin('fee_receipts as fr', 'fr.id', '=', 'lr.fr_id')
            ->leftJoinSub($regPaid, 'rp', 'rp.direct_registration_id', '=', 'dr.id')
            ->leftJoin('payments as regpay', 'regpay.id', '=', 'rp.id')
            ->leftJoinSub($appPaid, 'ap', 'ap.student_application_id', '=', 'sa.id')
            ->leftJoin('payments as apppay', 'apppay.id', '=', 'ap.id')
            ->where('dr.session_year', $sessionYear)
            ->whereNull('dr.deleted_at')
            ->when($req->program_id, fn($q) => $q->where('dr.program_id', $req->program_id))
            ->when($req->reg_type, fn($q) => $q->where('dr.reg_type', strtoupper($req->reg_type)))
            ->when($req->from, fn($q) => $q->whereRaw('COALESCE(dr.reg_date, dr.created_at::date) >= ?', [$req->from]))
            ->when($req->to, fn($q) => $q->whereRaw('COALESCE(dr.reg_date, dr.created_at::date) <= ?', [$req->to]))
            ->when($req->search, fn($q) => $q->where(function ($q2) use ($req) {
                $q2->where('dr.name', 'ilike', "%{$req->search}%")
                    ->orWhere('dr.mobile', 'ilike', "%{$req->search}%")
                    ->orWhere('dr.registration_no', 'ilike', "%{$req->search}%")
                    ->orWhere('sa.application_no', 'ilike', "%{$req->search}%");
            }))
            ->select(
                'dr.id',
                'dr.registration_no',
                'dr.reg_type',
                'dr.name',
                'dr.father_name',
                'dr.mobile',
                'dr.email',
                'dr.status as reg_status',
                DB::raw("CASE WHEN regpay.id IS NOT NULL THEN 'paid' ELSE NULL END as reg_payment_status"),
                'regpay.paid_at as reg_paid_at',
                'dr.reg_date',
                'dr.created_at',
                'p.short_name as course',
                'p.level',
                'sa.id as application_id',
                'dr.unique_code as application_code',
                'sa.application_no',
                'sa.status as app_status',
                DB::raw("CASE WHEN apppay.id IS NOT NULL THEN 'paid' ELSE NULL END as edu_payment_status"),
                'apppay.paid_at as edu_paid_at',
                'fr.receipt_no',
                'fr.receipt_date'
            )
            ->orderByDesc('dr.created_at')
            ->get();

        // Derive pipeline flags for each row.
        $rows = $records->map(function ($r) {

        $regComplete = $r->reg_status === 'registered';
            $regFeePaid = $r->reg_payment_status === 'paid';
            $appSubmit = $r->app_status && $r->app_status !== 'draft';
            $approval = match ($r->app_status) {
                'approved' => 'Approved',
                'on_hold', 'rejected' => 'Roll Back',
                'cancelled' => 'Canceled',
                default => null,
            };
            $eduFeePaid = $r->edu_payment_status === 'paid';

            $r->reg_complete = $regComplete;
            $r->reg_fee_paid = $regFeePaid;
            $r->app_initiated = !empty($r->application_id);
            $r->app_final_submit = (bool) $appSubmit;
            $r->app_approval = $approval;
            $r->edu_fee_paid = $eduFeePaid;
            $r->receipt_generated = !empty($r->receipt_no);
            return $r;
        });

        // Live Status counters (scope-wide, ignore check_status filter).
        $summary = [
            'complete_registration' => $rows->where('reg_complete', true)->count(),
            // Registered (form saved) but registration fee still unpaid.
            'pending_registration_fee' => $rows->filter(fn($r) => $r->reg_complete && !$r->reg_fee_paid)->count(),
            'paid_educational_fee' => $rows->where('edu_fee_paid', true)->count(),
            'receipt_generated' => $rows->where('receipt_generated', true)->count(),
            // Application submitted (final) but educational fee not yet paid.
            'application_unpaid' => $rows->filter(fn($r) => $r->app_final_submit && !$r->edu_fee_paid)->count(),
            'total' => $rows->count(),
        ];

        // Apply the "Check Statuse" filter to the table rows only.
        $status = $req->check_status ?? 'all';
        $filtered = $rows->filter(function ($r) use ($status) {
            return match ($status) {
                'complete' => $r->reg_complete,
                'incomplete' => !$r->reg_complete,
                'final_submit' => $r->app_final_submit,
                'approved', 'application' => $r->app_approval === 'Approved',
                default => true,
            };
        })->values();

        // Manual pagination + serial numbers.
        $perPage = max(1, (int) ($req->per_page ?? 50));
        $page = max(1, (int) ($req->page ?? 1));
        $total = $filtered->count();

        $data = $filtered->forPage($page, $perPage)->values()
            ->map(function ($r, $i) use ($page, $perPage) {
                $r->sr_no = ($page - 1) * $perPage + $i + 1;
                return $r;
            });

        // Class dropdown options.
        $classes = DB::table('programs')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->select('id', 'short_name', 'samarth_code', 'level')
            ->orderBy('level')->orderBy('short_name')
            ->get();

        return response()->json([
            'summary' => $summary,
            'data' => $data,
            'classes' => $classes,
            'session_year' => $sessionYear,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // 3. STUDENT STATUS
    //    Per-student registration status lookup
    // ══════════════════════════════════════════════════════════════

    public function studentStatus(Request $req)
    {
        $v = Validator::make($req->all(), [
            'roll_no' => 'nullable|string',
            'search' => 'nullable|string',
            'session_year' => 'nullable|string|max:12',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $sessionYear = $req->session_year ?: $this->sessionYear();
        $latestReg = $this->latestRegistrationSub();
        $sessionApp = DB::table('student_applications')
            ->select('student_id', DB::raw('MAX(id) as app_id'))
            ->where('academic_year', $sessionYear)
            ->whereIn('application_type', ApplicationController::ADMISSION_APP_TYPES)
            ->whereNull('deleted_at')
            ->groupBy('student_id');

        $statusAppPaid = DB::table('payments')
            ->select('student_application_id', DB::raw('MAX(id) as id'))
            ->whereIn('purpose', ['admission', 'semester_upgrade', 'back_paper'])->where('status', 'paid')
            ->groupBy('student_application_id');

        $q = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->leftJoinSub($sessionApp, 'la', 'la.student_id', 's.id')
            ->leftJoin('student_applications as sa', 'sa.id', 'la.app_id')
            ->leftJoinSub($statusAppPaid, 'sap', 'sap.student_application_id', '=', 'sa.id')
            ->leftJoin('payments as sapay', 'sapay.id', '=', 'sap.id')
            ->whereNull('a.deleted_at')
            ->select(
                'a.id as admission_id',
                'a.program_id',
                's.university_roll_no as roll_no',
                'a.account_no',
                's.enrollment_no',
                'a.semester_no',
                'a.status as admission_status',
                's.personal_info->first_name as first_name',
                's.personal_info->middle_name as middle_name',
                's.personal_info->last_name as last_name',
                'dr.name',
                'dr.father_name',
                'dr.mother_name',
                'dr.dob',
                's.mobile',
                's.personal_info->gender as gender',
                's.personal_info->date_of_birth as date_of_birth',
                'p.short_name as course',
                'p.full_name',
                'p.level',
                'sa.id as reg_id',
                'sa.application_no',
                'sa.semester_no as reg_semester',
                'sa.status as reg_status',
                'sa.application_type as exam_type',
                DB::raw('(sapay.id is not null) as fee_paid'),
                'sa.approved_at'
            )
            ->when($req->roll_no, fn($q) => $q->where('s.university_roll_no', $req->roll_no))
            ->when($req->search, fn($q) => $q->where(function ($q2) use ($req) {
                $q2->where('dr.name', 'ilike', "%{$req->search}%")
                    ->orWhere('s.personal_info->first_name', 'ilike', "%{$req->search}%")
                    ->orWhere('s.personal_info->last_name', 'ilike', "%{$req->search}%")
                    ->orWhere('s.university_roll_no', 'ilike', "%{$req->search}%")
                    ->orWhere('s.mobile', 'ilike', "%{$req->search}%");
            }))
            ->when($req->program_id, fn($q) => $q->where('a.program_id', $req->program_id))
            ->orderByDesc('a.id');

        $result = $q->paginate(20);
        $result->getCollection()->transform(function ($row) {
            $row = $this->withComposedName($row);
            $row->dob = $row->dob ?? $row->date_of_birth ?? null;
            return $row;
        });

        return response()->json($result);
    }

    // ══════════════════════════════════════════════════════════════
    // 6. STUDENT PORTAL — Registration Status
    //    GET /student/registration-status
    //    The signed-in student's semester status, built from their latest
    //    regular / semester-upgrade application (session filter optional).
    //    Works before a students row exists (first-time applicants).
    // ══════════════════════════════════════════════════════════════
    public function studentPortalStatus(Request $req)
    {
        $user = $req->user();
        $student = Student::flat(DB::table('students')->where('user_id', $user->id)->first());
        $pending = $this->pendingRegistrationFor($user);

        $portalAppPaid = DB::table('payments')
            ->select('student_application_id', DB::raw('MAX(id) as id'))
            ->whereIn('purpose', ['admission', 'semester_upgrade', 'back_paper'])->where('status', 'paid')
            ->groupBy('student_application_id');

        $app = DB::table('student_applications as sa')
            ->join('programs as p', 'p.id', 'sa.program_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'sa.direct_registration_id')
            ->leftJoinSub($portalAppPaid, 'pap', 'pap.student_application_id', '=', 'sa.id')
            ->leftJoin('payments as pappay', 'pappay.id', '=', 'pap.id')
            ->leftJoin('fee_receipts as fr', 'fr.id', '=', 'pappay.fee_receipt_id')
            ->leftJoin('admissions as a', 'a.application_id', 'sa.id')
            ->whereNull('sa.deleted_at')
            ->whereIn('sa.application_type', ApplicationController::ADMISSION_APP_TYPES)
            ->where(function ($q) use ($user, $student) {
                $q->where('sa.user_id', $user->id);
                if ($student) {
                    $q->orWhere('sa.student_id', $student->id);
                }
            })
            ->when($req->session_year, fn($q) => $q->where('sa.academic_year', $req->session_year))
            ->orderByDesc('sa.id')
            ->select(
                'sa.id as application_id',
                'sa.application_no',
                'sa.application_type',
                'sa.academic_year as session',
                'sa.semester_no as semester',
                'sa.status as app_status',
                DB::raw('(pappay.id is not null) as fee_paid'),
                'pappay.paid_at as paid_at',
                'sa.approved_at',
                'sa.updated_at',
                'sa.part_6',
                'sa.program_id',
                'p.short_name as program',
                'p.full_name as program_name',
                'dr.registration_no',
                'fr.receipt_no',
                'fr.is_verified as receipt_verified',
                'fr.verified_at as receipt_verified_at',
                'fr.status as receipt_status',
                'a.status as admission_status'
            )
            ->first();

        if (!$app) {
            return response()->json([
                'data' => null,
                'pending_registration' => $pending,
                'message' => $pending ? 'Complete your registration payment and verification.' : 'No application found.',
            ]);
        }

        // Each step: approved | rejected | cancelled | on_hold | pending
        $formStatus = match ($app->app_status) {
            'draft' => 'pending',
            'rejected' => 'rejected',
            'cancelled' => 'cancelled',
            default => 'approved', // submitted onwards = form complete
        };
        $feeStatus = !$app->fee_paid ? 'pending'
            : ($app->receipt_status === 'cancelled' ? 'cancelled' : ($app->receipt_verified ? 'verified' : 'pending'));
        $verification = match ($app->app_status) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'cancelled' => 'cancelled',
            'on_hold' => 'on_hold',
            default => 'pending',
        };
        $admitted = $app->admission_status === 'active' ? 'approved'
            : (in_array($app->admission_status, ['cancelled', 'rejected'], true) ? $app->admission_status : 'pending');

        $data = [
            'application_id' => $app->application_id,
            'application_no' => $app->application_no,
            'application_type' => $app->application_type,
            'session' => $app->session,
            'semester' => $app->semester,
            'program' => $app->program,
            'program_name' => $app->program_name,
            'registration_no' => $app->registration_no,
            'roll_no' => $student->university_roll_no ?? null,
            'enrollment_no' => $student->enrollment_no ?? null,
            'receipt_no' => $app->receipt_no,
            'registration_status' => $formStatus,
            'fee_receipt_status' => $feeStatus,
            'fee_receipt_status_date' => $app->receipt_verified_at ?? $app->paid_at,
            'verification_status' => $verification,
            'verification_status_date' => $app->approved_at,
            'principal_approval_status' => $admitted,
            'subjects' => $this->subjectsFromPart6($app),
        ];

        return response()->json([
            'data' => $data,
            'pending_registration' => $pending,
        ]);
    }

    /** Subjects / papers the application's Part 6 recorded, for display. */
    private function subjectsFromPart6(object $app): array
    {
        $p6 = $app->part_6 ? (json_decode($app->part_6, true) ?: []) : [];
        $out = [];

        $paperIds = array_merge(
            (array) ($p6['compulsory_paper_ids'] ?? []),
            (array) ($p6['optional_paper_ids'] ?? []),
            (array) ($p6['teaching_paper_ids'] ?? [])
        );
        if ($paperIds) {
            $papers = DB::table('subject_papers')->whereIn('id', array_map('intval', $paperIds))
                ->get(['id', 'paper_name', 'paper_code', 'selection_type'])->keyBy('id');
            foreach ($paperIds as $id) {
                if ($pp = $papers->get((int) $id)) {
                    $out[] = ['name' => $pp->paper_name, 'paper_code' => $pp->paper_code, 'type' => $pp->selection_type];
                }
            }
            return $out;
        }

        $subjectIds = array_values(array_filter([
            $p6['subject_1'] ?? null, $p6['subject_2'] ?? null, $p6['subject_3'] ?? null,
        ]));
        if ($subjectIds) {
            $names = DB::table('subjects')->whereIn('id', array_map('intval', $subjectIds))->pluck('name', 'id');
            foreach ($subjectIds as $id) {
                if (isset($names[(int) $id])) {
                    $out[] = ['name' => $names[(int) $id], 'paper_code' => null, 'type' => 'Core'];
                }
            }
        }
        return $out;
    }

    private function pendingRegistrationFor($user): ?array
    {
        $row = DB::table('direct_registrations as dr')
            ->leftJoin('programs as p', 'p.id', '=', 'dr.program_id')
            ->where(function ($q) use ($user) {
                $q->where('dr.user_id', $user->id);
                if (!empty($user->mobile)) {
                    $q->orWhere('dr.mobile', $user->mobile);
                }
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('payments')
                    ->whereColumn('payments.direct_registration_id', 'dr.id')
                    ->where('payments.purpose', 'registration')->where('payments.status', 'paid');
            })
            ->whereNull('dr.deleted_at')
            ->orderByDesc('dr.id')
            ->select(
                'dr.id as registration_id',
                'dr.registration_no',
                'dr.reg_type',
                'dr.session_year as session',
                'dr.name',
                'dr.father_name',
                'dr.fee_amount',
                'dr.status',
                'dr.phone_verified',
                'dr.email_verified',
                'dr.reg_date',
                'p.short_name as program',
                'p.full_name as program_name'
            )
            ->first();

        if (!$row) {
            return null;
        }

        return [
            'registration_id' => $row->registration_id,
            'reg_no' => $row->registration_no,
            'reg_type' => $row->reg_type,
            'session' => $row->session,
            'program' => $row->program,
            'program_name' => $row->program_name,
            'name' => $row->name,
            'father_name' => $row->father_name,
            'fee' => (float) ($row->fee_amount ?? 0),
            'payment_status' => 'pending', // this row only exists when it is not paid
            'status' => $row->status,
            'phone_verified' => (bool) $row->phone_verified,
            'email_verified' => (bool) $row->email_verified,
            'reg_date' => $row->reg_date,
            'needs_payment' => true,
            'needs_verification' => !$row->phone_verified || !$row->email_verified,
        ];
    }

    // ══════════════════════════════════════════════════════════════
    // 7. STUDENT PORTAL — Registration Dashboard
    // ══════════════════════════════════════════════════════════════
    public function studentRegistrationDashboard(Request $req)
    {
        $user = $req->user();

        $dashRegPaid = DB::table('payments')
            ->select('direct_registration_id', DB::raw('MAX(id) as id'))
            ->where('purpose', 'registration')->where('status', 'paid')
            ->groupBy('direct_registration_id');

        $reg = DB::table('direct_registrations as dr')
            ->leftJoin('programs as p', 'p.id', '=', 'dr.program_id')
            ->leftJoinSub($dashRegPaid, 'drp', 'drp.direct_registration_id', '=', 'dr.id')
            ->leftJoin('payments as drpay', 'drpay.id', '=', 'drp.id')
            ->where(function ($q) use ($user) {
                $q->where('dr.user_id', $user->id);
                if (!empty($user->mobile)) {
                    $q->orWhere('dr.mobile', $user->mobile);
                }
            })
            ->whereNull('dr.deleted_at')
            ->orderByDesc('dr.id')
            ->select(
                'dr.id as registration_id',
                'dr.registration_no',
                'dr.reg_type',
                'dr.name',
                'dr.father_name',
                'dr.dob',
                'dr.session_year as session',
                'dr.status',
                DB::raw("CASE WHEN drpay.id IS NOT NULL THEN 'paid' ELSE 'pending' END as payment_status"),
                'drpay.paid_at as paid_at',
                'dr.reg_date',
                'drpay.receipt_no as receipt_no',
                'dr.updated_at',
                'dr.program_id',
                'p.short_name as course',
                'p.full_name as program_name'
            )
            ->first();

        if (!$reg) {
            return response()->json(['data' => null]);
        }

        // Fresh registration is semester 1; use the latest admission if the
        // student has already progressed beyond registration.
        $semester = 1;
        $student = Student::flat(DB::table('students')->where('user_id', $user->id)->first());
        if ($student) {
            $adm = DB::table('admissions')->where('student_id', $student->id)
                ->orderByDesc('id')->first();
            if ($adm) {
                $semester = $adm->semester_no;
            }
        }

        // Same fix as registrationStatus() above — 'registered' is the real
        // completion status, not merely "not incomplete".
        $regComplete = $reg->status === 'registered';
        $feePaid = $reg->payment_status === 'paid';

        return response()->json([
            'data' => [
                'registration_id' => $reg->registration_id,
                'registration_no' => $reg->registration_no,
                'reg_type' => $reg->reg_type,
                'name' => $reg->name,
                'father_name' => $reg->father_name,
                'dob' => $reg->dob,
                'course' => $reg->course,
                'program_id' => $reg->program_id,
                'program_name' => $reg->program_name,
                'semester' => $semester,
                'session' => $reg->session,
                'status' => $reg->status,
                'payment_status' => $reg->payment_status,
                'reg_complete' => $regComplete,
                'reg_fee_paid' => $feePaid,
                'receipt_available' => $feePaid && !empty($reg->receipt_no),
                'receipt_no' => $reg->receipt_no,
                'reg_date' => $reg->reg_date,
                'paid_at' => $reg->paid_at,
                'updated_at' => $reg->updated_at,
            ] + \App\Support\StudentSemesters::cards($user, $reg->program_id ? (int) $reg->program_id : null),
        ]);
    }
}
