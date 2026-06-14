<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExaminationController extends Controller
{
    // ══════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════
    private function sessionYear(): string
    {
        return request('session_year', date('Y') . '-' . (date('Y') + 1));
    }

    // ══════════════════════════════════════════════════════════════
    // 1. ACCEPT EXAM FORM
    // ══════════════════════════════════════════════════════════════
    public function acceptFormIndex(Request $req)
    {
        $q = DB::table('exam_forms as ef')
            ->join('admissions as a', 'a.id', 'ef.admission_id')
            ->join('students as s', 's.id', 'a.student_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->select('ef.*', 's.name', 's.father_name', 's.mobile',
                     'a.roll_no', 'a.account_no', 'p.short_name as class')
            ->where('ef.session_year', $req->session_year ?? $this->sessionYear())
            ->when($req->program_id,  fn($q) => $q->where('a.program_id',  $req->program_id))
            ->when($req->semester_no, fn($q) => $q->where('ef.semester_no', $req->semester_no))
            ->when($req->exam_type,   fn($q) => $q->where('ef.exam_type',   $req->exam_type))
            ->when($req->status,      fn($q) => $q->where('ef.status',      $req->status));

        return response()->json($q->paginate(50));
    }

    public function acceptFormUpdate(Request $req, $id)
    {
        $v = Validator::make($req->all(), [
            'status' => 'required|in:Accepted,Pending,Rejected',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        DB::table('exam_forms')->where('id', $id)
            ->update(['status' => $req->status, 'updated_at' => now()]);

        return response()->json(['message' => 'Status updated.']);
    }

    public function acceptFormBulk(Request $req)
    {
        // Accept all filtered records
        DB::table('exam_forms')
            ->where('session_year', $req->session_year)
            ->when($req->program_id,  fn($q) => $q->whereIn('admission_id',
                DB::table('admissions')->where('program_id', $req->program_id)->pluck('id')))
            ->when($req->semester_no, fn($q) => $q->where('semester_no', $req->semester_no))
            ->update(['status' => 'Accepted', 'updated_at' => now()]);

        return response()->json(['message' => 'All accepted.']);
    }

    // ══════════════════════════════════════════════════════════════
    // 2. EXAM FORM ID ENTRY
    // ══════════════════════════════════════════════════════════════
    public function formIdIndex(Request $req)
    {
        return DB::table('exam_forms as ef')
            ->join('admissions as a', 'a.id', 'ef.admission_id')
            ->join('students as s', 's.id', 'a.student_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->select('ef.*', 's.name', 's.father_name', 's.gender',
                     'a.roll_no', 'a.account_no', 'a.enrollment_no', 'p.short_name as class')
            ->where('ef.session_year', $req->session_year ?? $this->sessionYear())
            ->when($req->program_id,  fn($q) => $q->where('a.program_id',  $req->program_id))
            ->when($req->semester_no, fn($q) => $q->where('ef.semester_no', $req->semester_no))
            ->when($req->exam_type,   fn($q) => $q->where('ef.exam_type',   $req->exam_type))
            ->paginate(50);
    }

    public function formIdUpdate(Request $req, $id)
    {
        $v = Validator::make($req->all(), ['form_id' => 'required|string|max:50']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        DB::table('exam_forms')->where('id', $id)
            ->update(['form_id' => $req->form_id, 'updated_at' => now()]);
        return response()->json(['message' => 'Form ID saved.']);
    }

    // ══════════════════════════════════════════════════════════════
    // 3. EXAM SCHEDULE — ENTRY
    // ══════════════════════════════════════════════════════════════
    public function scheduleIndex(Request $req)
    {
        return response()->json(
            DB::table('exam_schedules as es')
                ->join('subjects as sub', 'sub.id', 'es.subject_id')
                ->join('programs as p', 'p.id', 'es.program_id')
                ->select('es.*', 'sub.name as subject_name', 'p.short_name as class')
                ->where('es.session_year', $req->session_year ?? $this->sessionYear())
                ->when($req->program_id,  fn($q) => $q->where('es.program_id',  $req->program_id))
                ->when($req->semester_no, fn($q) => $q->where('es.semester_no', $req->semester_no))
                ->when($req->exam_mode,   fn($q) => $q->where('es.exam_mode',   $req->exam_mode))
                ->orderBy('es.exam_date')->orderBy('es.inning')
                ->get()
        );
    }

    public function scheduleStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'session_year' => 'required|string',
            'program_id'   => 'required|exists:programs,id',
            'semester_no'  => 'required|string',
            'exam_mode'    => 'required|in:Regular,Back Paper',
            'exam_date'    => 'required|date',
            'inning'       => 'required|string',
            'exam_start'   => 'required|string',
            'exam_end'     => 'required|string',
            'paper_code'   => 'required|string|max:20',
            'subject_id'   => 'required|exists:subjects,id',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('exam_schedules')->insertGetId(array_merge($req->all(), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Schedule entry saved.'], 201);
    }

    public function scheduleDestroy($id)
    {
        DB::table('exam_schedules')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // Schedule — Search
    public function scheduleSearch(Request $req)
    {
        $q = DB::table('exam_schedules as es')
            ->join('subjects as sub', 'sub.id', 'es.subject_id')
            ->join('programs as p', 'p.id', 'es.program_id')
            ->select('es.*', 'sub.name as subject_name', 'p.short_name as class')
            ->where('es.session_year', $req->session_year ?? $this->sessionYear());

        if ($req->search_by === 'date') {
            $dates = array_map('trim', explode(',', $req->exam_date ?? ''));
            $q->whereIn('es.exam_date', $dates);
        } elseif ($req->search_by === 'date_inning') {
            $dates = array_map('trim', explode(',', $req->exam_date ?? ''));
            $innings = array_map('trim', explode(',', $req->inning ?? ''));
            $q->whereIn('es.exam_date', $dates)->whereIn('es.inning', $innings);
        } elseif ($req->search_by === 'paper_code') {
            $codes = array_map('trim', explode(',', $req->paper_code ?? ''));
            $q->whereIn('es.paper_code', $codes);
        }

        return response()->json($q->orderBy('es.exam_date')->get());
    }

    // Schedule — Update
    public function scheduleUpdate(Request $req, $id)
    {
        DB::table('exam_schedules')->where('id', $id)->update(array_merge(
            $req->only(['exam_date', 'inning', 'exam_start', 'exam_end']),
            ['updated_at' => now()]
        ));
        return response()->json(['message' => 'Updated.']);
    }

    // ══════════════════════════════════════════════════════════════
    // 4. EXAM SEATING PLAN
    // ══════════════════════════════════════════════════════════════

    // Room Master
    public function roomMasterIndex()
    {
        return response()->json(DB::table('exam_rooms')->orderBy('room_no')->get());
    }

    public function roomMasterStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'room_no'       => 'required|string|max:20',
            'building_name' => 'required|string|max:100',
            'rows'          => 'required|integer|min:1',
            'columns'       => 'required|integer|min:1',
            'capacity'      => 'required|integer|min:1',
            'extra_seat'    => 'nullable|integer|min:0',
            'is_active'     => 'required|boolean',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('exam_rooms')->insertGetId(array_merge($req->all(), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Room saved.'], 201);
    }

    public function roomMasterUpdate(Request $req, $id)
    {
        DB::table('exam_rooms')->where('id', $id)->update(array_merge(
            $req->only(['room_no','building_name','rows','columns','capacity','extra_seat','is_active']),
            ['updated_at' => now()]
        ));
        return response()->json(['message' => 'Updated.']);
    }

    public function roomMasterDestroy($id)
    {
        DB::table('exam_rooms')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // Inning Setting
    public function inningIndex()
    {
        return response()->json(DB::table('exam_innings')->orderBy('id')->get());
    }

    public function inningStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'center_code'  => 'required|string|max:20',
            'inning_name'  => 'required|string|max:50',
            'time_start'   => 'required|string',
            'time_end'     => 'required|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('exam_innings')->insertGetId(array_merge($req->all(), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Inning saved.'], 201);
    }

    public function inningUpdate(Request $req, $id)
    {
        DB::table('exam_innings')->where('id', $id)->update(array_merge(
            $req->only(['inning_name','time_start','time_end']),
            ['updated_at' => now()]
        ));
        return response()->json(['message' => 'Updated.']);
    }

    public function inningDestroy($id)
    {
        DB::table('exam_innings')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // Seating Plan — Create
    public function seatingPlanCreate(Request $req)
    {
        $v = Validator::make($req->all(), [
            'session_year' => 'required|string',
            'exam_date'    => 'required|date',
            'inning_id'    => 'required|exists:exam_innings,id',
            'exam_type'    => 'required|in:Regular,Back Paper',
            'gender'       => 'nullable|in:Male,Female,Trans,All',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        // Get eligible exam forms for this date/inning
        $schedules = DB::table('exam_schedules')
            ->where('session_year', $req->session_year)
            ->where('exam_date', $req->exam_date)
            ->where('inning', DB::table('exam_innings')->where('id', $req->inning_id)->value('inning_name'))
            ->pluck('paper_code');

        $students = DB::table('exam_form_papers as efp')
            ->join('exam_forms as ef', 'ef.id', 'efp.exam_form_id')
            ->join('admissions as a', 'a.id', 'ef.admission_id')
            ->join('students as s', 's.id', 'a.student_id')
            ->whereIn('efp.paper_code', $schedules)
            ->where('ef.session_year', $req->session_year)
            ->where('ef.exam_type', $req->exam_type)
            ->when($req->gender && $req->gender !== 'All', fn($q) => $q->where('s.gender', $req->gender))
            ->where('ef.status', 'Accepted')
            ->select('s.id as student_id', 's.name', 'a.roll_no', 'efp.paper_code')
            ->get();

        $rooms = DB::table('exam_rooms')->where('is_active', true)->orderBy('building_name')->orderBy('room_no')->get();

        // Simple sequential seat allocation
        $seats = [];
        $roomIdx = 0;
        $seatIdx = 0;

        foreach ($students as $student) {
            if ($roomIdx >= $rooms->count()) break;
            $room = $rooms[$roomIdx];
            $capacity = $room->capacity + ($room->extra_seat ?? 0);

            $row = intdiv($seatIdx, $room->columns) + 1;
            $col = ($seatIdx % $room->columns) + 1;

            DB::table('exam_seating')->updateOrInsert(
                ['session_year' => $req->session_year, 'exam_date' => $req->exam_date,
                 'student_id' => $student->student_id, 'paper_code' => $student->paper_code],
                ['room_id' => $room->id, 'row_no' => $row, 'col_no' => $col,
                 'updated_at' => now(), 'created_at' => now()]
            );
            $seats[] = ['student' => $student->name, 'roll' => $student->roll_no,
                        'room' => $room->room_no, 'row' => $row, 'col' => $col];

            $seatIdx++;
            if ($seatIdx >= $capacity) { $roomIdx++; $seatIdx = 0; }
        }

        return response()->json(['message' => count($seats) . ' seats allocated.', 'count' => count($seats)]);
    }

    // Search Examinee Seat
    public function searchSeat(Request $req)
    {
        $v = Validator::make($req->all(), ['roll_no' => 'required|string']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $admission = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->where('a.roll_no', $req->roll_no)
            ->select('a.*', 's.name', 's.father_name', 's.mobile', 's.address', 'p.short_name as class')
            ->first();

        if (!$admission) return response()->json(['message' => 'Student not found.'], 404);

        $seats = DB::table('exam_seating as es')
            ->join('exam_rooms as r', 'r.id', 'es.room_id')
            ->where('es.student_id', $admission->student_id)
            ->where('es.session_year', $req->session_year ?? $this->sessionYear())
            ->select('es.*', 'r.room_no', 'r.building_name')
            ->get();

        return response()->json(['student' => $admission, 'seats' => $seats]);
    }

    // Self Create P7
    public function selfCreateP7(Request $req)
    {
        $v = Validator::make($req->all(), [
            'session_year' => 'required|string',
            'exam_date'    => 'required|date',
            'inning_id'    => 'required|exists:exam_innings,id',
            'program_id'   => 'required|exists:programs,id',
            'semester_no'  => 'required|string',
            'subject_id'   => 'required|exists:subjects,id',
            'paper_code'   => 'required|string',
            'exam_type'    => 'required|in:Regular,Back Paper',
            'gender'       => 'nullable|in:Male,Female,Trans,All',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $inning = DB::table('exam_innings')->find($req->inning_id);

        $students = DB::table('exam_form_papers as efp')
            ->join('exam_forms as ef', 'ef.id', 'efp.exam_form_id')
            ->join('admissions as a', 'a.id', 'ef.admission_id')
            ->join('students as s', 's.id', 'a.student_id')
            ->where('efp.paper_code', $req->paper_code)
            ->where('ef.session_year', $req->session_year)
            ->where('ef.exam_type', $req->exam_type)
            ->where('a.program_id', $req->program_id)
            ->where('a.semester_no', $req->semester_no)
            ->when($req->gender && $req->gender !== 'All', fn($q) => $q->where('s.gender', $req->gender))
            ->where('ef.status', 'Accepted')
            ->select('s.name', 'a.roll_no', 's.gender')
            ->get();

        return response()->json([
            'inning' => $inning,
            'students' => $students,
            'count' => $students->count(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // 5. EXAM CONDUCT
    // ══════════════════════════════════════════════════════════════

    // P1 — Create
    public function conductP1Index(Request $req)
    {
        return response()->json(
            DB::table('exam_conduct_p1')
                ->where('session_year', $req->session_year ?? $this->sessionYear())
                ->when($req->exam_date, fn($q) => $q->where('exam_date', $req->exam_date))
                ->orderBy('created_at', 'desc')->paginate(30)
        );
    }

    public function conductP1Store(Request $req)
    {
        $v = Validator::make($req->all(), [
            'session_year'   => 'required|string',
            'exam_date'      => 'required|date',
            'inning_id'      => 'required|exists:exam_innings,id',
            'center_code'    => 'required|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('exam_conduct_p1')->insertGetId(array_merge($req->all(), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'P1 created.'], 201);
    }

    // P3 — UFM
    public function conductP3Store(Request $req)
    {
        $v = Validator::make($req->all(), [
            'roll_no'        => 'required|string',
            'paper_code'     => 'required|string',
            'exam_date'      => 'required|date',
            'inning_id'      => 'required|exists:exam_innings,id',
            'room_id'        => 'required|exists:exam_rooms,id',
            'issued_copy_no' => 'required|string',
            'invigilator1'   => 'required|string',
            'ufm_by'         => 'required|string',
            'authority_name' => 'required|string',
            'ufm_copy_no2'   => 'required|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $admission = DB::table('admissions')->where('roll_no', $req->roll_no)->first();
        if (!$admission) return response()->json(['message' => 'Roll number not found.'], 404);

        $id = DB::table('exam_ufm')->insertGetId(array_merge(
            $req->all(), ['admission_id' => $admission->id, 'created_at' => now(), 'updated_at' => now()]
        ));
        return response()->json(['id' => $id, 'message' => "UFM recorded. Ref No: UFM/{$id}"], 201);
    }

    public function conductP3Index(Request $req)
    {
        return response()->json(
            DB::table('exam_ufm as u')
                ->join('admissions as a', 'a.id', 'u.admission_id')
                ->join('students as s', 's.id', 'a.student_id')
                ->select('u.*', 's.name', 's.father_name', 'a.roll_no')
                ->where('u.session_year', $req->session_year ?? $this->sessionYear())
                ->paginate(30)
        );
    }

    // P4 — Absent
    public function conductP4Store(Request $req)
    {
        $v = Validator::make($req->all(), [
            'roll_no'        => 'required|string',
            'paper_code'     => 'required|string',
            'exam_date'      => 'required|date',
            'inning_id'      => 'required|exists:exam_innings,id',
            'room_id'        => 'required|exists:exam_rooms,id',
            'issued_copy_no' => 'required|string',
            'invigilator1'   => 'required|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $admission = DB::table('admissions')->where('roll_no', $req->roll_no)->first();
        if (!$admission) return response()->json(['message' => 'Roll number not found.'], 404);

        $id = DB::table('exam_absent')->insertGetId(array_merge(
            $req->all(), ['admission_id' => $admission->id, 'created_at' => now(), 'updated_at' => now()]
        ));
        return response()->json(['id' => $id, 'message' => "ABS recorded. Ref No: ABS/{$id}"], 201);
    }

    public function conductP4Index(Request $req)
    {
        return response()->json(
            DB::table('exam_absent as ab')
                ->join('admissions as a', 'a.id', 'ab.admission_id')
                ->join('students as s', 's.id', 'a.student_id')
                ->select('ab.*', 's.name', 's.father_name', 'a.roll_no')
                ->where('ab.session_year', $req->session_year ?? $this->sessionYear())
                ->paginate(30)
        );
    }

    // P9 — Create Packet
    public function conductP9Store(Request $req)
    {
        $v = Validator::make($req->all(), [
            'session_year' => 'required|string',
            'exam_date'    => 'required|date',
            'inning_id'    => 'required|exists:exam_innings,id',
            'center_code'  => 'required|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('exam_packets')->insertGetId(array_merge($req->all(), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => "Packet No. P9/{$id} created."], 201);
    }

    public function conductP9Index(Request $req)
    {
        return response()->json(
            DB::table('exam_packets')
                ->where('session_year', $req->session_year ?? $this->sessionYear())
                ->orderBy('exam_date')->paginate(30)
        );
    }

    // ══════════════════════════════════════════════════════════════
    // 6. NOMINAL ROLL
    // ══════════════════════════════════════════════════════════════
    public function nominalRollIndex(Request $req)
    {
        return response()->json(
            DB::table('exam_forms as ef')
                ->join('admissions as a', 'a.id', 'ef.admission_id')
                ->join('students as s', 's.id', 'a.student_id')
                ->join('programs as p', 'p.id', 'a.program_id')
                ->leftJoin('exam_form_papers as efp', 'efp.exam_form_id', 'ef.id')
                ->select('ef.*', 's.name', 's.father_name', 's.mother_name', 's.gender', 's.dob',
                         'a.roll_no', 'a.enrollment_no', 'p.short_name as class', 'efp.paper_code')
                ->where('ef.session_year', $req->session_year ?? $this->sessionYear())
                ->when($req->program_id,  fn($q) => $q->where('a.program_id',  $req->program_id))
                ->when($req->semester_no, fn($q) => $q->where('ef.semester_no', $req->semester_no))
                ->when($req->exam_type,   fn($q) => $q->where('ef.exam_type',   $req->exam_type))
                ->where('ef.status', 'Accepted')
                ->orderBy('a.roll_no')
                ->paginate(50)
        );
    }

    public function nominalRollUpdate(Request $req, $id)
    {
        DB::table('exam_forms')->where('id', $id)->update(array_merge(
            $req->only(['form_id', 'enrollment_no', 'paper_code']),
            ['updated_at' => now()]
        ));
        return response()->json(['message' => 'Updated.']);
    }

    // ══════════════════════════════════════════════════════════════
    // 7. RESULT UPDATE
    // ══════════════════════════════════════════════════════════════
    public function resultIndex(Request $req)
    {
        return response()->json(
            DB::table('exam_forms as ef')
                ->join('admissions as a', 'a.id', 'ef.admission_id')
                ->join('students as s', 's.id', 'a.student_id')
                ->join('programs as p', 'p.id', 'a.program_id')
                ->select('ef.*', 's.name', 's.father_name', 's.gender',
                         'a.roll_no', 'a.account_no', 'a.enrollment_no', 'p.short_name as class')
                ->where('ef.session_year', $req->session_year ?? $this->sessionYear())
                ->when($req->program_id,  fn($q) => $q->where('a.program_id',  $req->program_id))
                ->when($req->semester_no, fn($q) => $q->where('ef.semester_no', $req->semester_no))
                ->when($req->exam_type,   fn($q) => $q->where('ef.exam_type',   $req->exam_type))
                ->when($req->gender,      fn($q) => $q->where('s.gender',       $req->gender))
                ->where('ef.status', 'Accepted')
                ->orderBy('a.roll_no')
                ->paginate(50)
        );
    }

    public function resultUpdate(Request $req, $id)
    {
        $v = Validator::make($req->all(), [
            'result' => 'required|in:Pass,Promote,Not Awarded,Fail,Absent',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        DB::table('exam_forms')->where('id', $id)
            ->update(['result' => $req->result, 'updated_at' => now()]);
        return response()->json(['message' => 'Result updated.']);
    }

    public function resultBulkUpdate(Request $req)
    {
        foreach ($req->results as $item) {
            DB::table('exam_forms')->where('id', $item['id'])
                ->update(['result' => $item['result'], 'updated_at' => now()]);
        }
        return response()->json(['message' => count($req->results) . ' records updated.']);
    }

    // ══════════════════════════════════════════════════════════════
    // 8. MARKSHEET DISTRIBUTION
    // ══════════════════════════════════════════════════════════════
    public function marksheetIndex(Request $req)
    {
        return response()->json(
            DB::table('exam_forms as ef')
                ->join('admissions as a', 'a.id', 'ef.admission_id')
                ->join('students as s', 's.id', 'a.student_id')
                ->join('programs as p', 'p.id', 'a.program_id')
                ->select('ef.*', 's.name', 's.father_name', 's.gender',
                         'a.roll_no', 'a.account_no', 'a.enrollment_no', 'p.short_name as class')
                ->where('ef.session_year', $req->session_year ?? $this->sessionYear())
                ->when($req->program_id,  fn($q) => $q->where('a.program_id',  $req->program_id))
                ->when($req->semester_no, fn($q) => $q->where('ef.semester_no', $req->semester_no))
                ->when($req->exam_type,   fn($q) => $q->where('ef.exam_type',   $req->exam_type))
                ->when($req->gender,      fn($q) => $q->where('s.gender',       $req->gender))
                ->where('ef.status', 'Accepted')
                ->orderBy('a.roll_no')
                ->paginate(50)
        );
    }

    public function marksheetUpdateAvailability(Request $req, $id)
    {
        DB::table('exam_forms')->where('id', $id)
            ->update(['marksheet_available' => $req->available, 'updated_at' => now()]);
        return response()->json(['message' => 'Updated.']);
    }

    // ══════════════════════════════════════════════════════════════
    // 9. STATISTICS
    // ══════════════════════════════════════════════════════════════
    public function examineeStats(Request $req)
    {
        $sessionYear = $req->session_year ?? $this->sessionYear();

        $data = DB::table('exam_forms as ef')
            ->join('admissions as a', 'a.id', 'ef.admission_id')
            ->join('students as s', 's.id', 'a.student_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->select('p.short_name as class', 'p.level', 's.gender',
                     DB::raw('count(*) as total'))
            ->where('ef.session_year', $sessionYear)
            ->when($req->program_id, fn($q) => $q->where('a.program_id', $req->program_id))
            ->where('ef.status', 'Accepted')
            ->groupBy('p.id', 'p.short_name', 'p.level', 's.gender')
            ->get();

        return response()->json($data);
    }

    public function subjectStats(Request $req)
    {
        $sessionYear = $req->session_year ?? $this->sessionYear();

        $data = DB::table('exam_form_papers as efp')
            ->join('exam_forms as ef', 'ef.id', 'efp.exam_form_id')
            ->join('admissions as a', 'a.id', 'ef.admission_id')
            ->join('students as s', 's.id', 'a.student_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->join('subjects as sub', 'sub.id', 'efp.subject_id')
            ->select('p.short_name as class', 'sub.name as subject',
                     'efp.exam_type', DB::raw('count(*) as total'))
            ->where('ef.session_year', $sessionYear)
            ->when($req->program_id, fn($q) => $q->where('a.program_id', $req->program_id))
            ->when($req->semester_no, fn($q) => $q->where('ef.semester_no', $req->semester_no))
            ->when($req->gender, fn($q) => $q->where('s.gender', $req->gender))
            ->where('ef.status', 'Accepted')
            ->groupBy('p.id', 'p.short_name', 'sub.id', 'sub.name', 'efp.exam_type')
            ->orderBy('p.short_name')->orderBy('sub.name')
            ->get();

        return response()->json($data);
    }

    // ══════════════════════════════════════════════════════════════
    // 10. ADD OTHER EXAM CENTRE
    // ══════════════════════════════════════════════════════════════
    public function examCentreIndex()
    {
        return response()->json(DB::table('exam_centres')->orderBy('center_code')->get());
    }

    public function examCentreStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'center_code'  => 'required|string|max:20|unique:exam_centres,center_code',
            'center_name'  => 'required|string|max:255',
            'college_code' => 'nullable|string|max:20',
            'college_name' => 'nullable|string|max:255',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('exam_centres')->insertGetId(array_merge($req->all(), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Centre added.'], 201);
    }

    public function examCentreUpdate(Request $req, $id)
    {
        DB::table('exam_centres')->where('id', $id)->update(array_merge(
            $req->only(['center_code','center_name','college_code','college_name']),
            ['updated_at' => now()]
        ));
        return response()->json(['message' => 'Updated.']);
    }

    public function examCentreDestroy($id)
    {
        DB::table('exam_centres')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // Students at centre
    public function examCentreStudents(Request $req)
    {
        return response()->json(
            DB::table('exam_forms as ef')
                ->join('admissions as a', 'a.id', 'ef.admission_id')
                ->join('students as s', 's.id', 'a.student_id')
                ->join('programs as p', 'p.id', 'a.program_id')
                ->leftJoin('exam_form_papers as efp', 'efp.exam_form_id', 'ef.id')
                ->select('ef.*', 's.name', 's.father_name', 's.gender',
                         'a.roll_no', 'p.short_name as class', 'efp.paper_code')
                ->where('ef.center_code', $req->center_code)
                ->when($req->program_id,  fn($q) => $q->where('a.program_id',  $req->program_id))
                ->when($req->semester_no, fn($q) => $q->where('ef.semester_no', $req->semester_no))
                ->when($req->exam_type,   fn($q) => $q->where('ef.exam_type',   $req->exam_type))
                ->paginate(50)
        );
    }

    // Lookup student by roll_no (used in P3/P4 forms)
    public function lookupStudent(Request $req)
    {
        $admission = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->where('a.roll_no', $req->roll_no)
            ->select('a.*', 's.name', 's.father_name', 's.mobile', 's.address', 'p.short_name as class')
            ->first();

        if (!$admission) return response()->json(['message' => 'Not found.'], 404);

        $examForm = DB::table('exam_forms')
            ->where('admission_id', $admission->id)
            ->where('session_year', $req->session_year ?? $this->sessionYear())
            ->first();

        $papers = $examForm
            ? DB::table('exam_form_papers as efp')
                ->join('subjects as sub', 'sub.id', 'efp.subject_id')
                ->where('efp.exam_form_id', $examForm->id)
                ->select('efp.paper_code', 'sub.name as subject_name')
                ->get()
            : [];

        return response()->json([
            'student'  => $admission,
            'examForm' => $examForm,
            'papers'   => $papers,
        ]);
    }
}
