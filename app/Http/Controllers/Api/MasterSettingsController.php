<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

use App\Models\Program;
use App\Models\Subject;
use App\Models\FeeHead;
use App\Models\FeeStructure;

class MasterSettingsController extends Controller
{
    // ══════════════════════════════════════════════════════════════════
    // ADMISSION SETTINGS
    // ══════════════════════════════════════════════════════════════════

    // 1. Application Schedule ─────────────────────────────────────────
    public function applicationScheduleIndex(Request $req)
    {
        $data = DB::table('application_schedules as s')
            ->join('programs as p', 'p.id', 's.program_id')
            ->select('s.*', 'p.short_name as class', 'p.full_name')
            ->when($req->session_year, fn($q) => $q->where('s.session_year', $req->session_year))
            ->orderBy('s.created_at', 'desc')
            ->paginate(20);
        return response()->json($data);
    }

    public function applicationScheduleStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'session_year' => 'required|string',
            'semester_name' => 'required|string',
            'semester_no' => 'required|string',
            'exam_mode' => 'required|in:Regular,Back Paper,Upgrade',
            'start_admission' => 'required|date',
            'close_admission' => 'required|date|after:start_admission',
            'late_fee_applicable' => 'required|boolean',
            'late_fee' => 'nullable|numeric|min:0',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $rec = DB::table('application_schedules')->insertGetId(array_merge($req->only([
            'program_id',
            'session_year',
            'semester_name',
            'semester_no',
            'exam_mode',
            'start_admission',
            'close_admission',
            'late_fee_applicable',
            'late_fee',
        ]), ['created_at' => now(), 'updated_at' => now()]));

