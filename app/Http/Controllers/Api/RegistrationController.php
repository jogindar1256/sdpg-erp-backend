<?php

namespace App\Http\Controllers\Api;

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
        return request('session_year', date('Y') . '-' . (date('Y') + 1));
    }

    // ══════════════════════════════════════════════════════════════
    // 1. REGISTRATION (UG / PG / B.Ed)
    //    All three use the same endpoints, filtered by program level
    // ══════════════════════════════════════════════════════════════
    public function index(Request $req)
    {
        // latest direct_registrations snapshot per user (see
        // ResolvesStudentIdentity), with first/middle/last as a fallback.
        $latestReg = $this->latestRegistrationSub();

        $q = DB::table('semester_registrations as sr')
            ->join('admissions as a',  'a.id',  'sr.admission_id')
            ->join('students as s',    's.id',  'a.student_id')
            ->join('programs as p',    'p.id',  'a.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->select(
                'sr.*',
                's.first_name', 's.middle_name', 's.last_name',
                'dr.name', 'dr.father_name',
                's.mobile', 's.gender',
                'a.roll_no', 'a.account_no', 'a.enrollment_no',
                'p.short_name as class', 'p.full_name', 'p.level'
            )
            ->where('sr.session_year', $req->session_year ?? $this->sessionYear())
            ->when($req->level,       fn($q) => $q->where('p.level',       $req->level))
            ->when($req->program_id,  fn($q) => $q->where('a.program_id',  $req->program_id))
            ->when($req->semester_no, fn($q) => $q->where('sr.semester_no', $req->semester_no))
            ->when($req->status,      fn($q) => $q->where('sr.status',      $req->status))
            ->when($req->search,      fn($q) => $q->where(function ($q2) use ($req) {
                $q2->where('dr.name',    'ilike', "%{$req->search}%")
                   ->orWhere('s.first_name', 'ilike', "%{$req->search}%")
                   ->orWhere('s.last_name', 'ilike', "%{$req->search}%")
                   ->orWhere('a.roll_no', 'ilike', "%{$req->search}%");
            }))
            ->orderBy('a.roll_no');

        $result = $q->paginate(50);
        $result->getCollection()->transform(fn($row) => $this->withComposedName($row));

        return response()->json($result);
    }

    /**
     * Approve / reject a single registration
     */
    public function updateStatus(Request $req, $id)
    {
        $v = Validator::make($req->all(), [
            'status' => 'required|in:Approved,Pending,Rejected',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        DB::table('semester_registrations')->where('id', $id)
            ->update(['status' => $req->status, 'approved_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Status updated.']);
    }

    /**
     * Bulk approve all filtered records
     */
    public function bulkApprove(Request $req)
    {
        $ids = DB::table('semester_registrations as sr')
            ->join('admissions as a', 'a.id', 'sr.admission_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->where('sr.session_year', $req->session_year ?? $this->sessionYear())
            ->when($req->level,       fn($q) => $q->where('p.level', $req->level))
            ->when($req->program_id,  fn($q) => $q->where('a.program_id', $req->program_id))
            ->when($req->semester_no, fn($q) => $q->where('sr.semester_no', $req->semester_no))
            ->where('sr.status', 'Pending')
            ->pluck('sr.id');

        DB::table('semester_registrations')->whereIn('id', $ids)
            ->update(['status' => 'Approved', 'approved_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => count($ids) . ' registrations approved.']);
    }

    /**
     * Create a new registration record
     */
    public function store(Request $req)
    {
        $v = Validator::make($req->all(), [
            'admission_id'  => 'required|exists:admissions,id',
            'session_year'  => 'required|string',
            'semester_no'   => 'required|string',
            'exam_type'     => 'required|in:Regular,Back Paper,Upgrade',
            'fee_paid'      => 'nullable|boolean',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        // Prevent duplicate
        $exists = DB::table('semester_registrations')
            ->where('admission_id', $req->admission_id)
            ->where('session_year', $req->session_year)
            ->where('semester_no', $req->semester_no)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Registration already exists for this student/semester.'], 409);
        }

        $id = DB::table('semester_registrations')->insertGetId([
            'admission_id'  => $req->admission_id,
            'session_year'  => $req->session_year,
            'semester_no'   => $req->semester_no,
            'exam_type'     => $req->exam_type,
            'fee_paid'      => $req->fee_paid ?? false,
            'status'        => 'Pending',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return response()->json(['id' => $id, 'message' => 'Registration created.'], 201);
    }

    public function registrationStatus(Request $req)
    {
        $sessionYear = $req->session_year ?? $this->sessionYear();

        // Latest fresh application per student (avoids row multiplication)
        $latestApp = DB::table('student_applications')
            ->select('student_id', DB::raw('MAX(id) as sa_id'))
            ->whereNull('deleted_at')
            ->groupBy('student_id');

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

        $records = DB::table('direct_registrations as dr')
            ->leftJoin('programs as p', 'p.id', 'dr.program_id')
            ->leftJoin('students as s', 's.user_id', '=', 'dr.user_id')
            ->leftJoinSub($latestApp, 'la', 'la.student_id', '=', 's.id')
            ->leftJoin('student_applications as sa', 'sa.id', '=', 'la.sa_id')
            ->leftJoinSub($latestAdm, 'lad', 'lad.student_id', '=', 's.id')
            ->leftJoin('admissions as adm', 'adm.id', '=', 'lad.adm_id')
            ->leftJoinSub($latestRcpt, 'lr', 'lr.admission_id', '=', 'adm.id')
            ->leftJoin('fee_receipts as fr', 'fr.id', '=', 'lr.fr_id')
            ->where('dr.session_year', $sessionYear)
            ->whereNull('dr.deleted_at')
            ->when($req->program_id, fn($q) => $q->where('dr.program_id', $req->program_id))
            ->when($req->reg_type,   fn($q) => $q->where('dr.reg_type', strtoupper($req->reg_type)))
            ->when($req->from, fn($q) => $q->whereRaw('COALESCE(dr.reg_date, dr.created_at::date) >= ?', [$req->from]))
            ->when($req->to,   fn($q) => $q->whereRaw('COALESCE(dr.reg_date, dr.created_at::date) <= ?', [$req->to]))
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
                'dr.payment_status as reg_payment_status',
                'dr.paid_at as reg_paid_at',
                'dr.reg_date',
                'dr.created_at',
                'p.short_name as class',
                'p.level',
                'sa.application_no',
                'sa.status as app_status',
                'adm.payment_status as edu_payment_status',
                'adm.paid_at as edu_paid_at',
                'fr.receipt_no',
                'fr.receipt_date'
            )
            ->orderByDesc('dr.created_at')
            ->get();

        // Derive pipeline flags for each row.
        $rows = $records->map(function ($r) {
            $regComplete = $r->reg_status !== 'incomplete';
            $regFeePaid  = $r->reg_payment_status === 'paid';
            $appSubmit   = $r->app_status && $r->app_status !== 'draft';
            $approval    = match ($r->app_status) {
                'approved'            => 'Approved',
                'on_hold', 'rejected' => 'Roll Back',
                'cancelled'           => 'Canceled',
                default               => null,
            };
            $eduFeePaid  = $r->edu_payment_status === 'paid';

            $r->reg_complete      = $regComplete;
            $r->reg_fee_paid      = $regFeePaid;
            $r->app_final_submit  = (bool) $appSubmit;
            $r->app_approval      = $approval;
            $r->edu_fee_paid      = $eduFeePaid;
            $r->receipt_generated = !empty($r->receipt_no);
            return $r;
        });

        // Live Status counters (scope-wide, ignore check_status filter).
        $summary = [
            'complete_registration'    => $rows->where('reg_complete', true)->count(),
            // Registered (form saved) but registration fee still unpaid.
            'pending_registration_fee' => $rows->filter(fn($r) => $r->reg_complete && !$r->reg_fee_paid)->count(),
            'paid_educational_fee'     => $rows->where('edu_fee_paid', true)->count(),
            'receipt_generated'        => $rows->where('receipt_generated', true)->count(),
            // Application submitted (final) but educational fee not yet paid.
            'application_unpaid'       => $rows->filter(fn($r) => $r->app_final_submit && !$r->edu_fee_paid)->count(),
            'total'                    => $rows->count(),
        ];

        // Apply the "Check Statuse" filter to the table rows only.
        $status = $req->check_status ?? 'all';
        $filtered = $rows->filter(function ($r) use ($status) {
            return match ($status) {
                'complete'              => $r->reg_complete,
                'incomplete'            => !$r->reg_complete,
                'final_submit'          => $r->app_final_submit,
                'approved', 'application' => $r->app_approval === 'Approved',
                default                 => true,
            };
        })->values();

        // Manual pagination + serial numbers.
        $perPage = max(1, (int) ($req->per_page ?? 50));
        $page    = max(1, (int) ($req->page ?? 1));
        $total   = $filtered->count();

        $data = $filtered->forPage($page, $perPage)->values()
            ->map(function ($r, $i) use ($page, $perPage) {
                $r->sr_no = ($page - 1) * $perPage + $i + 1;
                return $r;
            });

        // Class dropdown options.
        $classes = DB::table('programs')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->select('id', 'short_name', 'level')
            ->orderBy('level')->orderBy('short_name')
            ->get();

        return response()->json([
            'summary'      => $summary,
            'data'         => $data,
            'classes'      => $classes,
            'session_year' => $sessionYear,
            'meta'         => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => max(1, (int) ceil($total / $perPage)),
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
            'roll_no' => 'required_without:search|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $latestReg = $this->latestRegistrationSub();

        $q = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->leftJoin('semester_registrations as sr', function ($j) use ($req) {
                $j->on('sr.admission_id', 'a.id')
                  ->where('sr.session_year', $req->session_year ?? '2025-2026');
            })
            ->select(
                'a.id as admission_id', 'a.roll_no', 'a.account_no', 'a.enrollment_no', 'a.semester_no',
                's.first_name', 's.middle_name', 's.last_name',
                'dr.name', 'dr.father_name', 'dr.dob',
                's.mobile', 's.gender', 's.date_of_birth',
                'p.short_name as class', 'p.full_name', 'p.level',
                'sr.id as reg_id', 'sr.semester_no as reg_semester', 'sr.status as reg_status',
                'sr.exam_type', 'sr.fee_paid', 'sr.approved_at'
            )
            ->when($req->roll_no, fn($q) => $q->where('a.roll_no', $req->roll_no))
            ->when($req->search,  fn($q) => $q->where(function ($q2) use ($req) {
                $q2->where('dr.name',     'ilike', "%{$req->search}%")
                   ->orWhere('s.first_name', 'ilike', "%{$req->search}%")
                   ->orWhere('s.last_name', 'ilike', "%{$req->search}%")
                   ->orWhere('a.roll_no', 'ilike', "%{$req->search}%")
                   ->orWhere('s.mobile',  'ilike', "%{$req->search}%");
            }))
            ->when($req->program_id, fn($q) => $q->where('a.program_id', $req->program_id));

        $result = $q->paginate(20);
        $result->getCollection()->transform(function ($row) {
            $row = $this->withComposedName($row);
            $row->dob = $row->dob ?? $row->date_of_birth ?? null;
            return $row;
        });

        return response()->json($result);
    }

    // Summary stats for the registration pages
    public function stats(Request $req)
    {
        $sessionYear = $req->session_year ?? $this->sessionYear();

        return response()->json([
            'total_registered' => DB::table('semester_registrations')
                ->where('session_year', $sessionYear)->where('status', 'Approved')->count(),
            'pending'          => DB::table('semester_registrations')
                ->where('session_year', $sessionYear)->where('status', 'Pending')->count(),
            'total_students'   => DB::table('admissions')->where('status', 'Verified')->count(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // 6. STUDENT PORTAL — Registration Status
    //    GET /student/registration-status
    //    Returns the authenticated student's own registration record.
    // ══════════════════════════════════════════════════════════════
    public function studentPortalStatus(Request $req)
    {
        $user    = $req->user();
        $student = DB::table('students')->where('user_id', $user->id)->first();
        $sessionYear = $req->session_year ?? $this->sessionYear();
        $pending = $this->pendingRegistrationFor($user);

        // the pending draft so the portal can prompt for payment/verification
        if (!$student) {
            return response()->json([
                'data'                 => null,
                'pending_registration' => $pending,
                'message'              => $pending
                    ? 'Complete your registration payment and verification.'
                    : 'Student profile not found.',
            ], $pending ? 200 : 404);
        }

        $record = DB::table('admissions as a')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->leftJoin('semester_registrations as sr', function ($j) use ($sessionYear) {
                $j->on('sr.admission_id', 'a.id')
                  ->where('sr.session_year', $sessionYear);
            })
            ->where('a.student_id', $student->id)
            ->select(
                'a.roll_no', 'a.enrollment_no', 'a.semester_no as semester',
                'p.short_name as program', 'p.full_name as program_name',
                DB::raw("'{$sessionYear}' as session"),
                'sr.id as registration_id',
                'sr.status as registration_status',
                'sr.fee_paid as fee_receipt_status',
                'sr.exam_type',
                'sr.approved_at',
            )
            ->latest('a.id')
            ->first();

        if (!$record) {
            return response()->json([
                'data'                 => null,
                'pending_registration' => $pending,
            ]);
        }

        // Load subjects for this registration
        $subjects = [];
        if ($record->registration_id) {
            $subjects = DB::table('registration_subjects as rs')
                ->join('subjects as sub', 'sub.id', 'rs.subject_id')
                ->where('rs.registration_id', $record->registration_id)
                ->select('sub.name', 'sub.paper_code', 'rs.type')
                ->get();
        }

        return response()->json([
            'data'                 => array_merge((array) $record, ['subjects' => $subjects]),
            'pending_registration' => $pending,
        ]);
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
            ->where('dr.payment_status', '!=', 'paid')
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
                'dr.payment_status',
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
            'registration_id'    => $row->registration_id,
            'reg_no'             => $row->registration_no,
            'reg_type'           => $row->reg_type,
            'session'            => $row->session,
            'program'            => $row->program,
            'program_name'       => $row->program_name,
            'name'               => $row->name,
            'father_name'        => $row->father_name,
            'fee'                => (float) ($row->fee_amount ?? 0),
            'payment_status'     => $row->payment_status,
            'status'             => $row->status,
            'phone_verified'     => (bool) $row->phone_verified,
            'email_verified'     => (bool) $row->email_verified,
            'reg_date'           => $row->reg_date,
            'needs_payment'      => $row->payment_status !== 'paid',
            'needs_verification' => !$row->phone_verified || !$row->email_verified,
        ];
    }

    // ══════════════════════════════════════════════════════════════
    // 7. STUDENT PORTAL — Registration Dashboard
    // ══════════════════════════════════════════════════════════════
    public function studentRegistrationDashboard(Request $req)
    {
        $user = $req->user();

        $reg = DB::table('direct_registrations as dr')
            ->leftJoin('programs as p', 'p.id', '=', 'dr.program_id')
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
                'dr.payment_status',
                'dr.paid_at',
                'dr.reg_date',
                'dr.receipt_no',
                'dr.updated_at',
                'dr.program_id',
                'p.short_name as class',
                'p.full_name as program_name'
            )
            ->first();

        if (!$reg) {
            return response()->json(['data' => null]);
        }

        // Fresh registration is semester 1; use the latest admission if the
        // student has already progressed beyond registration.
        $semester = 1;
        $student  = DB::table('students')->where('user_id', $user->id)->first();
        if ($student) {
            $adm = DB::table('admissions')->where('student_id', $student->id)
                ->orderByDesc('id')->first();
            if ($adm) {
                $semester = $adm->semester_no;
            }
        }

        $regComplete = $reg->status !== 'incomplete';
        $feePaid     = $reg->payment_status === 'paid';

        return response()->json(['data' => [
            'registration_id'   => $reg->registration_id,
            'registration_no'   => $reg->registration_no,
            'reg_type'          => $reg->reg_type,
            'name'              => $reg->name,
            'father_name'       => $reg->father_name,
            'dob'               => $reg->dob,
            'class'             => $reg->class,
            'program_id'        => $reg->program_id,
            'program_name'      => $reg->program_name,
            'semester'          => $semester,
            'session'           => $reg->session,
            'status'            => $reg->status,
            'payment_status'    => $reg->payment_status,
            'reg_complete'      => $regComplete,
            'reg_fee_paid'      => $feePaid,
            'receipt_available' => $feePaid && !empty($reg->receipt_no),
            'receipt_no'        => $reg->receipt_no,
            'reg_date'          => $reg->reg_date,
            'paid_at'           => $reg->paid_at,
            'updated_at'        => $reg->updated_at,
        ]]);
    }
}
