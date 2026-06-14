<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// ─── Models ────────────────────────────────────────────────────────────────────
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
            'program_id'          => 'required|exists:programs,id',
            'session_year'        => 'required|string',
            'semester_name'       => 'required|string',
            'semester_no'         => 'required|string',
            'exam_mode'           => 'required|in:Regular,Back Paper,Upgrade',
            'start_admission'     => 'required|date',
            'close_admission'     => 'required|date|after:start_admission',
            'late_fee_applicable' => 'required|boolean',
            'late_fee'            => 'nullable|numeric|min:0',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $rec = DB::table('application_schedules')->insertGetId(array_merge($req->only([
            'program_id','session_year','semester_name','semester_no','exam_mode',
            'start_admission','close_admission','late_fee_applicable','late_fee',
        ]), ['created_at' => now(), 'updated_at' => now()]));

        return response()->json(['id' => $rec, 'message' => 'Schedule saved.'], 201);
    }

    public function applicationScheduleUpdate(Request $req, $id)
    {
        DB::table('application_schedules')->where('id', $id)->update(array_merge(
            $req->only(['program_id','session_year','semester_name','semester_no','exam_mode',
                        'start_admission','close_admission','late_fee_applicable','late_fee']),
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
        return response()->json(
            DB::table('admission_conditions')
                ->when($req->program_id, fn($q) => $q->where('program_id', $req->program_id))
                ->when($req->session_year, fn($q) => $q->where('session_year', $req->session_year))
                ->get()
        );
    }

    public function admissionConditionStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id'           => 'required|exists:programs,id',
            'session_year'         => 'required|string',
            'semester_no'          => 'required|string',
            'qualifying_class'     => 'required|string',
            'condition_type'       => 'required|in:Open Admission,Through Counselling,Cut Off Merit List,Out Of Merit List',
            'allotted_seat'        => 'required|integer',
            'required_percent_gen' => 'required|numeric',
            'required_percent_obc' => 'required|numeric',
            'required_percent_sc'  => 'required|numeric',
            'required_percent_st'  => 'required|numeric',
            'required_percent_ews' => 'required|numeric',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

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
                ->get()
        );
    }

    public function enclosureMasterStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id'     => 'required|exists:programs,id',
            'semester_no'    => 'required|string',
            'admission_mode' => 'required|string',
            'document_name'  => 'required|string|max:255',
            'is_required'    => 'required|boolean',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('enclosure_masters')->insertGetId(array_merge($req->all(), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Document saved.'], 201);
    }

    public function enclosureMasterDestroy($id)
    {
        DB::table('enclosure_masters')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 4. Fee Head Master ──────────────────────────────────────────────
    public function feeHeadIndex()
    {
        return response()->json(FeeHead::orderBy('name')->get());
    }

    public function feeHeadStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'name'       => 'required|string|max:255|unique:fee_heads,name',
            'in_favor_of'=> 'required|in:College,University,Government',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $fh = FeeHead::create($req->only(['name','in_favor_of']));
        return response()->json($fh, 201);
    }

    public function feeHeadUpdate(Request $req, $id)
    {
        FeeHead::findOrFail($id)->update($req->only(['name','in_favor_of']));
        return response()->json(['message' => 'Updated.']);
    }

    public function feeHeadDestroy($id)
    {
        FeeHead::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 5. Fee Structure ────────────────────────────────────────────────
    public function feeStructureIndex(Request $req)
    {
        return response()->json(
            DB::table('fee_structures as fs')
                ->join('programs as p', 'p.id', 'fs.program_id')
                ->join('fee_heads as fh', 'fh.id', 'fs.fee_head_id')
                ->select('fs.*', 'p.short_name as class', 'fh.name as fee_head', 'fh.in_favor_of')
                ->when($req->program_id, fn($q) => $q->where('fs.program_id', $req->program_id))
                ->when($req->session_year, fn($q) => $q->where('fs.session_year', $req->session_year))
                ->when($req->semester_no, fn($q) => $q->where('fs.semester_no', $req->semester_no))
                ->when($req->exam_mode, fn($q) => $q->where('fs.exam_mode', $req->exam_mode))
                ->orderBy('fh.name')
                ->get()
        );
    }

    public function feeStructureStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id'   => 'required|exists:programs,id',
            'fee_head_id'  => 'required|exists:fee_heads,id',
            'session_year' => 'required|string',
            'semester_no'  => 'required|string',
            'exam_mode'    => 'required|in:Regular,Back Paper,Upgrade',
            'term'         => 'required|in:Admission,Semester Registration',
            'amounts'      => 'required|array',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        DB::table('fee_structures')->updateOrInsert(
            ['program_id' => $req->program_id, 'fee_head_id' => $req->fee_head_id,
             'session_year' => $req->session_year, 'semester_no' => $req->semester_no, 'exam_mode' => $req->exam_mode],
            array_merge($req->only(['term','for_sdpg_passout','for_ddu_passout']),
                ['amounts' => json_encode($req->amounts), 'updated_at' => now()])
        );
        return response()->json(['message' => 'Fee structure saved.']);
    }

    public function feeStructureCopyYear(Request $req)
    {
        $v = Validator::make($req->all(), [
            'from_year' => 'required|string',
            'to_year'   => 'required|string',
            'program_id'=> 'required|exists:programs,id',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $rows = DB::table('fee_structures')
            ->where('session_year', $req->from_year)
            ->where('program_id', $req->program_id)
            ->get();

        foreach ($rows as $r) {
            DB::table('fee_structures')->updateOrInsert(
                ['program_id' => $r->program_id, 'fee_head_id' => $r->fee_head_id,
                 'session_year' => $req->to_year, 'semester_no' => $r->semester_no, 'exam_mode' => $r->exam_mode],
                ['term' => $r->term, 'amounts' => $r->amounts, 'updated_at' => now()]
            );
        }
        return response()->json(['message' => "Copied {$req->from_year} → {$req->to_year}."]);
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
            ['program_id' => $req->program_id, 'session_year' => $req->session_year,
             'semester_no' => $req->semester_no, 'registration_mode' => $req->registration_mode],
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
            'program_id'           => 'required|exists:programs,id',
            'semester'             => 'required|string',
            'session_year'         => 'required|string',
            'start_from'           => 'required|date',
            'end_on'               => 'required|date|after:start_from',
            'late_fee_applicable'  => 'required|boolean',
            'late_fee'             => 'nullable|numeric|min:0',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('back_paper_schedules')->insertGetId(array_merge($req->all(), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Back paper schedule saved.'], 201);
    }

    public function backPaperScheduleUpdate(Request $req, $id)
    {
        DB::table('back_paper_schedules')->where('id', $id)->update(array_merge(
            $req->only(['program_id','semester','session_year','start_from','end_on','late_fee_applicable','late_fee']),
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
    public function classMasterIndex()
    {
        return response()->json(Program::orderBy('short_name')->get());
    }

    public function classMasterStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'level'          => 'required|in:UG,PG,B.Ed',
            'approval_type'  => 'required|in:Under Finance,Self Finance',
            'short_name'     => 'required|string|max:20|unique:programs,short_name',
            'full_name'      => 'required|string|max:255',
            'duration_years' => 'required|integer|min:1',
            'exam_mode'      => 'required|in:Regular,Back Paper',
            'total_semesters'=> 'required|integer|min:1',
            'status'         => 'required|in:Active,Inactive',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $p = Program::create($req->only(['level','approval_type','short_name','full_name',
                                          'duration_years','exam_mode','total_semesters','status']));
        return response()->json($p, 201);
    }

    public function classMasterUpdate(Request $req, $id)
    {
        Program::findOrFail($id)->update($req->only([
            'level','approval_type','short_name','full_name','duration_years','exam_mode','total_semesters','status'
        ]));
        return response()->json(['message' => 'Updated.']);
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
            'name'         => 'required|string|max:50',
            'semester_nos' => 'required|string',
            'status'       => 'required|in:Active,Inactive',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('semester_masters')->insertGetId(array_merge($req->only(['name','semester_nos','status']), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Semester saved.'], 201);
    }

    public function semesterMasterUpdate(Request $req, $id)
    {
        DB::table('semester_masters')->where('id', $id)->update(array_merge(
            $req->only(['name','semester_nos','status']), ['updated_at' => now()]
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

    public function subjectMasterStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id'          => 'required|exists:programs,id',
            'name'                => 'required|string|max:255',
            'has_practical'       => 'required|boolean',
            'practical_fee'       => 'nullable|numeric',
            'permission_type'     => 'required|in:Finance,Self Finance',
            'additional_fee'      => 'nullable|numeric',
            'additional_fee_amt'  => 'nullable|numeric',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $s = Subject::create($req->only([
            'program_id','name','has_practical','practical_fee','permission_type','additional_fee','additional_fee_amt'
        ]));
        return response()->json($s, 201);
    }

    public function subjectMasterUpdate(Request $req, $id)
    {
        Subject::findOrFail($id)->update($req->only([
            'program_id','name','has_practical','practical_fee','permission_type','additional_fee','additional_fee_amt'
        ]));
        return response()->json(['message' => 'Updated.']);
    }

    public function subjectMasterDestroy($id)
    {
        Subject::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 11. Allotted Subject Master ─────────────────────────────────────
    public function allottedSubjectIndex(Request $req)
    {
        return response()->json(
            DB::table('allotted_subjects as a')
                ->join('programs as p', 'p.id', 'a.program_id')
                ->join('subjects as s', 's.id', 'a.subject_id')
                ->select('a.*', 'p.short_name as class', 'p.full_name', 's.name as subject_name', 's.has_practical', 's.practical_fee')
                ->when($req->program_id, fn($q) => $q->where('a.program_id', $req->program_id))
                ->get()
        );
    }

    public function allottedSubjectStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id'             => 'required|exists:programs,id',
            'subject_id'             => 'required|exists:subjects,id',
            'permission_type'        => 'required|in:Finance,Self Finance',
            'for_regular'            => 'required|boolean',
            'for_private'            => 'required|boolean',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('allotted_subjects')->insertGetId(array_merge($req->all(), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Allotted subject saved.'], 201);
    }

    public function allottedSubjectDestroy($id)
    {
        DB::table('allotted_subjects')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 12. Subject Paper Master ────────────────────────────────────────
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
                ->orderBy('sp.group_no')
                ->get()
        );
    }

    public function subjectPaperStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id'   => 'required|exists:programs,id',
            'subject_id'   => 'required|exists:subjects,id',
            'session_year' => 'required|string',
            'semester_no'  => 'required|string',
            'paper_type'   => 'required|string',
            'paper_name'   => 'required|string',
            'group_no'     => 'required|integer',
            'max_marks'    => 'required|integer',
            'min_marks'    => 'required|integer',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('subject_papers')->insertGetId(array_merge($req->all(), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Paper saved.'], 201);
    }

    public function subjectPaperDestroy($id)
    {
        DB::table('subject_papers')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
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
            'program_id'      => 'required|exists:programs,id',
            'subject_id'      => 'required|exists:subjects,id',
            'allotted_seat'   => 'required|integer',
            'order_ref'       => 'nullable|string',
            'varg_bridhi'     => 'nullable|integer',
            'total_seat'      => 'required|integer',
            'permission_type' => 'required|in:Finance,Self Finance,Temporary',
            'period_session'  => 'nullable|string',
            'status'          => 'required|in:Active,Inactive',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        DB::table('subject_seats')->updateOrInsert(
            ['program_id' => $req->program_id, 'subject_id' => $req->subject_id],
            array_merge($req->except(['program_id','subject_id']), ['updated_at' => now()])
        );
        return response()->json(['message' => 'Seat configuration saved.']);
    }

    public function subjectSeatDestroy($id)
    {
        DB::table('subject_seats')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 14. Subject Selection Master ────────────────────────────────────
    public function subjectSelectionIndex(Request $req)
    {
        return response()->json(
            DB::table('subject_selections as sel')
                ->join('programs as p', 'p.id', 'sel.program_id')
                ->join('subjects as s', 's.id', 'sel.subject_id')
                ->select('sel.*', 'p.short_name as class', 's.name as subject_name')
                ->when($req->program_id, fn($q) => $q->where('sel.program_id', $req->program_id))
                ->when($req->semester_no, fn($q) => $q->where('sel.semester_no', $req->semester_no))
                ->orderBy('sel.group_no')->orderBy('sel.sort_order')
                ->get()
        );
    }

    public function subjectSelectionStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id'  => 'required|exists:programs,id',
            'semester_no' => 'required|string',
            'subject_id'  => 'required|exists:subjects,id',
            'group_no'    => 'required|integer',
            'is_compulsory'=> 'required|boolean',
            'max_marks'   => 'required|integer',
            'min_marks'   => 'required|integer',
            'sort_order'  => 'nullable|integer',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('subject_selections')->insertGetId(array_merge($req->all(), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Selection saved.'], 201);
    }

    public function subjectSelectionDestroy($id)
    {
        DB::table('subject_selections')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 15. Vocational & Co-Curriculum Paper Master ─────────────────────
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

    public function vocationalPaperStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id'   => 'required|exists:programs,id',
            'session_year' => 'required|string',
            'semester_no'  => 'required|string',
            'group_no'     => 'required|integer',
            'group_name'   => 'required|string',
            'paper_code'   => 'required|string',
            'paper_name'   => 'required|string',
            'max_marks'    => 'required|integer',
            'min_marks'    => 'required|integer',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('vocational_papers')->insertGetId(array_merge($req->all(), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Paper saved.'], 201);
    }

    public function vocationalPaperDestroy($id)
    {
        DB::table('vocational_papers')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
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
            'session_year'  => 'required|string',
            'name'          => 'required|string|max:255',
            'type'          => 'required|in:Gazetted,Local,College Level,University Level',
            'leave_from'    => 'required|date',
            'leave_days'    => 'required|integer|min:1',
            'leave_till'    => 'required|date',
            'leave_for'     => 'required|in:All,Teaching Staff,Office Staff Only,Only Student',
            'sms_alert'     => 'required|in:Before,Same Day,Immediate',
            'sms_days_before'=> 'nullable|integer',
            'is_active'     => 'required|boolean',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('holiday_calendars')->insertGetId(array_merge($req->all(), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Holiday saved.'], 201);
    }

    public function holidayUpdate(Request $req, $id)
    {
        DB::table('holiday_calendars')->where('id', $id)->update(array_merge(
            $req->only(['name','type','leave_from','leave_days','leave_till','leave_for','sms_alert','sms_days_before','is_active']),
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
            'amount'           => $req->amount,
            'updated_at'       => now(),
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
            'program_id'         => 'required|exists:programs,id',
            'session_year'       => 'required|string',
            'entrance_roll_no'   => 'required|string|max:50',
            'name'               => 'required|string|max:255',
            'father_name'        => 'required|string|max:255',
            'mother_name'        => 'required|string|max:255',
            'gender'             => 'required|in:Male,Female,Trans',
            'social_category'    => 'required|in:General,OBC,SC,ST,EWS',
            'admission_category' => 'required|in:Regular,Private',
            'state_rank'         => 'required|integer',
            'category_rank'      => 'nullable|integer',
            'cut_off_mark'       => 'nullable|numeric',
            'allotment_no'       => 'nullable|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('counselling_reports')->insertGetId(array_merge($req->all(), [
            'entry_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Record saved successfully.'], 201);
    }

    public function counsellingDestroy($id)
    {
        DB::table('counselling_reports')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }
}
