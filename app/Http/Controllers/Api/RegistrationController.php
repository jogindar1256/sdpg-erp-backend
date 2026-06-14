<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RegistrationController extends Controller
{
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

    /**
     * List students eligible/pending/completed registration
     * level: UG | PG | B.Ed
     */
    public function index(Request $req)
    {
        $q = DB::table('semester_registrations as sr')
            ->join('admissions as a',  'a.id',  'sr.admission_id')
            ->join('students as s',    's.id',  'a.student_id')
            ->join('programs as p',    'p.id',  'a.program_id')
            ->select(
                'sr.*',
                's.name', 's.father_name', 's.mobile', 's.gender',
                'a.roll_no', 'a.account_no', 'a.enrollment_no',
                'p.short_name as class', 'p.full_name', 'p.level'
            )
            ->where('sr.session_year', $req->session_year ?? $this->sessionYear())
            ->when($req->level,       fn($q) => $q->where('p.level',       $req->level))
            ->when($req->program_id,  fn($q) => $q->where('a.program_id',  $req->program_id))
            ->when($req->semester_no, fn($q) => $q->where('sr.semester_no', $req->semester_no))
            ->when($req->status,      fn($q) => $q->where('sr.status',      $req->status))
            ->when($req->search,      fn($q) => $q->where(function ($q2) use ($req) {
                $q2->where('s.name',    'ilike', "%{$req->search}%")
                   ->orWhere('a.roll_no', 'ilike', "%{$req->search}%");
            }))
            ->orderBy('a.roll_no');

        return response()->json($q->paginate(50));
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

    // ══════════════════════════════════════════════════════════════
    // 2. REGISTRATION STATUS
    //    Summary view — how many students registered vs not
    // ══════════════════════════════════════════════════════════════
    public function registrationStatus(Request $req)
    {
        $sessionYear = $req->session_year ?? $this->sessionYear();

        $summary = DB::table('programs as p')
            ->leftJoin('admissions as a', 'a.program_id', 'p.id')
            ->leftJoin('semester_registrations as sr', function ($j) use ($sessionYear, $req) {
                $j->on('sr.admission_id', 'a.id')
                  ->where('sr.session_year', $sessionYear)
                  ->when($req->semester_no, fn($j2) => $j2->where('sr.semester_no', $req->semester_no));
            })
            ->when($req->level, fn($q) => $q->where('p.level', $req->level))
            ->select(
                'p.id as program_id', 'p.short_name as class', 'p.level',
                DB::raw('COUNT(DISTINCT a.id) as total_admitted'),
                DB::raw("COUNT(DISTINCT CASE WHEN sr.status = 'Approved' THEN sr.id END) as registered"),
                DB::raw("COUNT(DISTINCT CASE WHEN sr.status = 'Pending' THEN sr.id END) as pending"),
                DB::raw("COUNT(DISTINCT CASE WHEN sr.status IS NULL THEN a.id END) as not_registered")
            )
            ->groupBy('p.id', 'p.short_name', 'p.level')
            ->orderBy('p.level')->orderBy('p.short_name')
            ->get();

        return response()->json($summary);
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

        $q = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->leftJoin('semester_registrations as sr', function ($j) use ($req) {
                $j->on('sr.admission_id', 'a.id')
                  ->where('sr.session_year', $req->session_year ?? '2025-2026');
            })
            ->select(
                'a.id as admission_id', 'a.roll_no', 'a.account_no', 'a.enrollment_no', 'a.semester_no',
                's.name', 's.father_name', 's.mobile', 's.gender', 's.dob',
                'p.short_name as class', 'p.full_name', 'p.level',
                'sr.id as reg_id', 'sr.semester_no as reg_semester', 'sr.status as reg_status',
                'sr.exam_type', 'sr.fee_paid', 'sr.approved_at'
            )
            ->when($req->roll_no, fn($q) => $q->where('a.roll_no', $req->roll_no))
            ->when($req->search,  fn($q) => $q->where(function ($q2) use ($req) {
                $q2->where('s.name',     'ilike', "%{$req->search}%")
                   ->orWhere('a.roll_no', 'ilike', "%{$req->search}%")
                   ->orWhere('s.mobile',  'ilike', "%{$req->search}%");
            }))
            ->when($req->program_id, fn($q) => $q->where('a.program_id', $req->program_id));

        return response()->json($q->paginate(20));
    }

    // ══════════════════════════════════════════════════════════════
    // 4. SUBJECT GROUP
    //    Assign/view subject groups for a class+semester
    // ══════════════════════════════════════════════════════════════
    public function subjectGroupIndex(Request $req)
    {
        $q = DB::table('student_subject_groups as ssg')
            ->join('admissions as a',  'a.id',  'ssg.admission_id')
            ->join('students as s',    's.id',  'a.student_id')
            ->join('programs as p',    'p.id',  'a.program_id')
            ->join('subjects as sub',  'sub.id', 'ssg.subject_id')
            ->select(
                'ssg.*',
                's.name', 'a.roll_no', 'a.semester_no',
                'p.short_name as class',
                'sub.name as subject_name'
            )
            ->where('ssg.session_year', $req->session_year ?? $this->sessionYear())
            ->when($req->program_id,  fn($q) => $q->where('a.program_id',  $req->program_id))
            ->when($req->semester_no, fn($q) => $q->where('ssg.semester_no', $req->semester_no))
            ->orderBy('a.roll_no');

        return response()->json($q->paginate(50));
    }

    public function subjectGroupStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'admission_id'  => 'required|exists:admissions,id',
            'subject_id'    => 'required|exists:subjects,id',
            'session_year'  => 'required|string',
            'semester_no'   => 'required|string',
            'group_no'      => 'required|integer',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        DB::table('student_subject_groups')->updateOrInsert(
            [
                'admission_id' => $req->admission_id,
                'subject_id'   => $req->subject_id,
                'session_year' => $req->session_year,
                'semester_no'  => $req->semester_no,
            ],
            ['group_no' => $req->group_no, 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json(['message' => 'Subject group saved.']);
    }

    public function subjectGroupDestroy($id)
    {
        DB::table('student_subject_groups')->where('id', $id)->delete();
        return response()->json(['message' => 'Removed.']);
    }

    // Bulk assign subject groups from subject_selections master
    public function subjectGroupAutoAssign(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id'   => 'required|exists:programs,id',
            'session_year' => 'required|string',
            'semester_no'  => 'required|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        // Get compulsory subjects from subject_selections
        $compulsory = DB::table('subject_selections')
            ->where('program_id', $req->program_id)
            ->where('semester_no', $req->semester_no)
            ->where('is_compulsory', true)
            ->get();

        // Get all students in this class/semester
        $admissions = DB::table('admissions')
            ->where('program_id', $req->program_id)
            ->where('semester_no', $req->semester_no)
            ->pluck('id');

        $count = 0;
        foreach ($admissions as $admId) {
            foreach ($compulsory as $sub) {
                DB::table('student_subject_groups')->updateOrInsert(
                    ['admission_id' => $admId, 'subject_id' => $sub->subject_id,
                     'session_year' => $req->session_year, 'semester_no' => $req->semester_no],
                    ['group_no' => $sub->group_no, 'updated_at' => now(), 'created_at' => now()]
                );
                $count++;
            }
        }

        return response()->json(['message' => "{$count} subject groups auto-assigned."]);
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
}