        return response()->json(['id' => $rec, 'message' => 'Schedule saved.'], 201);
    }

    public function applicationScheduleUpdate(Request $req, $id)
    {
        DB::table('application_schedules')->where('id', $id)->update(array_merge(
            $req->only([
                'program_id',
                'session_year',
                'semester_name',
                'semester_no',
                'exam_mode',
                'start_admission',
                'close_admission',
                'late_fee_applicable',
                'late_fee'
            ]),
            ['updated_at' => now()]
        ));
        return response()->json(['message' => 'Updated.']);
    }

    public function applicationScheduleDestroy($id)
    {
        DB::table('application_schedules')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 2. Admission Condition ──────────────────────────────────────────
    public function admissionConditionIndex(Request $req)
    {
        // Previously selected admission_conditions.* only, with no join to
        // programs — so the "Class" column the frontend tries to render
        // was always empty even though program_id was saved correctly.
        return response()->json(
            DB::table('admission_conditions as ac')
                ->join('programs as p', 'p.id', 'ac.program_id')
                ->select('ac.*', 'p.short_name as class', 'p.full_name', 'p.name as program_name')
                ->when($req->program_id, fn($q) => $q->where('ac.program_id', $req->program_id))
                ->when($req->session_year, fn($q) => $q->where('ac.session_year', $req->session_year))
                ->orderByDesc('ac.id')
                ->get()
        );
    }

    public function admissionConditionStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'session_year' => 'required|string',
            'semester_no' => 'required|string',
            'qualifying_class' => 'required|string',
            'condition_type' => 'required|in:Open Admission,Through Counselling,Cut Off Merit List,Out Of Merit List',
            'allotted_seat' => 'required|integer',
            'required_percent_gen' => 'required|numeric',
            'required_percent_obc' => 'required|numeric',
            'required_percent_sc' => 'required|numeric',
            'required_percent_st' => 'required|numeric',
            'required_percent_ews' => 'required|numeric',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        DB::table('admission_conditions')->updateOrInsert(
            ['program_id' => $req->program_id, 'session_year' => $req->session_year, 'semester_no' => $req->semester_no],
            array_merge($req->all(), ['updated_at' => now()])
        );
        return response()->json(['message' => 'Condition saved.']);
    }

    // 3. Enclosure Master ─────────────────────────────────────────────

    public function enclosureMasterIndex(Request $req)
    {
        return response()->json(
            DB::table('enclosure_masters')
                ->when($req->program_id, fn($q) => $q->where('program_id', $req->program_id))
                ->when($req->semester_no, fn($q) => $q->where('semester_no', $req->semester_no))
                ->when($req->admission_mode, fn($q) => $q->where('admission_mode', $req->admission_mode))
                ->orderBy('document_name')
                ->get()
        );
    }

    public function enclosureMasterStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'semester_no' => 'required|string',
            'admission_mode' => 'required|string',
            'document_name' => 'required|string|max:255',
            'is_required' => 'required|boolean',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $keys = [
            'program_id' => $req->program_id,
            'semester_no' => $req->semester_no,
            'admission_mode' => $req->admission_mode,
            'document_name' => trim($req->document_name),
        ];

        // Idempotent: same document for the same class+semester+mode updates the
        // existing row instead of inserting a duplicate.
        DB::table('enclosure_masters')->updateOrInsert(
            $keys,
            ['is_required' => $req->boolean('is_required'), 'updated_at' => now()]
        );

        return response()->json(['message' => 'Document saved.'], 201);
    }
    
    public function enclosureMasterDestroy($id)
    {
        DB::table('enclosure_masters')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    public function enclosureMasterBulkStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'semester_no' => 'required|string',
            'admission_mode' => 'required|string',
            'rows' => 'required|array',
            'rows.*.document_name' => 'required|string|max:255',
            'rows.*.condition' => 'nullable|string|max:20',
            'rows.*.enclose' => 'nullable|boolean',
            'rows.*.scan_copy' => 'nullable|boolean',
            'rows.*.photo_count' => 'nullable|string|max:5',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        foreach ($req->rows as $row) {
            DB::table('enclosure_masters')->updateOrInsert(
                [
                    'program_id' => $req->program_id,
                    'semester_no' => $req->semester_no,
                    'admission_mode' => $req->admission_mode,
                    'document_name' => trim($row['document_name']),
                ],
                [
                    'condition' => $row['condition'] ?? null,
                    'enclose' => !empty($row['enclose']),
                    'scan_copy' => !empty($row['scan_copy']),
                    'photo_count' => $row['photo_count'] ?? null,
                    'is_required' => (($row['condition'] ?? '') === 'Mandatory'),
                    'updated_at' => now(),
                ]
            );
        }

        return response()->json(['message' => 'Saved ' . count($req->rows) . ' document rule(s).']);
    }

    // 4. Fee Head Master ──────────────────────────────────────────────
    public function feeHeadIndex()
    {
        return response()->json(FeeHead::orderBy('name')->get());
    }

    public function feeHeadStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'name' => 'required|string|max:255|unique:fee_heads,name',
            'in_favor_of' => 'required|in:College,University,Government',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $fh = FeeHead::create($req->only(['name', 'in_favor_of']));
        return response()->json($fh, 201);
    }

    public function feeHeadUpdate(Request $req, $id)
    {
        FeeHead::findOrFail($id)->update($req->only(['name', 'in_favor_of']));
        return response()->json(['message' => 'Updated.']);
    }

    public function feeHeadDestroy($id)
    {
        FeeHead::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 5. Fee Structure ────────────────────────────────────────────────
    // fee_structures real columns (2024_01_01_000005_create_fee_structures_table.php,
    // plus amounts/term added by 2026_07_21_100000_fix_master_settings_schema_gaps.php):
    // organization_id, program_id, fee_head_id, semester_no, academic_year,
    // admission_type (enum regular|back_paper|upgrade|lateral), amount (legacy,
    // unused single scalar), late_fine_per_day, due_date, is_active, amounts
    // (json — per-gender/category grid, same "{gender}_{category}" key
    // convention as registration_fees), term. There was NO session_year,
    // exam_mode, for_sdpg_passout, for_ddu_passout — the store/index/copy
    // methods here were reading/writing columns that never existed, so this
    // endpoint 500'd on every call.
    //
    // The frontend's "session_year"/"exam_mode" filters map onto
    // academic_year/admission_type; "exam_mode" values ('Regular','Back
    // Paper','Upgrade') map onto the lowercase admission_type enum.
    private const EXAM_MODE_TO_ADMISSION_TYPE = [
        'Regular' => 'regular',
        'Back Paper' => 'back_paper',
        'Upgrade' => 'upgrade',
        'Lateral' => 'lateral',
    ];

    public function feeStructureIndex(Request $req)
    {
        $admissionType = self::EXAM_MODE_TO_ADMISSION_TYPE[$req->exam_mode] ?? $req->exam_mode;

        return response()->json(
            DB::table('fee_structures as fs')
                ->join('programs as p', 'p.id', 'fs.program_id')
                ->join('fee_heads as fh', 'fh.id', 'fs.fee_head_id')
                ->select('fs.*', 'p.short_name as class', 'fh.name as fee_head', 'fh.in_favor_of')
                ->when($req->program_id, fn($q) => $q->where('fs.program_id', $req->program_id))
                ->when($req->session_year, fn($q) => $q->where('fs.academic_year', $req->session_year))
                ->when($req->semester_no, fn($q) => $q->where('fs.semester_no', $req->semester_no))
                ->when($admissionType, fn($q) => $q->where('fs.admission_type', $admissionType))
                ->orderBy('fh.name')
                ->get()
        );
    }

    public function feeStructureStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'fee_head_id' => 'required|exists:fee_heads,id',
            'session_year' => 'required|string',
            'semester_no' => 'required|string',
            'exam_mode' => 'required|in:Regular,Back Paper,Upgrade,Lateral',
            'term' => 'required|in:Admission,Semester Registration',
            'amounts' => 'required|array',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $orgId = DB::table('programs')->where('id', $req->program_id)->value('organization_id');

        DB::table('fee_structures')->updateOrInsert(
            [
                'program_id' => $req->program_id,
                'fee_head_id' => $req->fee_head_id,
                'academic_year' => $req->session_year,
                'semester_no' => $req->semester_no,
                'admission_type' => self::EXAM_MODE_TO_ADMISSION_TYPE[$req->exam_mode] ?? 'regular',
            ],
            [
                'organization_id' => $orgId,
                'term' => $req->term,
                'amounts' => json_encode($req->amounts),
                'amount' => (float) array_sum($req->amounts), // legacy scalar column kept in sync, unused by this UI
                'updated_at' => now(),
            ]
        );
        return response()->json(['message' => 'Fee structure saved.']);
    }

    public function feeStructureCopyYear(Request $req)
    {
        $v = Validator::make($req->all(), [
            'from_year' => 'required|string',
            'to_year' => 'required|string',
            'program_id' => 'required|exists:programs,id',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $rows = DB::table('fee_structures')
            ->where('academic_year', $req->from_year)
            ->where('program_id', $req->program_id)
            ->get();

        foreach ($rows as $r) {
            DB::table('fee_structures')->updateOrInsert(
                [
                    'program_id' => $r->program_id,
                    'fee_head_id' => $r->fee_head_id,
                    'academic_year' => $req->to_year,
                    'semester_no' => $r->semester_no,
                    'admission_type' => $r->admission_type,
                ],
                [
                    'organization_id' => $r->organization_id,
                    'term' => $r->term,
                    'amounts' => $r->amounts,
                    'amount' => $r->amount,
                    'updated_at' => now(),
                ]
            );
        }
        return response()->json(['message' => "Copied {$req->from_year} → {$req->to_year}."]);
    }

    public function registrationFeeCopyYear(Request $req)
    {
        $v = Validator::make($req->all(), [
            'from_year' => 'required|string',
            'to_year' => 'required|string|different:from_year',
            'program_id' => 'nullable|exists:programs,id',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $rows = DB::table('registration_fees')
            ->where('session_year', $req->from_year)
            ->when($req->program_id, fn($q) => $q->where('program_id', $req->program_id))
            ->get();

        if ($rows->isEmpty()) {
            return response()->json([
                'message' => "No registration fees found for {$req->from_year}" .
                    ($req->program_id ? ' for this class.' : '.'),
            ], 404);
        }

        $count = 0;
        foreach ($rows as $r) {
            DB::table('registration_fees')->updateOrInsert(
                [
                    'program_id' => $r->program_id,
                    'session_year' => $req->to_year,
                    'semester_no' => $r->semester_no,
                    'registration_mode' => $r->registration_mode,
                ],
                ['amounts' => $r->amounts, 'updated_at' => now()]
            );
            $count++;
        }

        return response()->json([
            'message' => "Copied {$count} registration fee row(s): {$req->from_year} → {$req->to_year}.",
        ]);
    }

    // 6. Registration Fee ─────────────────────────────────────────────
    public function registrationFeeIndex(Request $req)
    {
        return response()->json(
            DB::table('registration_fees')
                ->when($req->program_id, fn($q) => $q->where('program_id', $req->program_id))
                ->when($req->session_year, fn($q) => $q->where('session_year', $req->session_year))
                ->get()
        );
    }

    public function registrationFeeStore(Request $req)
    {
        DB::table('registration_fees')->updateOrInsert(
            [
                'program_id' => $req->program_id,
                'session_year' => $req->session_year,
                'semester_no' => $req->semester_no,
                'registration_mode' => $req->registration_mode
            ],
            array_merge($req->only(['amounts']), ['updated_at' => now()])
        );
        return response()->json(['message' => 'Registration fee saved.']);
    }

    public function registrationFeeDestroy($id)
    {
        DB::table('registration_fees')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 7. Back Paper Schedule ──────────────────────────────────────────
    public function backPaperScheduleIndex(Request $req)
    {
        return response()->json(
            DB::table('back_paper_schedules as b')
                ->join('programs as p', 'p.id', 'b.program_id')
                ->select('b.*', 'p.short_name as class_name')
                ->when($req->session_year, fn($q) => $q->where('b.session_year', $req->session_year))
                ->orderBy('b.created_at', 'desc')
                ->get()
        );
    }

    public function backPaperScheduleStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'semester' => 'required|string',
            'session_year' => 'required|string',
            'start_from' => 'required|date',
            'end_on' => 'required|date|after:start_from',
            'late_fee_applicable' => 'required|boolean',
            'late_fee' => 'nullable|numeric|min:0',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('back_paper_schedules')->insertGetId(array_merge($req->all(), [
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Back paper schedule saved.'], 201);
    }

    public function backPaperScheduleUpdate(Request $req, $id)
    {
        DB::table('back_paper_schedules')->where('id', $id)->update(array_merge(
            $req->only(['program_id', 'semester', 'session_year', 'start_from', 'end_on', 'late_fee_applicable', 'late_fee']),
            ['updated_at' => now()]
        ));
        return response()->json(['message' => 'Updated.']);
    }

    public function backPaperScheduleDestroy($id)
    {
        DB::table('back_paper_schedules')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // ══════════════════════════════════════════════════════════════════
    // COURSE SETTINGS
    // ══════════════════════════════════════════════════════════════════

    // 8. Class Master ─────────────────────────────────────────────────
    // programs real columns: organization_id, name, short_name, code (unique,
    // NOT NULL), level (enum UG|PG|BEd|Diploma|Certificate — note: 'BEd', not
    // 'B.Ed'), duration_years, total_semesters, semester_type, description,
    // is_active, full_name, course_code, is_self_finance, plus approval_type/
    // exam_mode added by 2026_07_21_100000_fix_master_settings_schema_gaps.php.
    //
    // The frontend sends 'B.Ed' (display label) not the DB enum 'BEd', and
    // sends 'status' (Active/Inactive) which maps onto the real is_active
    // boolean, and 'approval_type' Self Finance/Under Finance which also
    // drives the real is_self_finance boolean. It never collects `code` or
    // `organization_id` — both are required NOT NULL columns, so Class
    // Master's create previously threw a DB error on every single save
    // (Program::create() was also missing 'name' entirely, and $fillable
    // didn't even include full_name/approval_type/exam_mode, so those were
    // silently dropped by mass assignment even before hitting the DB).
    private const CLASS_LEVEL_TO_ENUM = ['B.Ed' => 'BEd'];

    public function classMasterIndex()
    {
        return response()->json(
            Program::orderBy('short_name')->get()->map(function ($p) {
                $p->status = $p->is_active ? 'Active' : 'Inactive';
                return $p;
            })
        );
    }

    public function classMasterStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'level' => 'required|in:UG,PG,B.Ed,BEd,Diploma,Certificate',
            'approval_type' => 'required|in:Under Finance,Self Finance',
            'short_name' => 'required|string|max:20|unique:programs,short_name',
            'full_name' => 'required|string|max:255',
            'duration_years' => 'required|integer|min:1',
            'exam_mode' => 'required|in:Regular,Back Paper',
            'total_semesters' => 'required|integer|min:1',
            'status' => 'required|in:Active,Inactive',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $orgId = DB::table('organizations')->where('is_active', true)->value('id');

        $p = Program::create([
            'organization_id' => $orgId,
            'name' => $req->full_name,
            'full_name' => $req->full_name,
            'short_name' => $req->short_name,
            'code' => $this->generateProgramCode($req->short_name),
            'level' => self::CLASS_LEVEL_TO_ENUM[$req->level] ?? $req->level,
            'duration_years' => $req->duration_years,
            'total_semesters' => $req->total_semesters,
            'approval_type' => $req->approval_type,
            'is_self_finance' => $req->approval_type === 'Self Finance',
            'exam_mode' => $req->exam_mode,
            'is_active' => $req->status === 'Active',
        ]);
        $p->status = $p->is_active ? 'Active' : 'Inactive';
        return response()->json($p, 201);
    }

    public function classMasterUpdate(Request $req, $id)
    {
        $v = Validator::make($req->all(), [
            'level' => 'required|in:UG,PG,B.Ed,BEd,Diploma,Certificate',
            'approval_type' => 'required|in:Under Finance,Self Finance',
            'short_name' => 'required|string|max:20|unique:programs,short_name,' . $id,
            'full_name' => 'required|string|max:255',
            'duration_years' => 'required|integer|min:1',
            'exam_mode' => 'required|in:Regular,Back Paper',
            'total_semesters' => 'required|integer|min:1',
            'status' => 'required|in:Active,Inactive',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        Program::findOrFail($id)->update([
            'name' => $req->full_name,
            'full_name' => $req->full_name,
            'short_name' => $req->short_name,
            'level' => self::CLASS_LEVEL_TO_ENUM[$req->level] ?? $req->level,
            'duration_years' => $req->duration_years,
            'total_semesters' => $req->total_semesters,
            'approval_type' => $req->approval_type,
            'is_self_finance' => $req->approval_type === 'Self Finance',
            'exam_mode' => $req->exam_mode,
            'is_active' => $req->status === 'Active',
        ]);
        return response()->json(['message' => 'Updated.']);
    }

    /** programs.code is unique + NOT NULL but never collected by the Class
     * Master form — derive a stable code from short_name and disambiguate
     * on collision. */
    private function generateProgramCode(string $shortName): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $shortName));
        $base = $base !== '' ? substr($base, 0, 15) : 'PRG';
        $code = $base;
        $i = 1;
        while (DB::table('programs')->where('code', $code)->exists()) {
            $i++;
            $code = substr($base, 0, 18) . $i;
        }
        return $code;
    }

    public function classMasterDestroy($id)
    {
        Program::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 9. Semester Master ──────────────────────────────────────────────
    public function semesterMasterIndex()
    {
        return response()->json(DB::table('semester_masters')->orderBy('semester_nos')->get());
    }

    public function semesterMasterStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'name' => 'required|string|max:50',
            'semester_nos' => 'required|string',
            'status' => 'required|in:Active,Inactive',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('semester_masters')->insertGetId(array_merge($req->only(['name', 'semester_nos', 'status']), [
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Semester saved.'], 201);
    }

    public function semesterMasterUpdate(Request $req, $id)
    {
        DB::table('semester_masters')->where('id', $id)->update(array_merge(
            $req->only(['name', 'semester_nos', 'status']),
            ['updated_at' => now()]
        ));
        return response()->json(['message' => 'Updated.']);
    }

    public function semesterMasterDestroy($id)
    {
        DB::table('semester_masters')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 10. Subject Master ──────────────────────────────────────────────
    public function subjectMasterIndex(Request $req)
    {
        return response()->json(
            Subject::with('program')
                ->when($req->program_id, fn($q) => $q->where('program_id', $req->program_id))
                ->orderBy('name')->get()
        );
    }

    // Subject Master.docx: this screen is subject-DETAIL info only — Subject
    // Name, Is Practical, Practical Fees Applicable, Permission Type,
    // Additional Fee Applicable, Fee Rs. It is NOT the place for exam-paper
    // fields (semester, marks, credits) — that's Subject Paper Master
    // (subjectPaperIndex/Store further down), which already has its own
    // per-session/semester subject_papers table for exactly that. The
    // `subjects` table still carries semester_no/type/max_marks/min_marks/
    // internal_marks/credits/code columns because other code paths
    // (Program::semesterSubjects(), exam controllers) depend on them, and
    // `code` is unique+NOT NULL — none of these are collected by this
    // simplified screen, so sane defaults/auto-generated values are used.
    public function subjectMasterStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'name' => 'required|string|max:255',
            'has_practical' => 'nullable|boolean',
            'practical_fee' => 'nullable|numeric|min:0',
            'permission_type' => 'required|in:Finance,Self Finance',
            'additional_fee_applicable' => 'nullable|boolean',
            'additional_fee' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $s = Subject::create([
            'program_id' => $req->program_id,
            'code' => $this->generateSubjectCode($req->program_id, $req->name),
            'name' => $req->name,
            'semester_no' => 1,           // not part of this screen — see comment above
            'type' => 'compulsory',       // not part of this screen — see comment above
            'paper_type' => $req->permission_type === 'Self Finance' ? 'self_finance' : 'regular',
            'is_active' => filter_var($req->is_active ?? true, FILTER_VALIDATE_BOOLEAN),
            'has_practical' => filter_var($req->has_practical ?? false, FILTER_VALIDATE_BOOLEAN),
            'practical_fee' => $req->has_practical ? $req->practical_fee : null,
            'additional_fee_applicable' => filter_var($req->additional_fee_applicable ?? false, FILTER_VALIDATE_BOOLEAN),
            'additional_fee' => $req->additional_fee_applicable ? $req->additional_fee : null,
        ]);
        return response()->json($s, 201);
    }

    public function subjectMasterUpdate(Request $req, $id)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'name' => 'required|string|max:255',
            'has_practical' => 'nullable|boolean',
            'practical_fee' => 'nullable|numeric|min:0',
            'permission_type' => 'required|in:Finance,Self Finance',
            'additional_fee_applicable' => 'nullable|boolean',
            'additional_fee' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        Subject::findOrFail($id)->update([
            'program_id' => $req->program_id,
            'name' => $req->name,
            'paper_type' => $req->permission_type === 'Self Finance' ? 'self_finance' : 'regular',
            'is_active' => filter_var($req->is_active ?? true, FILTER_VALIDATE_BOOLEAN),
            'has_practical' => filter_var($req->has_practical ?? false, FILTER_VALIDATE_BOOLEAN),
            'practical_fee' => $req->has_practical ? $req->practical_fee : null,
            'additional_fee_applicable' => filter_var($req->additional_fee_applicable ?? false, FILTER_VALIDATE_BOOLEAN),
            'additional_fee' => $req->additional_fee_applicable ? $req->additional_fee : null,
        ]);
        return response()->json(['message' => 'Updated.']);
    }

    /** subjects.code is unique + NOT NULL but this screen doesn't collect
     * one — derive from program short_name + subject name, disambiguate on
     * collision (same pattern as generateProgramCode()). */
    private function generateSubjectCode($programId, string $name): string
    {
        $shortName = DB::table('programs')->where('id', $programId)->value('short_name') ?? 'SUB';
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $shortName . '-' . $name));
        $base = $base !== '' ? substr($base, 0, 25) : 'SUBJECT';
        $code = $base;
        $i = 1;
        while (DB::table('subjects')->where('code', $code)->exists()) {
            $i++;
            $code = substr($base, 0, 28) . $i;
        }
        return $code;
    }

    public function subjectMasterDestroy($id)
    {
        Subject::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 11. Allotted Subject Master ─────────────────────────────────────
    public function allottedSubjectIndex(Request $req)
    {
        // Was missing p.level / p.approval_type — the frontend table renders
        // both columns but they always showed "-" since the query never
        // selected them.
        return response()->json(
            DB::table('allotted_subjects as a')
                ->join('programs as p', 'p.id', 'a.program_id')
                ->join('subjects as s', 's.id', 'a.subject_id')
                ->select(
                    'a.*', 'p.short_name as class', 'p.full_name', 'p.level', 'p.approval_type',
                    's.name as subject_name', 's.has_practical', 's.practical_fee'
                )
                ->when($req->program_id, fn($q) => $q->where('a.program_id', $req->program_id))
                ->get()
        );
    }

    public function allottedSubjectStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'subject_id' => 'required|exists:subjects,id',
            'permission_type' => 'required|in:Finance,Self Finance',
            'for_regular' => 'required|boolean',
            'for_private' => 'required|boolean',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('allotted_subjects')->insertGetId(array_merge($req->all(), [
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Allotted subject saved.'], 201);
    }

    public function allottedSubjectDestroy($id)
    {
        DB::table('allotted_subjects')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 12. Subject Paper Master ────────────────────────────────────────
    // subject_papers real columns: program_id, subject_id, session_year,
    // semester_no, paper_type, paper_name, group_no, max_marks, min_marks,
    // plus paper_code (added by 2026_07_22_090000_add_paper_code_to_subject_papers.php
    // — the mockup's "Paper Code" column had no backing column before this).
    /**
     * BSc is the only class with an elective "Group" concept (Maths Group,
     * Bio Group, etc. — several subjects bundled as one selectable combo).
     * Detection matches the short_name normalization already used by the
     * course_code backfill migration (strip non-letters, uppercase, look
     * for "BSC") since `level` alone is shared by every UG program (BA,
     * BCom, BSc all have level='UG').
     */
    private function programIsBsc($program): bool
    {
        if (!$program) return false;
        $norm = strtoupper(preg_replace('/[^A-Za-z]/', '', $program->short_name ?? ''));
        return str_contains($norm, 'BSC');
    }

    public function subjectPaperIndex(Request $req)
    {
        return response()->json(
            DB::table('subject_papers as sp')
                ->join('programs as p', 'p.id', 'sp.program_id')
                ->join('subjects as s', 's.id', 'sp.subject_id')
                ->select('sp.*', 'p.short_name as class', 's.name as subject_name')
                ->when($req->program_id, fn($q) => $q->where('sp.program_id', $req->program_id))
                ->when($req->semester_no, fn($q) => $q->where('sp.semester_no', $req->semester_no))
                ->when($req->session_year, fn($q) => $q->where('sp.session_year', $req->session_year))
                ->when($req->paper_type, fn($q) => $q->where('sp.paper_type', $req->paper_type))
                ->when($req->subject_id, fn($q) => $q->where('sp.subject_id', $req->subject_id))
                ->orderBy('sp.group_label')
                ->orderBy('sp.id')
                ->get()
        );
    }

    /**
     * Batch save — the Subject Paper Master screen lets the office fill
     * several Paper Code / Paper Name rows for one Class + Semester +
     * Paper Type + Subject in one go, then hit Save once.
     *
     * Group is only a real concept for BSc classes (elective combinations
     * like "Maths Group" / "Bio Group" spanning several subjects), and it
     * is a name the office chooses — never an auto-incrementing counter.
     * For every other class, group_label stays null and no grouping is
     * shown anywhere.
     */
    public function subjectPaperStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'subject_id' => 'required|exists:subjects,id',
            'session_year' => 'required|string',
            'semester_no' => 'required|string',
            'paper_type' => 'required|string',
            'group_label' => 'nullable|string|max:50',
            'papers' => 'required|array|min:1',
            'papers.*.paper_code' => 'nullable|string|max:50',
            'papers.*.paper_name' => 'required|string',
            'papers.*.max_marks' => 'nullable|integer|min:1',
            'papers.*.min_marks' => 'nullable|integer|min:0',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $program = DB::table('programs')->find($req->program_id);
        $isBsc = $this->programIsBsc($program);

        if ($isBsc && !$req->group_label) {
            return response()->json(['errors' => ['group_label' => ['Group Name is required for BSc electives.']]], 422);
        }
        $groupLabel = $isBsc ? trim($req->group_label) : null;

        $rows = [];
        foreach ($req->papers as $p) {
            $rows[] = [
                'program_id' => $req->program_id,
                'subject_id' => $req->subject_id,
                'session_year' => $req->session_year,
                'semester_no' => $req->semester_no,
                'paper_type' => $req->paper_type,
                'paper_code' => $p['paper_code'] ?? null,
                'paper_name' => $p['paper_name'],
                'group_no' => null,
                'group_label' => $groupLabel,
                'max_marks' => $p['max_marks'] ?? 100,
                'min_marks' => $p['min_marks'] ?? 33,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('subject_papers')->insert($rows);

        $msg = 'Saved ' . count($rows) . ' paper(s)' . ($groupLabel ? " under Group \"{$groupLabel}\"." : '.');
        return response()->json(['message' => $msg, 'group_label' => $groupLabel], 201);
    }

    public function subjectPaperUpdate(Request $req, $id)
    {
        $v = Validator::make($req->all(), [
            'paper_code' => 'nullable|string|max:50',
            'paper_name' => 'required|string',
            'group_label' => 'nullable|string|max:50',
            'max_marks' => 'nullable|integer|min:1',
            'min_marks' => 'nullable|integer|min:0',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $update = [
            'paper_code' => $req->paper_code,
            'paper_name' => $req->paper_name,
            'max_marks' => $req->max_marks ?? 100,
            'min_marks' => $req->min_marks ?? 33,
            'updated_at' => now(),
        ];
        if ($req->has('group_label')) {
            $update['group_label'] = $req->group_label ?: null;
        }

        DB::table('subject_papers')->where('id', $id)->update($update);
        return response()->json(['message' => 'Updated.']);
    }

    public function subjectPaperDestroy($id)
    {
        DB::table('subject_papers')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Real print — a college office needs an actual printable Subject Paper
     * Master sheet (Class / Semester / Session header, then Group 1 / 2 / 3
     * sections with Subject, Paper Code, Paper Name, Max, Min), not just a
     * browser print of the on-screen grid.
     */
    public function subjectPaperPrint(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'semester_no' => 'required|string',
            'session_year' => 'required|string',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $program = DB::table('programs')->find($req->program_id);
        $org = DB::table('organizations')->where('is_active', true)->first();

        $isBsc = $this->programIsBsc($program);

        $rows = DB::table('subject_papers as sp')
            ->join('subjects as s', 's.id', 'sp.subject_id')
            ->select('sp.*', 's.name as subject_name')
            ->where('sp.program_id', $req->program_id)
            ->where('sp.semester_no', $req->semester_no)
            ->where('sp.session_year', $req->session_year)
            ->orderBy('sp.group_label')
            ->orderBy('sp.id')
            ->get();

        // Group is only a real concept for BSc electives — everything else
        // prints as one flat list under a single unlabeled bucket.
        $groups = $isBsc
            ? $rows->groupBy(fn ($r) => $r->group_label ?: 'Ungrouped')
            : collect(['' => $rows]);

        try {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.subject-paper-master', [
                'program' => $program,
                'org' => $org,
                'semesterNo' => $req->semester_no,
                'sessionYear' => $req->session_year,
                'groups' => $groups,
                'showGroups' => $isBsc,
            ])->setPaper('a4');

            return response()->streamDownload(
                fn () => print($pdf->output()),
                "Subject-Paper-Master-{$program->short_name}-Sem{$req->semester_no}.pdf",
                ['Content-Type' => 'application/pdf']
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Subject paper master print failed: ' . $e->getMessage());
            return response()->json(['message' => 'Could not generate the print sheet.'], 500);
        }
    }

    // 13. Subject Seat in Class ───────────────────────────────────────
    public function subjectSeatIndex(Request $req)
    {
        return response()->json(
            DB::table('subject_seats as ss')
                ->join('programs as p', 'p.id', 'ss.program_id')
                ->join('subjects as s', 's.id', 'ss.subject_id')
                ->select('ss.*', 'p.short_name as class', 'p.full_name', 's.name as subject_name')
                ->when($req->program_id, fn($q) => $q->where('ss.program_id', $req->program_id))
                ->get()
        );
    }

    public function subjectSeatStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'subject_id' => 'required|exists:subjects,id',
            'allotted_seat' => 'required|integer',
            'order_ref' => 'nullable|string',
            'varg_bridhi' => 'nullable|integer',
            'total_seat' => 'required|integer',
            'permission_type' => 'required|in:Finance,Self Finance,Temporary',
            'period_session' => 'nullable|string',
            'status' => 'required|in:Active,Inactive',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        DB::table('subject_seats')->updateOrInsert(
            ['program_id' => $req->program_id, 'subject_id' => $req->subject_id],
            array_merge($req->except(['program_id', 'subject_id']), ['updated_at' => now()])
        );
        return response()->json(['message' => 'Seat configuration saved.']);
    }

    public function subjectSeatDestroy($id)
    {
        DB::table('subject_seats')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 14. Subject Selection Master ────────────────────────────────────
    // Grouped view (A, B, C …) for the builder + grid.
    public function subjectSelectionIndex(Request $req)
    {
        $groups = $this->groupedSelections($req->program_id, $req->semester_no);
        $class  = DB::table('programs')->where('id', $req->program_id)->value('short_name');

        return response()->json([
            'class'        => $class,
            'semester_no'  => $req->semester_no,
            'total_groups' => count($groups),
            'groups'       => $groups,
        ]);
    }

    /**
     * Save one letter-group and its subjects (upsert — replaces the group's rows).
     * Payload: { program_id, semester_no, group_label, group_name?, max_select,
     *            min_select, is_compulsory?, subjects: [{subject_id, sort_order?}] }
     */
    public function subjectSelectionStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id'          => 'required|exists:programs,id',
            'semester_no'         => 'required|string',
            'group_label'         => 'required|string|max:2',
            'group_name'          => 'nullable|string',
            'max_select'          => 'required|integer|min:1',
            'min_select'          => 'required|integer|min:0',
            'is_compulsory'       => 'nullable|boolean',
            'subjects'            => 'required|array|min:1',
            'subjects.*.subject_id' => 'required|exists:subjects,id',
            'subjects.*.sort_order' => 'nullable|integer',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $label   = strtoupper($req->group_label);
        $groupNo = ord($label) - 64;   // A -> 1 (kept for reg/adm compatibility)

        DB::transaction(function () use ($req, $label, $groupNo) {
            // Replace the whole group.
            DB::table('subject_selections')
                ->where('program_id', $req->program_id)
                ->where('semester_no', $req->semester_no)
                ->where('group_label', $label)
                ->delete();

            $rows = [];
            foreach ($req->subjects as $i => $sub) {
                $rows[] = [
                    'program_id'    => $req->program_id,
                    'semester_no'   => $req->semester_no,
                    'subject_id'    => $sub['subject_id'],
                    'group_no'      => $groupNo,
                    'group_label'   => $label,
                    'group_name'    => $req->group_name,
                    'max_select'    => $req->max_select,
                    'min_select'    => $req->min_select,
                    'is_compulsory' => (bool) ($req->is_compulsory ?? false),
                    'sort_order'    => $sub['sort_order'] ?? ($i + 1),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];
            }
            DB::table('subject_selections')->insert($rows);
        });

        return response()->json(['message' => "Group {$label} saved."], 201);
    }

    // Delete a single subject row (per-subject X action).
    public function subjectSelectionDestroy($id)
    {
        DB::table('subject_selections')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // Delete a whole group: ?program_id=&semester_no=&group_label=
    public function subjectSelectionGroupDestroy(Request $req)
    {
        DB::table('subject_selections')
            ->where('program_id', $req->program_id)
            ->where('semester_no', $req->semester_no)
            ->where('group_label', strtoupper((string) $req->group_label))
            ->delete();
        return response()->json(['message' => 'Group deleted.']);
    }

    /**
     * Real print — an actual PDF of the Subject Selection Master groups
     * (Group A / B / C … with their subjects and Max/Min Select), not a
     * browser print of the on-screen builder.
     */
    public function subjectSelectionPrint(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'semester_no' => 'nullable|string',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $program = DB::table('programs')->find($req->program_id);
        $org = DB::table('organizations')->where('is_active', true)->first();
        $groups = $this->groupedSelections($req->program_id, $req->semester_no);

        try {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.subject-selection-master', [
                'program' => $program,
                'org' => $org,
                'semesterNo' => $req->semester_no,
                'groups' => $groups,
            ])->setPaper('a4');

            return response()->streamDownload(
                fn () => print($pdf->output()),
                "Subject-Selection-Master-{$program->short_name}.pdf",
                ['Content-Type' => 'application/pdf']
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Subject selection master print failed: ' . $e->getMessage());
            return response()->json(['message' => 'Could not generate the print sheet.'], 500);
        }
    }

    // Groupwise subjects for the registration & application dropdowns.
    // Auth version (college portal).
    public function subjectGroups(Request $req)
    {
        return response()->json([
            'groups' => $this->groupedSelections($req->program_id, $req->semester_no),
        ]);
    }

    // Public version (registration form is public — no auth).
    public function publicSubjectGroups(Request $req)
    {
        return response()->json([
            'groups' => $this->groupedSelections(
                $req->query('program_id'),
                $req->query('semester_no', $req->query('semester'))
            ),
        ]);
    }

    /** Shared: build the A/B/C group structure with its subjects. */
    private function groupedSelections($programId, $semesterNo): array
    {
        if (!$programId) {
            return [];
        }

        $rows = DB::table('subject_selections as sel')
            ->join('subjects as s', 's.id', '=', 'sel.subject_id')
            ->select(
                'sel.id', 'sel.subject_id', 'sel.group_label', 'sel.group_name',
                'sel.max_select', 'sel.min_select', 'sel.is_compulsory', 'sel.sort_order',
                's.name as subject_name', 's.code as subject_code'
            )
            ->where('sel.program_id', $programId)
            ->when($semesterNo, fn ($q) => $q->where('sel.semester_no', $semesterNo))
            ->orderBy('sel.group_label')->orderBy('sel.sort_order')
            ->get();

        return $rows->groupBy('group_label')->map(function ($items, $label) {
            $first = $items->first();
            return [
                'group_label'   => $label,
                'group_name'    => $first->group_name,
                'max_select'    => (int) $first->max_select,
                'min_select'    => (int) $first->min_select,
                'is_compulsory' => (bool) $first->is_compulsory,
                'subjects'      => $items->map(fn ($r) => [
                    'id'           => $r->id,
                    'subject_id'   => $r->subject_id,
                    'subject_name' => $r->subject_name,
                    'subject_code' => $r->subject_code,
                    'sort_order'   => $r->sort_order,
                ])->values(),
            ];
        })->values()->all();
    }

    // 15. Vocational & Co-Curriculum Paper Master ─────────────────────
    /**
     * Public list of vocational / co-curricular papers, used to populate the
     * Minor Subject dropdown on the (public) UG registration form.
     * GET /student/register/vocational-papers?program_id=&semester_no=&session_year=
     */
    public function publicVocationalPapers(Request $req)
    {
        $programId = $req->query('program_id');
        if (!$programId) {
            return response()->json(['papers' => []]);
        }

        $papers = DB::table('vocational_papers')
            ->where('program_id', $programId)
            ->when($req->query('semester_no'), fn ($q) => $q->where('semester_no', $req->query('semester_no')))
            ->when($req->query('session_year'), fn ($q) => $q->where('session_year', $req->query('session_year')))
            ->orderBy('group_no')->orderBy('paper_name')
            ->get(['id', 'paper_code', 'paper_name', 'group_no', 'group_name']);

        return response()->json(['papers' => $papers]);
    }

    public function vocationalPaperIndex(Request $req)
    {
        return response()->json(
            DB::table('vocational_papers as vp')
                ->join('programs as p', 'p.id', 'vp.program_id')
                ->select('vp.*', 'p.short_name as class')
                ->when($req->program_id, fn($q) => $q->where('vp.program_id', $req->program_id))
                ->when($req->semester_no, fn($q) => $q->where('vp.semester_no', $req->semester_no))
                ->when($req->session_year, fn($q) => $q->where('vp.session_year', $req->session_year))
                ->orderBy('vp.group_no')
                ->get()
        );
    }

    /**
     * Batch save — the office picks a Group (via Prev./Next Group nav),
     * sets Group Name / Max. Select / Min Select once for that group, fills
     * several Paper Code / Paper Name rows, then hits Save once. Every row
     * lands in the group number the office is currently viewing — group_no
     * here is chosen by the office (via navigation), never auto-incremented.
     */
    public function vocationalPaperStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'session_year' => 'required|string',
            'semester_no' => 'required|string',
            'group_no' => 'required|integer',
            'group_name' => 'required|string',
            'max_select' => 'nullable|integer|min:1',
            'min_select' => 'nullable|integer|min:0',
            'papers' => 'required|array|min:1',
            'papers.*.paper_code' => 'required|string|max:50',
            'papers.*.paper_name' => 'required|string',
            'papers.*.max_marks' => 'nullable|integer|min:1',
            'papers.*.min_marks' => 'nullable|integer|min:0',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $rows = [];
        foreach ($req->papers as $p) {
            $rows[] = [
                'program_id' => $req->program_id,
                'session_year' => $req->session_year,
                'semester_no' => $req->semester_no,
                'group_no' => $req->group_no,
                'group_name' => $req->group_name,
                'max_select' => $req->max_select ?? 1,
                'min_select' => $req->min_select ?? 1,
                'paper_code' => $p['paper_code'],
                'paper_name' => $p['paper_name'],
                'max_marks' => $p['max_marks'] ?? 100,
                'min_marks' => $p['min_marks'] ?? 33,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('vocational_papers')->insert($rows);

        return response()->json(['message' => 'Saved ' . count($rows) . " paper(s) under Group {$req->group_no}."], 201);
    }

    public function vocationalPaperUpdate(Request $req, $id)
    {
        $v = Validator::make($req->all(), [
            'group_name' => 'nullable|string',
            'max_select' => 'nullable|integer|min:1',
            'min_select' => 'nullable|integer|min:0',
            'paper_code' => 'nullable|string',
            'paper_name' => 'required|string',
            'max_marks' => 'nullable|integer',
            'min_marks' => 'nullable|integer',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        DB::table('vocational_papers')->where('id', $id)->update([
            'paper_code' => $req->paper_code,
            'paper_name' => $req->paper_name,
            'max_marks' => $req->max_marks ?? 100,
            'min_marks' => $req->min_marks ?? 33,
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Updated.']);
    }

    public function vocationalPaperDestroy($id)
    {
        DB::table('vocational_papers')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Real print — an actual PDF of the Vocational & Co-Curriculum Paper
     * Master groups (Group 1 / 2 / 3 … with Paper Code/Name/Max/Min), not a
     * browser print of the on-screen grid.
     */
    public function vocationalPaperPrint(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'semester_no' => 'required|string',
            'session_year' => 'required|string',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $program = DB::table('programs')->find($req->program_id);
        $org = DB::table('organizations')->where('is_active', true)->first();

        $groups = DB::table('vocational_papers')
            ->where('program_id', $req->program_id)
            ->where('semester_no', $req->semester_no)
            ->where('session_year', $req->session_year)
            ->orderBy('group_no')
            ->orderBy('id')
            ->get()
            ->groupBy('group_no');

        try {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.vocational-paper-master', [
                'program' => $program,
                'org' => $org,
                'semesterNo' => $req->semester_no,
                'sessionYear' => $req->session_year,
                'groups' => $groups,
            ])->setPaper('a4');

            return response()->streamDownload(
                fn () => print($pdf->output()),
                "Vocational-Paper-Master-{$program->short_name}-Sem{$req->semester_no}.pdf",
                ['Content-Type' => 'application/pdf']
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Vocational paper master print failed: ' . $e->getMessage());
            return response()->json(['message' => 'Could not generate the print sheet.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // HOLIDAY CALENDAR
    // ══════════════════════════════════════════════════════════════════
    public function holidayIndex(Request $req)
    {
        return response()->json(
            DB::table('holiday_calendars')
                ->when($req->session_year, fn($q) => $q->where('session_year', $req->session_year))
                ->orderBy('leave_from')
                ->get()
        );
    }

    public function holidayStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'session_year' => 'required|string',
            'name' => 'required|string|max:255',
            'type' => 'required|in:Gazetted,Local,College Level,University Level',
            'leave_from' => 'required|date',
            'leave_days' => 'required|integer|min:1',
            'leave_till' => 'required|date',
            'leave_for' => 'required|in:All,Teaching Staff,Office Staff Only,Only Student',
            'sms_alert' => 'required|in:Before,Same Day,Immediate',
            'sms_days_before' => 'nullable|integer',
            'is_active' => 'required|boolean',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('holiday_calendars')->insertGetId(array_merge($req->all(), [
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Holiday saved.'], 201);
    }

    public function holidayUpdate(Request $req, $id)
    {
        DB::table('holiday_calendars')->where('id', $id)->update(array_merge(
            $req->only(['name', 'type', 'leave_from', 'leave_days', 'leave_till', 'leave_for', 'sms_alert', 'sms_days_before', 'is_active']),
            ['updated_at' => now()]
        ));
        return response()->json(['message' => 'Updated.']);
    }

    public function holidayDestroy($id)
    {
        DB::table('holiday_calendars')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // ══════════════════════════════════════════════════════════════════
    // PRINT PERMISSION ON STUDENT PORTAL
    // ══════════════════════════════════════════════════════════════════
    public function printPermissionIndex()
    {
        return response()->json(DB::table('print_permissions')->get());
    }

    public function printPermissionUpdate(Request $req)
    {
        foreach ($req->permissions as $perm) {
            DB::table('print_permissions')->updateOrInsert(
                ['document_type' => $perm['document_type']],
                ['is_allowed' => $perm['is_allowed'], 'updated_at' => now()]
            );
        }
        return response()->json(['message' => 'Print permissions updated.']);
    }

    // ══════════════════════════════════════════════════════════════════
    // STATE SECURITY DEPOSIT
    // ══════════════════════════════════════════════════════════════════
    public function securityDepositIndex()
    {
        return response()->json(DB::table('state_security_deposits')->orderBy('state_name')->get());
    }

    public function securityDepositUpdate(Request $req, $id)
    {
        DB::table('state_security_deposits')->where('id', $id)->update([
            'deposit_required' => $req->deposit_required,
            'amount' => $req->amount,
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Updated.']);
    }

    // ══════════════════════════════════════════════════════════════════
    // COUNSELLING REPORTED STUDENT DATA
    // ══════════════════════════════════════════════════════════════════
    public function counsellingIndex(Request $req)
    {
        return response()->json(
            DB::table('counselling_reports')
                ->when($req->program_id, fn($q) => $q->where('program_id', $req->program_id))
                ->when($req->session_year, fn($q) => $q->where('session_year', $req->session_year))
                ->orderBy('created_at', 'desc')
                ->paginate(20)
        );
    }

    public function counsellingStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'session_year' => 'required|string',
            'entrance_roll_no' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'father_name' => 'required|string|max:255',
            'mother_name' => 'required|string|max:255',
            'gender' => 'required|in:Male,Female,Trans',
            'social_category' => 'required|in:General,OBC,SC,ST,EWS',
            'admission_category' => 'required|in:Regular,Private',
            'state_rank' => 'required|integer',
            'category_rank' => 'nullable|integer',
            'cut_off_mark' => 'nullable|numeric',
            'allotment_no' => 'nullable|string',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('counselling_reports')->insertGetId(array_merge($req->all(), [
            'entry_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Record saved successfully.'], 201);
    }

    public function counsellingDestroy($id)
    {
        DB::table('counselling_reports')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }
}
