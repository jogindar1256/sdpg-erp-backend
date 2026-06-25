<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ApplicationController extends Controller
{
    private function sessionYear(): string
    {
        return request('session_year', date('Y') . '-' . (date('Y') + 1));
    }

    // ══════════════════════════════════════════════════════════════
    // 1. FRESH APPLICATIONS — Office Dashboard
    // ══════════════════════════════════════════════════════════════

    /**
     * List all fresh applications with filters
     */
    public function index(Request $req)
    {
        $q = DB::table('applications as ap')
            ->join('students as s', 's.id', 'ap.student_id')
            ->leftJoin('programs as p', 'p.id', 'ap.program_id')
            ->select(
                'ap.*',
                's.name',
                's.father_name',
                's.mother_name',
                's.mobile',
                's.gender',
                's.dob',
                's.category',
                'p.short_name as class',
                'p.full_name',
                'p.level'
            )
            ->where('ap.session_year', $req->session_year ?? $this->sessionYear())
            ->where('ap.form_type', 'Fresh')
            ->when($req->program_id, fn($q) => $q->where('ap.program_id', $req->program_id))
            ->when($req->exam_mode, fn($q) => $q->where('ap.exam_mode', $req->exam_mode))
            ->when($req->status, fn($q) => $q->where('ap.status', $req->status))
            ->when($req->search, fn($q) => $q->where(function ($q2) use ($req) {
                $q2->where('s.name', 'ilike', "%{$req->search}%")
                    ->orWhere('ap.application_no', 'ilike', "%{$req->search}%")
                    ->orWhere('ap.reg_no', 'ilike', "%{$req->search}%")
                    ->orWhere('s.mobile', 'ilike', "%{$req->search}%");
            }))
            ->orderByDesc('ap.created_at');

        return response()->json($q->paginate(50));
    }

    /**
     * Lookup student by search key (for semester upgrade / back paper form fill)
     */
    public function lookupStudent(Request $req)
    {
        $v = Validator::make($req->all(), [
            'search' => 'required|string|min:3',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $s = $req->search;
        $row = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->leftJoin('applications as ap', function ($j) {
                $j->on('ap.student_id', 's.id')->where('ap.form_type', 'Fresh');
            })
            ->where(function ($q) use ($s) {
                $q->where('a.roll_no', $s)
                    ->orWhere('a.enrollment_no', $s)
                    ->orWhere('a.account_no', $s)
                    ->orWhere('s.mobile', $s)
                    ->orWhere('s.aadhar_no', $s)
                    ->orWhere('ap.application_no', $s);
            })
            ->select(
                'a.*',
                's.name',
                's.father_name',
                's.mother_name',
                's.spouse_name',
                's.mobile',
                's.gender',
                's.dob',
                's.category',
                's.domestic_state',
                's.aadhar_no',
                's.abc_id',
                's.ddurn',
                'p.short_name as class',
                'p.full_name',
                'p.level',
                'ap.application_no',
                'ap.reg_no',
                'ap.status as app_status',
                'ap.cgpa',
                'ap.result',
                'ap.tc_status',
                'ap.migration_status'
            )
            ->first();

        if (!$row)
            return response()->json(['message' => 'Student not found.'], 404);

        // Get selected subjects for display
        $subjects = DB::table('application_subjects as asub')
            ->join('subjects as sub', 'sub.id', 'asub.subject_id')
            ->leftJoin('subject_papers as sp', 'sp.id', 'asub.paper_id')
            ->where('asub.admission_id', $row->id)
            ->select('asub.*', 'sub.name as subject_name', 'sp.paper_code', 'sp.paper_name')
            ->get();

        return response()->json(array_merge((array) $row, ['subjects' => $subjects]));
    }

    // ══════════════════════════════════════════════════════════════
    // 2. APPLICATION HOLD / RELEASE
    // ══════════════════════════════════════════════════════════════

    public function holdIndex(Request $req)
    {
        $q = DB::table('applications as ap')
            ->join('students as s', 's.id', 'ap.student_id')
            ->leftJoin('programs as p', 'p.id', 'ap.program_id')
            ->where('ap.status', 'Hold')
            ->when($req->search, fn($q) => $q->where(function ($q2) use ($req) {
                $q2->where('ap.application_no', 'ilike', "%{$req->search}%")
                    ->orWhere('ap.reg_no', 'ilike', "%{$req->search}%")
                    ->orWhere('s.name', 'ilike', "%{$req->search}%");
            }))
            ->select(
                'ap.*',
                's.name',
                's.father_name',
                's.mobile',
                'p.short_name as class',
                'p.full_name'
            )
            ->orderByDesc('ap.updated_at');

        return response()->json($q->paginate(50));
    }

    public function holdStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'application_id' => 'required|exists:applications,id',
            'hold_type' => 'required|string',
            'reason' => 'required|string|min:20',
            'submitted_by' => 'required|string',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        DB::table('application_holds')->insert([
            'application_id' => $req->application_id,
            'hold_type' => $req->hold_type,
            'reason' => $req->reason,
            'objections' => $req->objections ?? null,
            'submitted_by' => $req->submitted_by,
            'submitted_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('applications')->where('id', $req->application_id)
            ->update(['status' => 'Hold', 'updated_at' => now()]);

        return response()->json(['message' => 'Application placed on hold.']);
    }

    public function holdRelease(Request $req, $id)
    {
        DB::table('applications')->where('id', $id)
            ->update(['status' => 'Pending', 'updated_at' => now()]);

        return response()->json(['message' => 'Hold released.']);
    }

    // ══════════════════════════════════════════════════════════════
    // 3. BACK PAPER APPLICATION
    // ══════════════════════════════════════════════════════════════

    public function backPaperIndex(Request $req)
    {
        $q = DB::table('applications as ap')
            ->join('students as s', 's.id', 'ap.student_id')
            ->leftJoin('programs as p', 'p.id', 'ap.program_id')
            ->where('ap.form_type', 'BackPaper')
            ->where('ap.session_year', $req->session_year ?? $this->sessionYear())
            ->when($req->program_id, fn($q) => $q->where('ap.program_id', $req->program_id))
            ->when($req->semester_no, fn($q) => $q->where('ap.semester_no', $req->semester_no))
            ->when($req->status, fn($q) => $q->where('ap.status', $req->status))
            ->when($req->search, fn($q) => $q->where(function ($q2) use ($req) {
                $q2->where('s.name', 'ilike', "%{$req->search}%")
                    ->orWhere('a.roll_no', 'ilike', "%{$req->search}%");
            }))
            ->select(
                'ap.*',
                's.name',
                's.father_name',
                's.mobile',
                's.gender',
                'p.short_name as class'
            )
            ->orderByDesc('ap.created_at');

        return response()->json($q->paginate(50));
    }

    /**
     * Get back paper eligible papers for a student
     */
    public function backPaperPapers(Request $req)
    {
        $v = Validator::make($req->all(), [
            'admission_id' => 'required',
            'semester_no' => 'required',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        // Get papers for the given admission's program + semester from subject_papers
        $admission = DB::table('admissions')->find($req->admission_id);
        if (!$admission)
            return response()->json(['message' => 'Admission not found.'], 404);

        $papers = DB::table('subject_papers as sp')
            ->join('subjects as sub', 'sub.id', 'sp.subject_id')
            ->join('allotted_subjects as als', function ($j) use ($admission) {
                $j->on('als.subject_id', 'sub.id')
                    ->where('als.program_id', $admission->program_id);
            })
            ->where('sp.semester_no', $req->semester_no)
            ->select('sp.*', 'sub.name as subject_name')
            ->get();

        return response()->json($papers);
    }

    public function backPaperStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'admission_id' => 'required|exists:admissions,id',
            'session_year' => 'required|string',
            'semester_no' => 'required|string',
            'back_semester' => 'required|string',
            'exam_mode' => 'required|in:Regular,Private',
            'paper_ids' => 'required|array|min:1',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        // Get student_id from admission
        $admission = DB::table('admissions')->find($req->admission_id);

        // Create application
        $appNo = 'BP' . date('Y') . str_pad(DB::table('applications')->count() + 1, 6, '0', STR_PAD_LEFT);

        $appId = DB::table('applications')->insertGetId([
            'student_id' => $admission->student_id,
            'program_id' => $admission->program_id,
            'session_year' => $req->session_year,
            'semester_no' => $req->semester_no,
            'back_semester' => $req->back_semester,
            'exam_mode' => $req->exam_mode,
            'form_type' => 'BackPaper',
            'application_no' => $appNo,
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attach selected papers
        foreach ($req->paper_ids as $paperId) {
            DB::table('application_subjects')->insert([
                'application_id' => $appId,
                'admission_id' => $req->admission_id,
                'paper_id' => $paperId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['id' => $appId, 'application_no' => $appNo, 'message' => 'Back paper application saved.'], 201);
    }

    // ══════════════════════════════════════════════════════════════
    // 4. SEMESTER UPGRADE APPLICATION
    // ══════════════════════════════════════════════════════════════

    public function upgradeIndex(Request $req)
    {
        $q = DB::table('applications as ap')
            ->join('students as s', 's.id', 'ap.student_id')
            ->leftJoin('programs as p', 'p.id', 'ap.program_id')
            ->where('ap.form_type', 'Upgrade')
            ->where('ap.session_year', $req->session_year ?? $this->sessionYear())
            ->when($req->program_id, fn($q) => $q->where('ap.program_id', $req->program_id))
            ->when($req->level, fn($q) => $q->where('p.level', $req->level))
            ->when($req->status, fn($q) => $q->where('ap.status', $req->status))
            ->when($req->search, fn($q) => $q->where(function ($q2) use ($req) {
                $q2->where('s.name', 'ilike', "%{$req->search}%")
                    ->orWhere('ap.application_no', 'ilike', "%{$req->search}%");
            }))
            ->select(
                'ap.*',
                's.name',
                's.father_name',
                's.mother_name',
                's.spouse_name',
                's.mobile',
                's.gender',
                's.dob',
                's.category',
                's.domestic_state',
                's.aadhar_no',
                's.abc_id',
                's.ddurn',
                's.enrollment_no',
                'p.short_name as class',
                'p.full_name',
                'p.level'
            )
            ->orderByDesc('ap.created_at');

        return response()->json($q->paginate(50));
    }

    public function upgradeStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'admission_id' => 'required|exists:admissions,id',
            'session_year' => 'required|string',
            'semester_no' => 'required|string',
            'exam_mode' => 'required|in:Regular,Private',
            'cgpa' => 'nullable|numeric',
            'result' => 'nullable|string',
            'aadhar_no' => 'nullable|string',
            'abc_id' => 'nullable|string',
            'ddurn' => 'nullable|string',
            'enrollment_no' => 'nullable|string',
            'selected_subjects' => 'nullable|array',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $admission = DB::table('admissions')->find($req->admission_id);

        // Update student fields if provided
        $studentUpdate = array_filter([
            'aadhar_no' => $req->aadhar_no,
            'abc_id' => $req->abc_id,
            'ddurn' => $req->ddurn,
            'enrollment_no' => $req->enrollment_no,
        ]);
        if ($studentUpdate) {
            DB::table('students')->where('id', $admission->student_id)
                ->update(array_merge($studentUpdate, ['updated_at' => now()]));
        }

        $appNo = 'UP' . date('Y') . str_pad(DB::table('applications')->count() + 1, 6, '0', STR_PAD_LEFT);

        $appId = DB::table('applications')->insertGetId([
            'student_id' => $admission->student_id,
            'program_id' => $admission->program_id,
            'session_year' => $req->session_year,
            'semester_no' => $req->semester_no,
            'exam_mode' => $req->exam_mode,
            'form_type' => 'Upgrade',
            'application_no' => $appNo,
            'cgpa' => $req->cgpa,
            'result' => $req->result,
            'tc_status' => $req->tc_status,
            'migration_status' => $req->migration_status,
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attach selected subjects/papers
        if ($req->selected_subjects) {
            foreach ($req->selected_subjects as $sub) {
                DB::table('application_subjects')->insert([
                    'application_id' => $appId,
                    'admission_id' => $req->admission_id,
                    'subject_id' => $sub['subject_id'] ?? null,
                    'paper_id' => $sub['paper_id'] ?? null,
                    'subject_type' => $sub['subject_type'] ?? 'Major',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return response()->json(['id' => $appId, 'application_no' => $appNo, 'message' => 'Upgrade application saved.'], 201);
    }

    public function upgradeUpdateStatus(Request $req, $id)
    {
        $v = Validator::make($req->all(), [
            'status' => 'required|in:Pending,Approved,Rejected,Hold',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        DB::table('applications')->where('id', $id)
            ->update(['status' => $req->status, 'updated_at' => now()]);

        return response()->json(['message' => 'Status updated.']);
    }

    // ══════════════════════════════════════════════════════════════
    // 5. REGISTRATION FORM STATUS
    // ══════════════════════════════════════════════════════════════

    public function registrationFormStatus(Request $req)
    {
        $sessionYear = $req->session_year ?? $this->sessionYear();

        $data = DB::table('programs as p')
            ->leftJoin('admissions as a', 'a.program_id', 'p.id')
            ->leftJoin('applications as ap', function ($j) use ($sessionYear, $req) {
                $j->on('ap.student_id', 'a.student_id')
                    ->where('ap.session_year', $sessionYear)
                    ->where('ap.form_type', 'Fresh')
                    ->when($req->exam_mode, fn($j2) => $j2->where('ap.exam_mode', $req->exam_mode));
            })
            ->when($req->semester_no, fn($q) => $q->where('a.semester_no', $req->semester_no))
            ->select(
                'p.id as program_id',
                'p.short_name as class',
                'p.level',
                DB::raw('COUNT(DISTINCT a.id) as total_admitted'),
                DB::raw("COUNT(DISTINCT CASE WHEN ap.status = 'Approved' THEN ap.id END) as approved"),
                DB::raw("COUNT(DISTINCT CASE WHEN ap.status = 'Pending' THEN ap.id END) as pending"),
                DB::raw("COUNT(DISTINCT CASE WHEN ap.status = 'Hold' THEN ap.id END) as hold"),
                DB::raw("COUNT(DISTINCT CASE WHEN ap.id IS NULL THEN a.id END) as not_applied")
            )
            ->groupBy('p.id', 'p.short_name', 'p.level')
            ->orderBy('p.level')->orderBy('p.short_name')
            ->get();

        return response()->json($data);
    }

    // ══════════════════════════════════════════════════════════════
    // 6. DOCUMENT UPLOAD
    // ══════════════════════════════════════════════════════════════

    public function uploadDocument(Request $req)
    {
        $v = Validator::make($req->all(), [
            'application_id' => 'required|exists:applications,id',
            'enclosure_no' => 'required|string',
            'document_name' => 'required|string',
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $path = $req->file('file')->store("applications/{$req->application_id}/documents", 'public');

        DB::table('application_documents')->updateOrInsert(
            ['application_id' => $req->application_id, 'enclosure_no' => $req->enclosure_no],
            [
                'document_name' => $req->document_name,
                'file_path' => $path,
                'status' => 'Uploaded',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json(['message' => 'Document uploaded.', 'path' => $path]);
    }

    public function deleteDocument($id)
    {
        $doc = DB::table('application_documents')->find($id);
        if ($doc) {
            Storage::disk('public')->delete($doc->file_path);
            DB::table('application_documents')->delete($id);
        }
        return response()->json(['message' => 'Deleted.']);
    }

    //student side

    public function store(Request $request)
    {
        $request->validate([
            'program_id' => 'required|exists:programs,id',
            'academic_year' => 'required|string|max:10',
            'application_type' => 'required|in:fresh,back_paper,semester_upgrade,lateral',
            'semester_no' => 'required|integer|min:1|max:12',
        ]);

        $user = $request->user();
        $student = \DB::table('students')->where('user_id', $user->id)->first();

        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $existing = \DB::table('student_applications')
            ->where('student_id', $student->id)
            ->where('program_id', $request->program_id)
            ->where('academic_year', $request->academic_year)
            ->where('application_type', $request->application_type)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'You already have an active application for this program and year.',
                'id' => $existing->id,
            ], 409);
        }

        $seq = \DB::table('student_applications')->count() + 1;
        $appNo = 'SA-' . date('Y') . '-' . str_pad($seq, 6, '0', STR_PAD_LEFT);

        $id = \DB::table('student_applications')->insertGetId([
            'organization_id' => $user->organization_id,
            'student_id' => $student->id,
            'program_id' => $request->program_id,
            'academic_year' => $request->academic_year,
            'application_type' => $request->application_type,
            'semester_no' => $request->semester_no,
            'application_no' => $appNo,
            'status' => 'draft',
            'form_progress' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'id' => $id,
            'application_no' => $appNo,
            'message' => 'Application draft created.',
        ], 201);
    }

    // ══════════════════════════════════════════════════════════════════════════════
// myApplications() — GET /student/applications
// ══════════════════════════════════════════════════════════════════════════════
    public function myApplications(Request $request)
    {
        $user = $request->user();
        $student = \DB::table('students')->where('user_id', $user->id)->first();

        if (!$student) {
            return response()->json(['data' => []]);
        }

        $apps = \DB::table('student_applications as sa')
            ->join('programs as p', 'p.id', '=', 'sa.program_id')
            ->where('sa.student_id', $student->id)
            ->select([
                'sa.id',
                'sa.application_no',
                'sa.academic_year',
                'sa.application_type',
                'sa.semester_no',
                'sa.status',
                'sa.form_progress',
                'sa.rejection_reason',
                'sa.remarks',
                'sa.created_at',
                'sa.updated_at',
                'p.name as program_name',
                'p.short_name',
                'p.level',
            ])
            ->orderByDesc('sa.created_at')
            ->get()
            ->map(function ($app) {
                $app->form_progress = json_decode($app->form_progress ?? '{}', true);
                return $app;
            });

        return response()->json(['data' => $apps]);
    }

    // ══════════════════════════════════════════════════════════════════════════════
// show() — GET /student/applications/{id}   REPLACES the existing show()
//
// Returns a FLAT object so the frontend can access fields directly:
//   application.form_progress
//   application.part_1  (parsed object, not JSON string)
//   application.student (student profile sub-object)
//   application.program (program sub-object)
//
// Falls back to college-side `applications` table for office use.
// ══════════════════════════════════════════════════════════════════════════════
    public function show($id)
    {
        // ── Student application path ──────────────────────────────────────────────
        $sa = \DB::table('student_applications')->where('id', $id)->first();

        if ($sa) {
            // Parse all JSONB columns so frontend receives objects, not strings
            $jsonColumns = [
                'form_progress',
                'part_1',
                'part_2',
                'part_3',
                'part_4',
                'part_5',
                'part_6',
                'part_7',
                'part_8',
                'selected_subjects',
                'selected_optional_subjects'
            ];

            foreach ($jsonColumns as $col) {
                if (isset($sa->$col) && is_string($sa->$col)) {
                    $sa->$col = json_decode($sa->$col, true);
                }
            }

            // Attach student profile as sub-object
            $sa->student = \DB::table('students')->where('id', $sa->student_id)->first();

            // Attach program as sub-object
            $sa->program = \DB::table('programs')->where('id', $sa->program_id)->first();

            // Return FLAT — frontend accesses fields directly on this object
            return response()->json($sa);
        }

        // ── College application path (old table, office side) ─────────────────────
        $app = \DB::table('applications as ap')
            ->join('students as s', 's.id', 'ap.student_id')
            ->leftJoin('programs as p', 'p.id', 'ap.program_id')
            ->where('ap.id', $id)
            ->select(
                'ap.*',
                's.*',
                'p.short_name as class',
                'p.full_name',
                'p.level',
                's.name as student_name'
            )
            ->first();

        if (!$app) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $education = \DB::table('application_education')->where('application_id', $id)->get();
        $tc = \DB::table('application_tc')->where('application_id', $id)->first();
        $migration = \DB::table('application_migration')->where('application_id', $id)->first();
        $bank = \DB::table('application_bank')->where('application_id', $id)->first();
        $subjects = \DB::table('application_subjects')->where('application_id', $id)->get();
        $documents = \DB::table('application_documents')->where('application_id', $id)->get();

        return response()->json(compact('app', 'education', 'tc', 'migration', 'bank', 'subjects', 'documents'));
    }

    // ══════════════════════════════════════════════════════════════════════════════
// updatePart() — PUT /student/applications/{id}/part/{part}
// Part is a number 1–8. Saves to part_1 … part_8 JSONB columns.
// Marks form_progress.part1 … part8 = true.
// ══════════════════════════════════════════════════════════════════════════════
    public function updatePart(Request $request, $id, $part)
    {
        $partNo = (int) $part;

        if ($partNo < 1 || $partNo > 8) {
            return response()->json(['message' => "Invalid part number: {$part}. Must be 1–8."], 422);
        }

        $user = $request->user();
        $student = \DB::table('students')->where('user_id', $user->id)->first();

        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $app = \DB::table('student_applications')
            ->where('id', $id)
            ->where('student_id', $student->id)
            ->first();

        if (!$app) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        if (in_array($app->status, ['submitted', 'approved'])) {
            return response()->json(['message' => 'Application is already submitted and cannot be edited.'], 422);
        }

        $columnName = 'part_' . $partNo;            // part_1, part_2 …
        $progressKey = 'part' . $partNo;             // part1, part2 … (matches frontend)

        $progress = json_decode($app->form_progress ?? '{}', true);
        $progress[$progressKey] = true;

        \DB::table('student_applications')->where('id', $id)->update([
            $columnName => json_encode($request->all()),
            'form_progress' => json_encode($progress),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => "Part {$partNo} saved successfully.",
            'form_progress' => $progress,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════════
// submit() — POST /student/applications/{id}/submit
// ══════════════════════════════════════════════════════════════════════════════
    public function submit(Request $request, $id)
    {
        $request->validate(['declaration_accepted' => 'required|accepted']);

        $user = $request->user();
        $student = \DB::table('students')->where('user_id', $user->id)->first();

        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $app = \DB::table('student_applications')
            ->where('id', $id)
            ->where('student_id', $student->id)
            ->first();

        if (!$app) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        if ($app->status !== 'draft') {
            return response()->json([
                'message' => "Application is already {$app->status}.",
            ], 422);
        }

        \DB::table('student_applications')->where('id', $id)->update([
            'status' => 'submitted',
            'declaration_accepted' => true,
            'declaration_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Application submitted successfully.',
            'application_no' => $app->application_no,
            'status' => 'submitted',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════════
// uploadStudentDocument() — POST /student/applications/{id}/documents
// Stores one document file and returns its URL.
// Part 7 (Documents) calls this per file.
// ══════════════════════════════════════════════════════════════════════════════
    public function uploadStudentDocument(Request $request, $id)
    {
        $request->validate([
            'file' => 'required|file|max:2048|mimes:jpg,jpeg,png,pdf',
            'document_type' => 'required|string|max:50',
        ]);

        $user = $request->user();
        $student = \DB::table('students')->where('user_id', $user->id)->first();

        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $app = \DB::table('student_applications')
            ->where('id', $id)
            ->where('student_id', $student->id)
            ->first();

        if (!$app) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $docType = $request->input('document_type');
        $path = $request->file('file')->store(
            "student-applications/{$id}",
            'public'
        );

        // Store record in student_application_documents
        \DB::table('student_application_documents')->updateOrInsert(
            ['application_id' => $id, 'document_type' => $docType],
            [
                'path' => $path,
                'filename' => $request->file('file')->getClientOriginalName(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'url' => \Storage::url($path),
            'filename' => $request->file('file')->getClientOriginalName(),
        ]);
    }


}
