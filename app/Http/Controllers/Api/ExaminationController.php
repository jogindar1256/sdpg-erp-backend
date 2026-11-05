<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesStudentIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * REBUILT. This entire module referenced 11 tables that were never
 * migrated — applied now in 2026_07_20_110000_create_examination_tables.php,
 * matching this controller's own (already coherent) column usage. Also
 * fixed: students has no name/father_name/mother_name/dob/address (those
 * live on direct_registrations / students.permanent_address), and
 * admissions has no roll_no/enrollment_no (real equivalents are
 * students.university_roll_no and students.enrollment_no — admissions.
 * account_no is real and unchanged).
 */
class ExaminationController extends Controller
{
    use ResolvesStudentIdentity;

    // ══════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════
    private function sessionYear(): string
    {
        return request('session_year', \App\Support\AcademicSession::current());
    }

    /** Shared student-identity join, applied wherever 's.name'/'s.father_name' were used. */
    private function withIdentity($query, string $studentAlias = 's')
    {
        $latestReg = $this->latestRegistrationSub();
        return $query
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', "{$studentAlias}.user_id")
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id');
    }

    private function identitySelect(string $studentAlias = 's'): array
    {
        return [
            "{$studentAlias}.personal_info->first_name as first_name", "{$studentAlias}.personal_info->middle_name as middle_name", "{$studentAlias}.personal_info->last_name as last_name",
            'dr.name as reg_name', 'dr.father_name', 'dr.mother_name',
        ];
    }

    /** Exam Form ID Entry is only for fresh students: Regular, Single Subject (SS), Single Subject 3rd only (SS3). */
    private const FORM_ID_EXAM_TYPES = ['Regular', 'SS', 'SS3'];

    /** Search box shared by Accept Exam Form / Exam Form ID Entry. Needs the s, sa, dr and ef aliases. */
    private function applyFormSearch($q, ?string $term)
    {
        $term = trim((string) $term);
        if ($term === '') return $q;
        $like = '%' . $term . '%';
        return $q->where(function ($w) use ($like) {
            $w->where('sa.application_no', 'ilike', $like)
              ->orWhere('s.university_roll_no', 'ilike', $like)
              ->orWhere('s.enrollment_no', 'ilike', $like)
              ->orWhere('s.student_uid', 'ilike', $like)
              ->orWhere('sa.student_uid', 'ilike', $like)
              ->orWhere('s.mobile', 'ilike', $like)
              ->orWhere('dr.name', 'ilike', $like)
              ->orWhere('dr.registration_no', 'ilike', $like)
              ->orWhere('ef.form_id', 'ilike', $like);
        });
    }

    /** Back-fill the Class A/C No of the admissions on screen (those created before it was assigned). */
    private function backfillAcNo($rows): void
    {
        $svc = app(\App\Services\AdmissionNumberService::class);
        foreach ($rows as $r) {
            if (empty($r->account_no) && !empty($r->admission_id)) {
                $svc->ensureClassAcNo((int) $r->admission_id);
                $r->account_no = DB::table('admissions')->where('id', $r->admission_id)->value('account_no');
            }
        }
    }

    private function decorate($row)
    {
        if (!$row) return $row;
        $row->name = !empty($row->reg_name) ? $row->reg_name
            : trim(implode(' ', array_filter([$row->first_name ?? null, $row->middle_name ?? null, $row->last_name ?? null])));
        return $row;
    }

    private function decorateCollection($rows)
    {
        foreach ($rows as $r) $this->decorate($r);
        return $rows;
    }

    // ══════════════════════════════════════════════════════════════
    // 1. ACCEPT EXAM FORM
    // ══════════════════════════════════════════════════════════════
    public function acceptFormIndex(Request $req)
    {
        $session = $req->session_year ?? $this->sessionYear();
        // Verified admissions appear here as Pending exam forms (also back-fills ones verified earlier).
        if ($req->program_id) {
            \App\Support\ExamFormSeeder::syncFor($session, (int) $req->program_id, $req->semester_no ?: null);
        }

        $q = $this->withIdentity(
            DB::table('exam_forms as ef')
                ->join('admissions as a', 'a.id', 'ef.admission_id')
                ->join('students as s', 's.id', 'a.student_id')
                ->join('programs as p', 'p.id', 'a.program_id')
                ->leftJoin('student_applications as sa', 'sa.id', 'a.application_id')
                ->leftJoin('direct_registrations as drc', 'drc.id', 'sa.direct_registration_id')
        )
            ->select(array_merge(['ef.*', 's.university_roll_no as roll_no', 's.mobile', 'sa.application_no', 'sa.id as application_id', 'drc.unique_code as code', 'a.account_no', 'a.id as admission_id', 'p.short_name as course'], $this->identitySelect()))
            ->where('ef.session_year', $session)
            ->tap(fn($q) => $this->applyFormSearch($q, $req->search))
            ->when($req->program_id,  fn($q) => $q->where('a.program_id',  $req->program_id))
            ->when($req->semester_no, fn($q) => $q->where('ef.semester_no', $req->semester_no))
            ->when($req->exam_type,   fn($q) => $q->where('ef.exam_type',   $req->exam_type))
            ->when($req->status,      fn($q) => $q->where('ef.status',      $req->status))
            ->orderBy('s.university_roll_no')->orderBy('ef.id');

        $records = $q->paginate(50);
        $records->getCollection()->transform(fn ($r) => $this->decorate($r));
        $this->backfillAcNo($records->getCollection());

        return response()->json($records);
    }

    public function acceptFormUpdate(Request $req, $id)
    {
        $v = Validator::make($req->all(), [
            'status' => 'required|in:Accepted,Pending,Rejected',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $current = DB::table('exam_forms')->where('id', $id)->value('status');
        $update = ['status' => $req->status, 'updated_at' => now()];
        if ($current === 'Accepted' && $req->status !== 'Accepted') {
            $update['reversed_at'] = now(); // a later re-accept is password-gated
            // A reversed form leaves the nominal roll too.
            DB::table('exam_nominal_roll_entries')->where('exam_form_id', $id)->delete();
        }
        DB::table('exam_forms')->where('id', $id)->update($update);

        return response()->json(['message' => 'Status updated.']);
    }

    // Accepts exactly the exam forms the office ticked (2 or more) — never "everything in the
    // filter". Already-accepted forms are skipped.
    public function acceptFormBulk(Request $req)
    {
        $v = Validator::make($req->all(), ['ids' => 'required|array|min:2', 'ids.*' => 'integer']);
        if ($v->fails()) return response()->json(['message' => 'Select at least 2 exam forms.', 'errors' => $v->errors()], 422);

        $n = DB::table('exam_forms')
            ->whereIn('id', $req->ids)
            ->where('status', '!=', 'Accepted')
            ->update(['status' => 'Accepted', 'updated_at' => now()]);

        return response()->json(['message' => "{$n} exam form(s) accepted.", 'accepted' => $n]);
    }

    // ══════════════════════════════════════════════════════════════
    // 2. EXAM FORM ID ENTRY
    // ══════════════════════════════════════════════════════════════
    // Accepted exam forms only — Form ID is entered after the form is accepted.
    public function formIdIndex(Request $req, bool $restrictTypes = true)
    {
        $q = $this->withIdentity(
            DB::table('exam_forms as ef')
                ->join('admissions as a', 'a.id', 'ef.admission_id')
                ->join('students as s', 's.id', 'a.student_id')
                ->join('programs as p', 'p.id', 'a.program_id')
                ->leftJoin('student_applications as sa', 'sa.id', 'a.application_id')
                ->leftJoin('direct_registrations as drc', 'drc.id', 'sa.direct_registration_id')
        )
            ->select(array_merge(['ef.*', 's.personal_info->gender as gender', 's.university_roll_no as roll_no', 'a.account_no', 'sa.application_no', 'sa.id as application_id', 'drc.unique_code as code', 's.enrollment_no', 'p.short_name as course'], $this->identitySelect()))
            ->where('ef.status', 'Accepted')
            ->where('ef.session_year', $req->session_year ?? $this->sessionYear())
            ->when($restrictTypes, fn($q) => $q->whereIn('ef.exam_type', self::FORM_ID_EXAM_TYPES))
            ->tap(fn($q) => $this->applyFormSearch($q, $req->search))
            ->when($req->program_id,  fn($q) => $q->where('a.program_id',  $req->program_id))
            ->when($req->semester_no, fn($q) => $q->where('ef.semester_no', $req->semester_no))
            ->when($req->exam_type,   fn($q) => $q->where('ef.exam_type',   $req->exam_type))
            ->orderBy('s.university_roll_no')->orderBy('ef.id');

        $records = $q->paginate(50);
        $records->getCollection()->transform(fn ($r) => $this->decorate($r));

        return $records;
    }

    // ══════════════════════════════════════════════════════════════
    // 2b. UNIVERSITY ROLL NO. UPDATE — roll no / enrolment no (students) + form id
    // ══════════════════════════════════════════════════════════════
    public function rollUpdateIndex(Request $req)
    {
        return $this->formIdIndex($req, false); // roll-no update covers every exam type
    }

    public function rollUpdateUpdate(Request $req, $id)
    {
        $v = Validator::make($req->all(), [
            'university_roll_no' => 'nullable|string|max:30',
            'enrollment_no' => 'nullable|string|max:50',
            'form_id' => 'nullable|string|max:50',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $form = DB::table('exam_forms as ef')->join('admissions as a', 'a.id', 'ef.admission_id')
            ->where('ef.id', $id)->first(['ef.id', 'ef.session_year', 'a.student_id', 'a.program_id']);
        if (!$form) return response()->json(['message' => 'Exam form not found.'], 404);

        $update = [];
        if ($req->has('university_roll_no')) {
            $roll = trim((string) $req->university_roll_no) ?: null;
            if ($roll && DB::table('students as s')
                ->join('admissions as a', 'a.student_id', 's.id')
                ->where('s.university_roll_no', $roll)->where('s.id', '!=', $form->student_id)
                ->where('a.program_id', $form->program_id)->where('a.academic_year', $form->session_year)
                ->whereNull('s.deleted_at')->exists()) {
                return response()->json(['errors' => ['university_roll_no' => ['This roll number is already given to another student of this course and session.']]], 422);
            }
            $update['university_roll_no'] = $roll;
        }
        if ($req->has('enrollment_no')) {
            $enr = trim((string) $req->enrollment_no) ?: null;
            if ($enr && DB::table('students')->where('enrollment_no', $enr)->where('id', '!=', $form->student_id)->exists()) {
                return response()->json(['errors' => ['enrollment_no' => ['This enrolment number already belongs to another student.']]], 422);
            }
            $update['enrollment_no'] = $enr;
        }
        if ($update) {
            DB::table('students')->where('id', $form->student_id)->update($update + ['updated_at' => now()]);
        }
        if ($req->has('form_id')) {
            DB::table('exam_forms')->where('id', $id)->update(['form_id' => trim((string) $req->form_id) ?: null, 'updated_at' => now()]);
        }

        return response()->json(['message' => 'Updated.']);
    }

    /** "Delete" = clear the roll no. and enrolment no. (the student and exam form stay). */
    public function rollUpdateClear(Request $req, $id)
    {
        $studentId = DB::table('exam_forms as ef')->join('admissions as a', 'a.id', 'ef.admission_id')->where('ef.id', $id)->value('a.student_id');
        if (!$studentId) return response()->json(['message' => 'Exam form not found.'], 404);
        DB::table('students')->where('id', $studentId)->update(['university_roll_no' => null, 'enrollment_no' => null, 'updated_at' => now()]);
        return response()->json(['message' => 'Roll no. and enrolment no. cleared.']);
    }

    public function formIdUpdate(Request $req, $id)
    {
        $v = Validator::make($req->all(), ['form_id' => 'required|string|max:50']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $type = DB::table('exam_forms')->where('id', $id)->value('exam_type');
        if (!in_array($type, self::FORM_ID_EXAM_TYPES, true)) {
            return response()->json(['message' => 'Form ID is entered only for Regular, SS and SS 3rd-only exam forms.'], 422);
        }
        DB::table('exam_forms')->where('id', $id)
            ->update(['form_id' => $req->form_id, 'updated_at' => now()]);
        return response()->json(['message' => 'Form ID saved.']);
    }

    // ══════════════════════════════════════════════════════════════
    // 3. EXAM SCHEDULE — ENTRY
    // ══════════════════════════════════════════════════════════════

    /**
     * How many examinees (from accepted exam forms) sit a given paper —
     * broken down Regular / Back Paper / Total. Joined onto exam_schedules
     * by paper_code + session_year + semester_no + program_id, which is
     * the same key the "entry"/search grids group by. A paper nobody has
     * filled a form for yet (schedule entered ahead of forms) reads 0, not
     * null, so the UI shows a real count rather than '-' once forms exist.
     */
    private function withExamineeCounts($query, string $scheduleAlias = 'es')
    {
        $counts = DB::table('exam_form_papers as efp')
            ->join('exam_forms as ef', 'ef.id', 'efp.exam_form_id')
            ->join('admissions as adm', 'adm.id', 'ef.admission_id')
            ->where('ef.status', 'Accepted')
            ->select(
                'efp.paper_code', 'ef.session_year', 'ef.semester_no', 'adm.program_id',
                DB::raw("count(*) filter (where efp.exam_type = 'Regular') as regular_count"),
                DB::raw("count(*) filter (where efp.exam_type = 'Back Paper') as bp_count"),
                DB::raw('count(*) as total_count')
            )
            ->groupBy('efp.paper_code', 'ef.session_year', 'ef.semester_no', 'adm.program_id');

        return $query->leftJoinSub($counts, 'cnt', function ($join) use ($scheduleAlias) {
            $join->on('cnt.paper_code', "{$scheduleAlias}.paper_code")
                ->on('cnt.session_year', "{$scheduleAlias}.session_year")
                ->on('cnt.semester_no', "{$scheduleAlias}.semester_no")
                ->on('cnt.program_id', "{$scheduleAlias}.program_id");
        })->selectRaw('coalesce(cnt.regular_count, 0) as regular_count, coalesce(cnt.bp_count, 0) as bp_count, coalesce(cnt.total_count, 0) as total_count');
    }

    public function scheduleIndex(Request $req)
    {
        $q = $this->withExamineeCounts(
            DB::table('exam_schedules as es')
                ->join('subjects as sub', 'sub.id', 'es.subject_id')
                ->join('programs as p', 'p.id', 'es.program_id')
                ->select('es.*', 'sub.name as subject_name', 'p.short_name as course')
                ->where('es.session_year', $req->session_year ?? $this->sessionYear())
                ->when($req->program_id,  fn($q) => $q->where('es.program_id',  $req->program_id))
                ->when($req->semester_no, fn($q) => $q->where('es.semester_no', $req->semester_no))
                ->when($req->exam_mode,   fn($q) => $q->where('es.exam_mode',   $req->exam_mode))
        );

        return response()->json($q->orderBy('es.exam_date')->orderBy('es.inning')->get());
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

        $data = $v->validated();
        $duplicate = DB::table('exam_schedules')
            ->where('session_year', $data['session_year'])
            ->where('program_id', $data['program_id'])
            ->where('semester_no', $data['semester_no'])
            ->where('exam_mode', $data['exam_mode'])
            ->where('paper_code', $data['paper_code'])
            ->exists();
        if ($duplicate) {
            return response()->json(['message' => 'This paper already has a schedule entry for this session/semester/exam mode.'], 422);
        }

        $id = DB::table('exam_schedules')->insertGetId(array_merge($data, [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Schedule entry saved.'], 201);
    }

    public function scheduleDestroy($id)
    {
        DB::table('exam_schedules')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    public function scheduleSearch(Request $req)
    {
        $q = $this->withExamineeCounts(
            DB::table('exam_schedules as es')
                ->join('subjects as sub', 'sub.id', 'es.subject_id')
                ->join('programs as p', 'p.id', 'es.program_id')
                ->select('es.*', 'sub.name as subject_name', 'p.short_name as course', DB::raw('(select sp.paper_name from subject_papers sp where sp.paper_code = es.paper_code and sp.program_id = es.program_id limit 1) as paper_name'))
                ->where('es.session_year', $req->session_year ?? $this->sessionYear())
        );

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

    /** Suggestion lists for the schedule search screens (dates, innings, paper codes). */
    public function scheduleOptions(Request $req)
    {
        $session = $req->session_year ?? $this->sessionYear();
        $base = fn () => DB::table('exam_schedules as es')->where('es.session_year', $session);

        $dates = $base()->distinct()->orderBy('es.exam_date')->pluck('es.exam_date')
            ->map(fn ($d) => substr((string) $d, 0, 10))->unique()->values();
        $innings = $base()->distinct()->orderBy('es.inning')->pluck('es.inning')->values();
        $papers = $base()
            ->join('subjects as sub', 'sub.id', 'es.subject_id')
            ->join('programs as p', 'p.id', 'es.program_id')
            ->select('es.paper_code', 'sub.name as subject_name', 'p.short_name as course', 'es.semester_no',
                DB::raw('(select sp.paper_name from subject_papers sp where sp.paper_code = es.paper_code and sp.program_id = es.program_id limit 1) as paper_name'))
            ->orderBy('es.paper_code')->get()->unique('paper_code')->values();

        return response()->json(['dates' => $dates, 'innings' => $innings, 'papers' => $papers]);
    }

    /** Paper codes of one subject (for the schedule entry form dropdown). */
    public function scheduleSubjectPapers(Request $req)
    {
        $rows = DB::table('subject_papers')
            ->where('program_id', $req->program_id)
            ->where('subject_id', $req->subject_id)
            ->when($req->semester_no, fn ($q) => $q->where('semester_no', (string) $req->semester_no))
            ->whereNotNull('paper_code')
            ->orderBy('paper_code')
            ->get(['paper_code', 'paper_name'])
            ->unique('paper_code')->values();

        return response()->json($rows);
    }

    /**
     * Paper-code lookup for the schedule entry form. A numeric paper code
     * resolves to the paper (name, course, semester, subject) plus the
     * examinee counts for the session. The same code can exist under more
     * than one course, so this returns every match and the UI lets the user
     * pick when there is more than one.
     */
    public function schedulePaperLookup(Request $req)
    {
        $code = strtoupper(trim((string) $req->paper_code));
        if ($code === '') return response()->json([]);
        $session = $req->session_year ?? $this->sessionYear();

        // Typeahead: codes starting with what has been typed so far.
        if ($req->boolean('suggest')) {
            return response()->json(DB::table('subject_papers as sp')
                ->join('programs as p', 'p.id', 'sp.program_id')
                ->whereRaw('upper(trim(sp.paper_code)) like ?', [str_replace(['%', '_'], '', $code) . '%'])
                ->orderBy('sp.paper_code')->orderBy('p.short_name')->limit(10)
                ->get(['sp.paper_code', 'sp.paper_name', 'sp.semester_no', 'p.short_name as course']));
        }

        $papers = DB::table('subject_papers as sp')
            ->join('programs as p', 'p.id', 'sp.program_id')
            ->join('subjects as sub', 'sub.id', 'sp.subject_id')
            ->whereRaw('upper(trim(sp.paper_code)) = ?', [$code])
            ->orderBy('p.short_name')->orderBy('sp.semester_no')
            ->get(['sp.paper_code', 'sp.paper_name', 'sp.program_id', 'sp.semester_no', 'sub.id as subject_id', 'sub.name as subject_name', 'p.short_name as course']);

        $out = $papers->map(function ($pp) use ($code, $session) {
            $lower = "lower(coalesce(s.personal_info->>'gender', ''))";
            $c = DB::table('exam_form_papers as efp')
                ->join('exam_forms as ef', 'ef.id', 'efp.exam_form_id')
                ->join('admissions as a', 'a.id', 'ef.admission_id')
                ->join('students as s', 's.id', 'a.student_id')
                ->whereRaw('upper(trim(efp.paper_code)) = ?', [$code])
                ->where('ef.session_year', $session)
                ->where('ef.semester_no', (string) $pp->semester_no)
                ->where('a.program_id', $pp->program_id)
                ->where('ef.status', 'Accepted')
                ->selectRaw("
                    count(*) filter (where efp.exam_type = 'Regular') as regular,
                    count(*) filter (where efp.exam_type = 'Back Paper') as back_paper,
                    count(*) filter (where ef.exam_type = 'SS') as ss,
                    count(*) filter (where ef.exam_type = 'SS3') as ss3,
                    count(*) filter (where $lower = 'male') as male,
                    count(*) filter (where $lower = 'female') as female,
                    count(*) filter (where $lower not in ('male', 'female')) as trans,
                    count(*) as total")
                ->first();

            return [
                'paper_code' => $pp->paper_code, 'paper_name' => $pp->paper_name,
                'program_id' => $pp->program_id, 'course' => $pp->course,
                'semester_no' => (string) $pp->semester_no,
                'subject_id' => $pp->subject_id, 'subject_name' => $pp->subject_name,
                'counts' => collect((array) $c)->map(fn ($v) => (int) $v),
            ];
        })->values();

        return response()->json($out);
    }

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

        $id = DB::table('exam_rooms')->insertGetId(array_merge($v->validated(), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Room saved.'], 201);
    }

    public function roomMasterUpdate(Request $req, $id)
    {
        DB::table('exam_rooms')->where('id', $id)->update(array_merge(
            $req->only(['room_no', 'building_name', 'rows', 'columns', 'capacity', 'extra_seat', 'is_active']),
            ['updated_at' => now()]
        ));
        return response()->json(['message' => 'Updated.']);
    }

    public function roomMasterDestroy($id)
    {
        DB::table('exam_rooms')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

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

        $id = DB::table('exam_innings')->insertGetId(array_merge($v->validated(), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Inning saved.'], 201);
    }

    public function inningUpdate(Request $req, $id)
    {
        DB::table('exam_innings')->where('id', $id)->update(array_merge(
            $req->only(['inning_name', 'time_start', 'time_end']),
            ['updated_at' => now()]
        ));
        return response()->json(['message' => 'Updated.']);
    }

    public function inningDestroy($id)
    {
        DB::table('exam_innings')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // ══════════════════════════════════════════════════════════════
    // SEATING PLAN — per exam date + inning. Rooms are picked per class
    // by hand; classes may share a room (seats continue after the previous
    // class); seats are given in roll-number order; a room holds
    // capacity + extra seats.
    // ══════════════════════════════════════════════════════════════

    /** Schedule entries (classes) written on the given date + inning. */
    private function seatingSchedules(string $session, string $date, int $inningId)
    {
        $inning = DB::table('exam_innings')->where('id', $inningId)->value('inning_name');
        return DB::table('exam_schedules as es')
            ->join('programs as p', 'p.id', 'es.program_id')
            ->join('subjects as sub', 'sub.id', 'es.subject_id')
            ->where('es.session_year', $session)->where('es.exam_date', $date)->where('es.inning', $inning)
            ->orderBy('p.short_name')->orderBy('es.semester_no')->orderBy('es.paper_code')
            ->get(['es.id', 'es.program_id', 'es.semester_no', 'es.exam_mode', 'es.paper_code', 'p.short_name as course', 'sub.name as subject']);
    }

    /** Accepted examinees of one schedule entry, in roll-number order. */
    private function seatingExaminees(object $sch, string $session, array $types, array $genders)
    {
        $q = DB::table('exam_form_papers as efp')
            ->join('exam_forms as ef', 'ef.id', 'efp.exam_form_id')
            ->join('admissions as a', 'a.id', 'ef.admission_id')
            ->join('students as s', 's.id', 'a.student_id')
            ->whereRaw('upper(trim(efp.paper_code)) = ?', [strtoupper(trim($sch->paper_code))])
            ->where('ef.session_year', $session)
            ->where('ef.semester_no', $sch->semester_no)
            ->where('a.program_id', $sch->program_id)
            ->where('ef.status', 'Accepted')
            ->whereIn('efp.exam_type', $types ?: ['Regular', 'Back Paper']);

        $g = array_values(array_intersect($genders, ['Male', 'Female', 'Trans']));
        if ($g && count($g) < 3) {
            $q->where(function ($w) use ($g) {
                $lower = "lower(coalesce(s.personal_info->>'gender', ''))";
                if (in_array('Male', $g, true)) $w->orWhereRaw("$lower = 'male'");
                if (in_array('Female', $g, true)) $w->orWhereRaw("$lower = 'female'");
                if (in_array('Trans', $g, true)) $w->orWhereRaw("$lower not in ('male', 'female')");
            });
        }

        return $q->select('s.id as student_id', 's.university_roll_no as roll_no')
            ->distinct()->orderByRaw('s.university_roll_no asc nulls last')->orderBy('s.id')->get();
    }

    private function seatingInput(Request $req): array
    {
        return [
            $req->input('session_year', $this->sessionYear()),
            (string) $req->input('exam_date'),
            (int) $req->input('inning_id'),
            array_values(array_filter((array) $req->input('exam_types', ['Regular']))),
            array_values(array_filter((array) $req->input('genders', ['Male', 'Female', 'Trans']))),
        ];
    }

    /** GET seating-plan/classes — classes on that date + inning, examinee counts, room list and any saved plan. */
    public function seatingPlanClasses(Request $req)
    {
        $v = Validator::make($req->all(), ['exam_date' => 'required|date', 'inning_id' => 'required|exists:exam_innings,id']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);
        [$session, $date, $inningId, $types, $genders] = $this->seatingInput($req);

        $schedules = $this->seatingSchedules($session, $date, $inningId);
        $seated = DB::table('exam_seating')
            ->where('session_year', $session)->where('exam_date', $date)->where('inning_id', $inningId)
            ->selectRaw('paper_code, room_id, count(*) as seated, min(id) as first_id')
            ->groupBy('paper_code', 'room_id')->orderBy('first_id')->get()->groupBy('paper_code');

        $classes = $schedules->map(function ($sch) use ($session, $types, $genders, $seated) {
            $ex = $this->seatingExaminees($sch, $session, $types, $genders);
            // Why a count can be 0: accepted forms of this class / how many carry this paper.
            $accepted = DB::table('exam_forms as ef')->join('admissions as a', 'a.id', 'ef.admission_id')
                ->where('ef.session_year', $session)->where('ef.semester_no', $sch->semester_no)
                ->where('a.program_id', $sch->program_id)->where('ef.status', 'Accepted');
            return [
                'schedule_id' => $sch->id, 'course' => $sch->course, 'semester_no' => $sch->semester_no,
                'exam_mode' => $sch->exam_mode, 'subject' => $sch->subject, 'paper_code' => $sch->paper_code,
                'students' => $ex->count(),
                'no_roll' => $ex->whereNull('roll_no')->count(),
                'accepted_forms' => (clone $accepted)->count(),
                'forms_with_papers' => (clone $accepted)->whereExists(fn ($q) => $q->selectRaw('1')->from('exam_form_papers as p')->whereColumn('p.exam_form_id', 'ef.id'))->count(),
                'rooms' => ($seated->get($sch->paper_code) ?? collect())->map(fn ($r) => ['room_id' => (int) $r->room_id, 'seated' => (int) $r->seated])->values(),
            ];
        })->values();

        $rooms = DB::table('exam_rooms')->where('is_active', true)->orderBy('room_no')
            ->get(['id', 'room_no', 'building_name', 'rows', 'columns', 'capacity', 'extra_seat'])
            ->map(fn ($r) => (array) $r + ['total' => (int) $r->capacity + (int) ($r->extra_seat ?? 0)]);

        return response()->json(['classes' => $classes, 'rooms' => $rooms, 'saved' => $seated->isNotEmpty()]);
    }

    /**
     * POST seating-plan/save — {session_year, exam_date, inning_id, exam_types[], genders[],
     * classes: [{schedule_id, room_ids: []}]}. Replaces the plan of that date + inning.
     * Classes are seated in the order sent; each class fills its rooms in the order sent, roll-number order.
     */
    public function seatingPlanSave(Request $req)
    {
        $v = Validator::make($req->all(), [
            'exam_date' => 'required|date', 'inning_id' => 'required|exists:exam_innings,id',
            'classes' => 'required|array|min:1', 'classes.*.schedule_id' => 'required|integer',
            'classes.*.room_ids' => 'array', 'classes.*.room_ids.*' => 'integer|exists:exam_rooms,id',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);
        [$session, $date, $inningId, $types, $genders] = $this->seatingInput($req);

        $schedules = $this->seatingSchedules($session, $date, $inningId)->keyBy('id');
        $rooms = DB::table('exam_rooms')->where('is_active', true)->get()->keyBy('id');
        $used = [];   // room_id => seats taken so far
        $rows = [];
        $now = now();

        foreach ($req->input('classes') as $c) {
            $sch = $schedules->get((int) $c['schedule_id']);
            if (!$sch) {
                return response()->json(['message' => 'A selected class is not scheduled on this date and inning.'], 422);
            }
            $students = $this->seatingExaminees($sch, $session, $types, $genders)->values();
            if ($noRoll = $students->whereNull('roll_no')->count()) {
                return response()->json(['message' => "{$sch->course} · {$sch->subject} ({$sch->paper_code}): {$noRoll} student(s) have no University Roll No. — assign it in University Roll No. Update first."], 422);
            }
            $i = 0;
            foreach (array_values(array_unique($c['room_ids'] ?? [])) as $rid) {
                $room = $rooms->get($rid);
                if (!$room) continue;
                $free = (int) $room->capacity + (int) ($room->extra_seat ?? 0) - ($used[$rid] ?? 0);
                for (; $free > 0 && $i < $students->count(); $free--, $i++) {
                    $seat = ($used[$rid] = ($used[$rid] ?? 0) + 1);
                    $cols = max(1, (int) $room->columns);
                    $rows[] = [
                        'session_year' => $session, 'exam_date' => $date, 'inning_id' => $inningId,
                        'student_id' => $students[$i]->student_id, 'paper_code' => $sch->paper_code,
                        'room_id' => $rid, 'seat_no' => $seat,
                        'row_no' => intdiv($seat - 1, $cols) + 1, 'col_no' => ($seat - 1) % $cols + 1,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
            if ($i < $students->count()) {
                return response()->json(['message' => "{$sch->course} · {$sch->subject} ({$sch->paper_code}): " . ($students->count() - $i) . ' of ' . $students->count() . ' students have no seat — add a room or more capacity.'], 422);
            }
        }

        DB::transaction(function () use ($session, $date, $inningId, $rows) {
            DB::table('exam_seating')->where('session_year', $session)->where('exam_date', $date)->where('inning_id', $inningId)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('exam_seating')->insert($chunk);
            }
        });

        return response()->json(['message' => count($rows) . ' seats saved.', 'count' => count($rows)]);
    }

    /** DELETE seating-plan — remove the plan of that date + inning. */
    public function seatingPlanDelete(Request $req)
    {
        $v = Validator::make($req->all(), ['exam_date' => 'required|date', 'inning_id' => 'required|exists:exam_innings,id']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);
        [$session, $date, $inningId] = $this->seatingInput($req);
        $n = DB::table('exam_seating')->where('session_year', $session)->where('exam_date', $date)->where('inning_id', $inningId)->delete();
        return response()->json(['message' => "Plan deleted ({$n} seats removed).", 'count' => $n]);
    }

    /** GET seating-plan/students — seated students of a room (and optionally one paper) for that date + inning; all rooms without room_id. */
    public function seatingPlanStudents(Request $req)
    {
        $v = Validator::make($req->all(), ['exam_date' => 'required|date', 'inning_id' => 'required|exists:exam_innings,id']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);
        [$session, $date, $inningId] = $this->seatingInput($req);

        $rows = $this->withIdentity(
            DB::table('exam_seating as es')
                ->join('students as s', 's.id', 'es.student_id')
                ->join('exam_rooms as r', 'r.id', 'es.room_id')
                ->where('es.session_year', $session)->where('es.exam_date', $date)->where('es.inning_id', $inningId)
                ->when($req->room_id, fn ($q, $v) => $q->where('es.room_id', $v))
                ->when($req->paper_code, fn ($q, $v) => $q->where('es.paper_code', $v))
        )->orderBy('r.room_no')->orderBy('es.seat_no')
            ->get(array_merge(['es.paper_code', 'es.seat_no', 'r.room_no', 'r.building_name', 's.university_roll_no as roll_no', 's.mobile'], $this->identitySelect()));

        return response()->json($this->decorateCollection($rows)->values());
    }

    public function searchSeat(Request $req)
    {
        $v = Validator::make($req->all(), ['roll_no' => 'required|string']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $admission = $this->withIdentity(
            DB::table('admissions as a')
                ->join('students as s', 's.id', 'a.student_id')
                ->join('programs as p', 'p.id', 'a.program_id')
        )
            ->where('s.university_roll_no', $req->roll_no)
            ->select(array_merge(['a.*', 's.mobile', 's.address_info->permanent_address as address', 'p.short_name as course'], $this->identitySelect()))
            ->first();

        if (!$admission) return response()->json(['message' => 'Student not found.'], 404);
        $admission = $this->decorate($admission);

        $seats = DB::table('exam_seating as es')
            ->join('exam_rooms as r', 'r.id', 'es.room_id')
            ->where('es.student_id', $admission->student_id)
            ->where('es.session_year', $req->session_year ?? $this->sessionYear())
            ->select('es.*', 'r.room_no', 'r.building_name')
            ->get();

        return response()->json(['student' => $admission, 'seats' => $seats]);
    }

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
            'gender'       => 'nullable|in:male,female,transgender,All',
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
            ->when($req->gender && $req->gender !== 'All', fn($q) => $q->where('s.personal_info->gender', $req->gender))
            ->where('ef.status', 'Accepted')
            ->select('s.university_roll_no as roll_no', 's.personal_info->gender as gender')
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

    // ══════════════════════════════════════════════════════════════
    // CREATE P1 / CREATE PACKET NO. (P9)
    // One record per session + exam date + inning + centre; `lines` is the
    // exam schedule of that date + inning, snapshotted on save. P9 also
    // carries a packet number per paper (kept stable on re-save).
    // ══════════════════════════════════════════════════════════════

    private function conductTable(string $kind): string
    {
        return $kind === 'p9' ? 'exam_packets' : 'exam_conduct_p1';
    }

    /** Exam schedule of a date + inning: examinee counts and, from the saved seating plan, the rooms of every paper. */
    private function conductScheduleLines(string $session, string $date, int $inningId): array
    {
        $all = ['Regular', 'Back Paper'];
        $key = fn ($q) => $q->where('exam_date', $date)->where('inning_id', $inningId);

        $seated = DB::table('exam_seating as es')->join('exam_rooms as r', 'r.id', 'es.room_id')
            ->where('es.session_year', $session)->where('es.exam_date', $date)->where('es.inning_id', $inningId)
            ->groupBy('es.paper_code', 'es.room_id', 'r.room_no', 'r.building_name')
            ->orderBy('r.room_no')
            ->get(['es.paper_code', 'es.room_id', 'r.room_no', 'r.building_name', DB::raw('count(*) as seated')])
            ->groupBy('paper_code');
        $abs = $key(DB::table('exam_absent'))->groupBy('paper_code', 'room_id')
            ->get(['paper_code', 'room_id', DB::raw('count(*) as n')])->groupBy('paper_code');
        $ufm = $key(DB::table('exam_ufm'))->groupBy('paper_code', 'room_id')
            ->get(['paper_code', 'room_id', DB::raw('count(*) as n')])->groupBy('paper_code');
        $count = fn ($grp, $code, $rid) => (int) (($grp->get($code) ?? collect())->firstWhere('room_id', $rid)->n ?? 0);

        return $this->seatingSchedules($session, $date, $inningId)->map(function ($sch) use ($session, $all, $seated, $abs, $ufm, $count) {
            $total = $this->seatingExaminees($sch, $session, $all, [])->count();
            $bp = $this->seatingExaminees($sch, $session, ['Back Paper'], [])->count();
            $rooms = ($seated->get($sch->paper_code) ?? collect())->map(function ($r) use ($sch, $abs, $ufm, $count) {
                $a = $count($abs, $sch->paper_code, $r->room_id);
                return [
                    'room_id' => (int) $r->room_id, 'room_no' => $r->room_no, 'building_name' => $r->building_name,
                    'seated' => (int) $r->seated, 'abs' => $a, 'ufm' => $count($ufm, $sch->paper_code, $r->room_id),
                    'required' => max(0, (int) $r->seated - $a),
                    'copy_in_room' => null, 'received_copy' => null, 'locked' => false,
                ];
            })->values()->all();
            return [
                'schedule_id' => $sch->id, 'course' => $sch->course, 'semester_no' => (string) $sch->semester_no,
                'subject' => $sch->subject, 'paper_code' => $sch->paper_code, 'exam_mode' => $sch->exam_mode,
                'total' => $total, 'regular' => max(0, $total - $bp), 'back_paper' => $bp,
                'rooms' => $rooms,
                'absent' => array_sum(array_column($rooms, 'abs')), 'ufm' => array_sum(array_column($rooms, 'ufm')),
                'total_copy' => null, 'catch_no' => null, 'packet_no' => null,
                'ufm_total_copy' => null, 'ufm_catch_no' => null, 'ufm_packet_no' => null,
            ];
        })->values()->all();
    }

    /**
     * Lay the saved values of a record over a fresh schedule. $edits (from the screen) are applied
     * only to rows that are not locked in the saved record; a row may always be unlocked.
     */
    private function conductMerge(array $fresh, array $saved, array $edits = []): array
    {
        $savedBy = collect($saved)->keyBy('paper_code');
        $editBy = collect($edits)->keyBy('paper_code');
        foreach ($fresh as &$ln) {
            $old = $savedBy->get($ln['paper_code'], []);
            $ed = $editBy->get($ln['paper_code'], []);
            $oldRooms = collect($old['rooms'] ?? [])->keyBy('room_id');
            $edRooms = collect($ed['rooms'] ?? [])->keyBy('room_id');
            foreach ($ln['rooms'] as &$rm) {
                $o = $oldRooms->get($rm['room_id'], []);
                $e = $edRooms->get($rm['room_id'], []);
                $rm['copy_in_room'] = $o['copy_in_room'] ?? null;
                $rm['received_copy'] = $o['received_copy'] ?? null;
                $rm['locked'] = (bool) ($o['locked'] ?? false);
                if ($e) {
                    if (!$rm['locked']) {
                        $rm['copy_in_room'] = isset($e['copy_in_room']) && $e['copy_in_room'] !== '' ? max(0, (int) $e['copy_in_room']) : null;
                        $rm['received_copy'] = isset($e['received_copy']) && $e['received_copy'] !== '' ? max(0, (int) $e['received_copy']) : null;
                    }
                    if (array_key_exists('locked', $e)) $rm['locked'] = (bool) $e['locked'];
                }
            }
            unset($rm);
            $ln['packet_no'] = $old['packet_no'] ?? null;
            $ln['total_copy'] = $old['total_copy'] ?? null;
            $ln['catch_no'] = $old['catch_no'] ?? null;
            $ln['ufm_packet_no'] = $old['ufm_packet_no'] ?? null;
            $ln['ufm_total_copy'] = $old['ufm_total_copy'] ?? null;
            $ln['ufm_catch_no'] = $old['ufm_catch_no'] ?? null;
            if ($ed) {
                if (array_key_exists('ufm_total_copy', $ed)) $ln['ufm_total_copy'] = $ed['ufm_total_copy'] !== '' && $ed['ufm_total_copy'] !== null ? max(0, (int) $ed['ufm_total_copy']) : null;
                if (array_key_exists('ufm_catch_no', $ed)) $ln['ufm_catch_no'] = trim((string) $ed['ufm_catch_no']) !== '' ? trim((string) $ed['ufm_catch_no']) : null;
                if (array_key_exists('total_copy', $ed)) $ln['total_copy'] = $ed['total_copy'] !== '' && $ed['total_copy'] !== null ? max(0, (int) $ed['total_copy']) : null;
                if (array_key_exists('catch_no', $ed)) $ln['catch_no'] = trim((string) $ed['catch_no']) !== '' ? trim((string) $ed['catch_no']) : null;
            }
        }
        return $fresh;
    }

    private function conductDecorate(object $r)
    {
        $r->lines = is_string($r->lines ?? null) ? (json_decode($r->lines, true) ?: []) : ($r->lines ?? []);
        $r->total_examinee = array_sum(array_column($r->lines, 'total'));
        $r->paper_count = count($r->lines);
        return $r;
    }

    private function conductRecord(string $kind, string $session, string $date, int $inningId, string $center): ?object
    {
        return DB::table($this->conductTable($kind))->where([
            'session_year' => $session, 'exam_date' => $date, 'inning_id' => $inningId, 'center_code' => $center,
        ])->first();
    }

    /** GET conduct/schedule?kind&session_year&exam_date&inning_id&center_code — the grid: schedule + rooms, with any saved values. */
    public function conductSchedule(Request $req)
    {
        $v = Validator::make($req->all(), ['exam_date' => 'required|date', 'inning_id' => 'required|exists:exam_innings,id', 'kind' => 'nullable|in:p1,p9']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);
        $session = $req->input('session_year', $this->sessionYear());
        $kind = $req->input('kind', 'p1');
        $fresh = $this->conductScheduleLines($session, $req->exam_date, (int) $req->inning_id);
        $rec = $req->center_code ? $this->conductRecord($kind, $session, $req->exam_date, (int) $req->inning_id, $req->center_code) : null;
        $saved = $rec ? (is_string($rec->lines) ? json_decode($rec->lines, true) : ($rec->lines ?? [])) : [];
        return response()->json([
            'lines' => $this->conductMerge($fresh, $saved ?: []),
            'saved_id' => $rec?->id,
            'p1_saved' => $kind === 'p9' ? (bool) ($req->center_code && $this->conductRecord('p1', $session, $req->exam_date, (int) $req->inning_id, $req->center_code)) : (bool) $rec,
        ]);
    }

    private function conductIndex(Request $req, string $kind)
    {
        $t = $this->conductTable($kind);
        $q = DB::table("{$t} as c")->join('exam_innings as i', 'i.id', 'c.inning_id')
            ->where('c.session_year', $req->session_year ?? $this->sessionYear());
        $term = trim((string) $req->q);
        if ($term !== '') {
            $like = '%' . $term . '%';
            $clean = str_replace(['%', '_', '"'], '', $term);
            match ($req->search_by) {
                'inning' => $q->where('i.inning_name', 'ilike', $like),
                'center' => $q->where('c.center_code', 'ilike', $like),
                'paper_code' => $q->whereRaw('c.lines::text ilike ?', ['%"paper_code":"%' . $clean . '%']),
                'packet_no' => $q->whereRaw('c.lines::text ilike ?', ['%"packet_no":"%' . $clean . '%']),
                default => $q->where(fn ($w) => $w->whereRaw("to_char(c.exam_date, 'DD/MM/YYYY') ilike ?", [$like])->orWhereRaw('c.exam_date::text ilike ?', [$like])),
            };
        }
        $rows = $q->orderByDesc('c.exam_date')->orderBy('i.inning_name')->limit(200)
            ->get(['c.*', 'i.inning_name', 'i.time_start', 'i.time_end']);
        return response()->json($rows->map(fn ($r) => $this->conductDecorate($r))->values());
    }

    private function conductShow(int $id, string $kind)
    {
        $r = DB::table("{$this->conductTable($kind)} as c")->join('exam_innings as i', 'i.id', 'c.inning_id')
            ->where('c.id', $id)->first(['c.*', 'i.inning_name', 'i.time_start', 'i.time_end']);
        return $r ? response()->json($this->conductDecorate($r)) : response()->json(['message' => 'Record not found.'], 404);
    }

    /** Create/refresh the record of a date + inning + centre. $edits: the screen's editable values; $mutate: extra change of the merged lines. */
    private function conductPersist(Request $req, string $kind, array $edits, ?callable $mutate = null)
    {
        $v = Validator::make($req->all(), [
            'session_year' => 'required|string', 'exam_date' => 'required|date',
            'inning_id' => 'required|exists:exam_innings,id', 'center_code' => 'required|string|max:20',
            'creation_date' => 'nullable|date',
        ]);
        if ($v->fails()) return [null, response()->json(['errors' => $v->errors()], 422)];
        $d = $v->validated();
        $t = $this->conductTable($kind);

        $fresh = $this->conductScheduleLines($d['session_year'], $d['exam_date'], (int) $d['inning_id']);
        if (!$fresh) {
            return [null, response()->json(['message' => 'No exam is scheduled on this date and inning — set it in Exam Time Table first.'], 422)];
        }
        $key = ['session_year' => $d['session_year'], 'exam_date' => $d['exam_date'], 'inning_id' => $d['inning_id'], 'center_code' => $d['center_code']];

        $id = DB::transaction(function () use ($t, $key, $d, $fresh, $edits, $mutate, $req) {
            $existing = DB::table($t)->where($key)->lockForUpdate()->first();
            $saved = $existing ? (is_string($existing->lines) ? json_decode($existing->lines, true) : ($existing->lines ?? [])) : [];
            $lines = $this->conductMerge($fresh, $saved ?: [], $edits);
            if ($mutate) $lines = $mutate($lines);
            $row = ['creation_date' => $d['creation_date'] ?? ($existing->creation_date ?? now()->toDateString()), 'lines' => json_encode($lines), 'updated_at' => now()];
            if ($existing) {
                DB::table($t)->where('id', $existing->id)->update($row);
                return $existing->id;
            }
            return DB::table($t)->insertGetId($key + $row + ['created_by' => $req->user()?->id, 'created_at' => now()]);
        });
        return [$id, null];
    }

    private function conductStore(Request $req, string $kind)
    {
        [$id, $err] = $this->conductPersist($req, $kind, (array) $req->input('edits', []));
        if ($err) return $err;
        return response()->json(['id' => $id, 'message' => $kind === 'p9' ? 'P9 saved.' : 'P1 saved.'], 201);
    }

    /** POST conduct/p9/packet {…keys, paper_codes?: []} — give a packet number to the listed papers (all papers without one if omitted). */
    public function conductP9Packet(Request $req)
    {
        $only = array_values(array_filter((array) $req->input('paper_codes', [])));
        [$id, $err] = $this->conductPersist($req, 'p9', (array) $req->input('edits', []), function (array $lines) use ($req, $only) {
            $center = (string) $req->input('center_code');
            $last = 0;
            DB::table('exam_packets')->where('session_year', $req->session_year)->where('center_code', $center)->pluck('lines')
                ->each(function ($l) use (&$last) {
                    foreach ((is_string($l) ? json_decode($l, true) : $l) ?: [] as $x) {
                        if (preg_match('/(\d+)$/', (string) ($x['packet_no'] ?? ''), $m)) $last = max($last, (int) $m[1]);
                    }
                });
            foreach ($lines as &$ln) {
                $pick = !$only || in_array($ln['paper_code'], $only, true);
                if (empty($ln['packet_no']) && $pick) {
                    $ln['packet_no'] = sprintf('%s-%04d', $center, ++$last);
                }
                if (empty($ln['ufm_packet_no']) && ($ln['ufm'] ?? 0) > 0 && $pick) {
                    $ln['ufm_packet_no'] = sprintf('%s-%04d', $center, ++$last);
                }
            }
            return $lines;
        });
        if ($err) return $err;
        return response()->json(['id' => $id, 'message' => 'Packet number created.']);
    }

    /** DELETE conduct/{kind}/{id}/line?paper_code — clear what was saved for one paper (its row stays on the schedule, blank). */
    private function conductDeleteLine(Request $req, int $id, string $kind)
    {
        $t = $this->conductTable($kind);
        $rec = DB::table($t)->where('id', $id)->first();
        if (!$rec) return response()->json(['message' => 'Record not found.'], 404);
        $code = (string) $req->input('paper_code');
        $lines = collect(is_string($rec->lines) ? json_decode($rec->lines, true) : ($rec->lines ?? []))->map(function ($ln) use ($code) {
            if (($ln['paper_code'] ?? null) !== $code) return $ln;
            foreach ($ln['rooms'] ?? [] as $i => $_) {
                $ln['rooms'][$i]['copy_in_room'] = null; $ln['rooms'][$i]['received_copy'] = null; $ln['rooms'][$i]['locked'] = false;
            }
            $ln['total_copy'] = null; $ln['catch_no'] = null; $ln['packet_no'] = null;
            $ln['ufm_total_copy'] = null; $ln['ufm_catch_no'] = null; $ln['ufm_packet_no'] = null;
            return $ln;
        })->values()->all();
        DB::table($t)->where('id', $id)->update(['lines' => json_encode($lines), 'updated_at' => now()]);
        return response()->json(['message' => 'Cleared.']);
    }

    private function conductDelete(int $id, string $kind)
    {
        DB::table($this->conductTable($kind))->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    public function conductP1DeleteLine(Request $req, int $id) { return $this->conductDeleteLine($req, $id, 'p1'); }
    public function conductP9DeleteLine(Request $req, int $id) { return $this->conductDeleteLine($req, $id, 'p9'); }

    public function conductP1Index(Request $req) { return $this->conductIndex($req, 'p1'); }
    public function conductP1Show(int $id)       { return $this->conductShow($id, 'p1'); }
    public function conductP1Store(Request $req) { return $this->conductStore($req, 'p1'); }
    public function conductP1Destroy(int $id)    { return $this->conductDelete($id, 'p1'); }
    public function conductP9Index(Request $req) { return $this->conductIndex($req, 'p9'); }
    public function conductP9Show(int $id)       { return $this->conductShow($id, 'p9'); }
    public function conductP9Store(Request $req) { return $this->conductStore($req, 'p9'); }
    public function conductP9Destroy(int $id)    { return $this->conductDelete($id, 'p9'); }

    // ══════════════════════════════════════════════════════════════
    // P3 — UNFAIR MEANS (UFM) and P4 — ABSENT (ABS), per student + paper.
    // Exam date, inning and room are never typed: they come from the exam
    // time table and the student's seat in the saved Seating Plan.
    // ══════════════════════════════════════════════════════════════

    private function conductAddress($raw): string
    {
        $a = is_string($raw) ? (json_decode($raw, true) ?? $raw) : $raw;
        if (is_array($a)) {
            $a = implode(', ', array_filter(array_map('trim', array_map('strval', array_filter([
                $a['house_no'] ?? null, $a['mohalla'] ?? null, $a['post_office'] ?? null, $a['sub_district'] ?? null,
                $a['district'] ?? null, $a['state'] ?? null, $a['pin_code'] ?? null,
            ])))));
        }
        return trim((string) $a);
    }

    /** The student (by university roll no.) with exam form, papers, schedule, seat and ABS/UFM state. */
    private function conductStudentData(string $roll, string $session): ?array
    {
        $row = $this->withIdentity(
            DB::table('admissions as a')
                ->join('students as s', 's.id', 'a.student_id')
                ->join('programs as p', 'p.id', 'a.program_id')
                ->leftJoin('student_applications as sa', 'sa.id', 'a.application_id')
                ->leftJoin('direct_registrations as drc', 'drc.id', 'sa.direct_registration_id')
        )->whereNull('a.deleted_at')->where('s.university_roll_no', $roll)->orderByDesc('a.id')
            ->first(array_merge([
                'a.id as admission_id', 'a.program_id', 'a.application_id', 's.id as student_id', 's.mobile',
                's.address_info->permanent_address as address', 'p.short_name as course', 'sa.application_no',
                'drc.registration_no',
            ], $this->identitySelect()));
        if (!$row) return null;
        $row = $this->decorate($row);

        $forms = DB::table('exam_forms')->where('admission_id', $row->admission_id)->where('session_year', $session)
            ->orderByDesc('id')->get();
        $form = $forms->first();
        $paperRows = $forms->isEmpty() ? collect() : DB::table('exam_form_papers as efp')
            ->join('exam_forms as ef', 'ef.id', 'efp.exam_form_id')
            ->leftJoin('subjects as sub', 'sub.id', 'efp.subject_id')
            ->whereIn('efp.exam_form_id', $forms->pluck('id'))
            ->get(['efp.paper_code', 'sub.name as subject', 'ef.semester_no'])->unique('paper_code')->values();

        $sched = DB::table('exam_schedules as es')->join('exam_innings as i', DB::raw('i.inning_name'), '=', 'es.inning')
            ->where('es.session_year', $session)->where('es.program_id', $row->program_id)
            ->whereIn('es.paper_code', $paperRows->pluck('paper_code'))
            ->get(['es.paper_code', 'es.exam_date', 'es.exam_start', 'es.exam_end', 'es.inning', 'i.id as inning_id'])
            ->unique('paper_code')->keyBy('paper_code');
        $seats = DB::table('exam_seating as es')->join('exam_rooms as r', 'r.id', 'es.room_id')
            ->where('es.session_year', $session)->where('es.student_id', $row->student_id)
            ->get(['es.paper_code', 'es.exam_date', 'es.room_id', 'r.room_no'])->keyBy(fn ($x) => $x->paper_code . '|' . substr((string) $x->exam_date, 0, 10));
        $abs = DB::table('exam_absent')->where('admission_id', $row->admission_id)->where('session_year', $session)->get()->keyBy('paper_code');
        $ufm = DB::table('exam_ufm')->where('admission_id', $row->admission_id)->where('session_year', $session)->get()->keyBy('paper_code');
        $subNames = DB::table('subject_papers')->where('program_id', $row->program_id)->whereIn('paper_code', $paperRows->pluck('paper_code'))
            ->pluck('paper_name', 'paper_code');
        $today = now()->toDateString();

        $papers = $paperRows->map(function ($p) use ($sched, $seats, $abs, $ufm, $subNames, $today) {
            $sc = $sched->get($p->paper_code);
            $date = $sc ? substr((string) $sc->exam_date, 0, 10) : null;
            $seat = $date ? $seats->get($p->paper_code . '|' . $date) : null;
            return [
                'paper_code' => $p->paper_code, 'subject' => $p->subject, 'paper_name' => $subNames[$p->paper_code] ?? null,
                'exam_date' => $date, 'inning_id' => $sc->inning_id ?? null, 'inning' => $sc->inning ?? null,
                'time_start' => $sc->exam_start ?? null, 'time_end' => $sc->exam_end ?? null,
                'room_id' => $seat->room_id ?? null, 'room_no' => $seat->room_no ?? null,
                'abs_id' => $abs->get($p->paper_code)->id ?? null, 'ufm_id' => $ufm->get($p->paper_code)->id ?? null,
                'status' => isset($abs[$p->paper_code]) ? 'ABS' : (!$date ? 'Not scheduled' : ($date <= $today ? 'Appear' : 'Awaited')),
            ];
        })->sortBy(fn ($p) => ($p['exam_date'] ?? '9999') . $p['paper_code'])->values()->all();

        $docs = $row->application_id
            ? \App\Support\FileUrls::forDocuments(DB::table('student_application_documents')->where('application_id', $row->application_id)->get())
            : collect();
        $part6 = $row->application_id ? (json_decode((string) DB::table('student_applications')->where('id', $row->application_id)->value('part_6'), true) ?: []) : [];

        return [
            'admission_id' => (int) $row->admission_id, 'student_id' => (int) $row->student_id, 'roll_no' => $roll,
            'name' => $row->name, 'father_name' => $row->father_name, 'address' => $this->conductAddress($row->address),
            'exam_form_no' => $form->form_id ?? null, 'registration_no' => $row->registration_no, 'application_no' => $row->application_no,
            'course' => $row->course, 'semester_no' => $form->semester_no ?? null, 'mobile' => $row->mobile,
            'photo_url' => $docs->firstWhere('document_type', 'photo')->url ?? null,
            'signature_url' => $docs->firstWhere('document_type', 'signature')->url ?? null,
            'subjects' => collect($papers)->pluck('subject')->filter()->unique()->values()->all(),
            'itrp_subject' => $part6['itrp_subject'] ?? null, 'drop_subject' => $part6['drop_subject'] ?? null,
            'papers' => $papers,
        ];
    }

    /** GET conduct/student?roll_no&session_year */
    public function conductStudent(Request $req)
    {
        $v = Validator::make($req->all(), ['roll_no' => 'required|string']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);
        $d = $this->conductStudentData(trim($req->roll_no), $req->input('session_year', $this->sessionYear()));
        return $d ? response()->json($d) : response()->json(['message' => 'No student found with this Roll Number.'], 404);
    }

    private function conductMarkTable(string $kind): string
    {
        return $kind === 'ufm' ? 'exam_ufm' : 'exam_absent';
    }

    /** GET conduct/p3|p4?roll_no&session_year — the saved records of one student. */
    private function conductMarkIndex(Request $req, string $kind)
    {
        $session = $req->input('session_year', $this->sessionYear());
        $stu = $this->conductStudentData(trim((string) $req->roll_no), $session);
        if (!$stu) return response()->json([]);
        $names = collect($stu['papers'])->keyBy('paper_code');
        $rows = DB::table($this->conductMarkTable($kind) . ' as m')->join('exam_innings as i', 'i.id', 'm.inning_id')->join('exam_rooms as r', 'r.id', 'm.room_id')
            ->where('m.admission_id', $stu['admission_id'])->where('m.session_year', $session)->orderBy('m.exam_date')
            ->get(['m.*', 'i.inning_name', 'r.room_no']);
        return response()->json($rows->map(function ($r) use ($names) {
            $r->subject = $names[$r->paper_code]['subject'] ?? null;
            $r->paper_name = $names[$r->paper_code]['paper_name'] ?? null;
            $r->exam_date = substr((string) $r->exam_date, 0, 10);
            return $r;
        })->values());
    }

    /** POST (id null) / PUT conduct/p3|p4 — submit or edit one ABS / UFM record. */
    private function conductMarkSave(Request $req, string $kind, ?int $id = null)
    {
        $rules = [
            'roll_no' => 'required|string', 'paper_code' => 'required|string',
            'invigilator1' => 'required|string|max:255', 'invigilator2' => 'nullable|string|max:255',
            'issued_copy_no' => $kind === 'ufm' ? 'required|string|max:50' : 'nullable|string|max:50',
        ];
        if ($kind === 'ufm') {
            $rules += ['ufm_by' => 'required|string|max:255', 'authority_name' => 'required|string|max:255', 'ufm_copy_no2' => 'required|string|max:50'];
        }
        $v = Validator::make($req->all(), $rules);
        if ($v->fails()) return response()->json(['errors' => $v->errors(), 'message' => $v->errors()->first()], 422);
        $d = $v->validated();
        $session = $req->input('session_year', $this->sessionYear());
        $t = $this->conductMarkTable($kind);

        $stu = $this->conductStudentData(trim($d['roll_no']), $session);
        if (!$stu) return response()->json(['message' => 'No student found with this Roll Number.'], 404);
        $paper = collect($stu['papers'])->firstWhere('paper_code', strtoupper(trim($d['paper_code']))) ?? collect($stu['papers'])->first(fn ($p) => strcasecmp($p['paper_code'], trim($d['paper_code'])) === 0);
        if (!$paper) return response()->json(['message' => 'This paper is not in the student\'s exam form.'], 422);
        if (!$paper['exam_date']) return response()->json(['message' => 'This paper has no exam date in the Exam Time Table.'], 422);
        if (!$paper['room_id']) return response()->json(['message' => 'The student has no seat for this paper — save the Seating Plan for that date and inning first.'], 422);

        $dup = DB::table($t)->where('admission_id', $stu['admission_id'])->where('session_year', $session)->where('paper_code', $paper['paper_code'])
            ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
        if ($dup) return response()->json(['message' => ($kind === 'ufm' ? 'UFM' : 'ABS') . ' is already submitted for this paper — open it from the list to edit.'], 422);

        $row = [
            'admission_id' => $stu['admission_id'], 'roll_no' => $stu['roll_no'], 'paper_code' => $paper['paper_code'], 'session_year' => $session,
            'exam_date' => $paper['exam_date'], 'inning_id' => $paper['inning_id'], 'room_id' => $paper['room_id'],
            'issued_copy_no' => $d['issued_copy_no'] ?? null, 'invigilator1' => $d['invigilator1'], 'invigilator2' => $d['invigilator2'] ?? null,
            'updated_at' => now(),
        ];
        if ($kind === 'ufm') $row += ['ufm_by' => $d['ufm_by'], 'authority_name' => $d['authority_name'], 'ufm_copy_no2' => $d['ufm_copy_no2']];

        if ($id) {
            DB::table($t)->where('id', $id)->update($row);
            return response()->json(['id' => $id, 'message' => ($kind === 'ufm' ? 'UFM' : 'ABS') . ' updated.']);
        }
        $newId = DB::table($t)->insertGetId($row + ['created_at' => now()]);
        return response()->json(['id' => $newId, 'message' => $kind === 'ufm' ? "UFM Detail generated successfully. Ref No: UFM/{$newId}" : "ABS Detail generated successfully. Ref No: ABS/{$newId}"], 201);
    }

    public function conductP3Index(Request $req) { return $this->conductMarkIndex($req, 'ufm'); }
    public function conductP3Store(Request $req) { return $this->conductMarkSave($req, 'ufm'); }
    public function conductP3Update(Request $req, int $id) { return $this->conductMarkSave($req, 'ufm', $id); }
    public function conductP3Destroy(int $id) { DB::table('exam_ufm')->where('id', $id)->delete(); return response()->json(['message' => 'Deleted.']); }
    public function conductP4Index(Request $req) { return $this->conductMarkIndex($req, 'abs'); }
    public function conductP4Store(Request $req) { return $this->conductMarkSave($req, 'abs'); }
    public function conductP4Update(Request $req, int $id) { return $this->conductMarkSave($req, 'abs', $id); }
    public function conductP4Destroy(int $id) { DB::table('exam_absent')->where('id', $id)->delete(); return response()->json(['message' => 'Deleted.']); }

    // ══════════════════════════════════════════════════════════════
    // 6. NOMINAL ROLL
    // ══════════════════════════════════════════════════════════════
    public function nominalRollIndex(Request $req)
    {
        $session = $req->session_year ?? $this->sessionYear();
        $base = fn(bool $withType = true) => DB::table('exam_forms as ef')
            ->join('admissions as a', 'a.id', 'ef.admission_id')
            ->join('students as s', 's.id', 'a.student_id')
            ->leftJoin('student_applications as sa', 'sa.id', 'a.application_id')
            ->leftJoin('direct_registrations as drc', 'drc.id', 'sa.direct_registration_id')
            ->leftJoin('exam_nominal_roll_entries as nr', 'nr.exam_form_id', 'ef.id')
            ->where('ef.status', 'Accepted')
            ->where('ef.session_year', $session)
            ->when($req->program_id,  fn($q) => $q->where('a.program_id',  $req->program_id))
            ->when($req->semester_no, fn($q) => $q->where('ef.semester_no', $req->semester_no))
            ->when($withType && $req->exam_type, fn($q) => $q->where('ef.exam_type', $req->exam_type));

        $page = $this->withIdentity($base())
            ->select(array_merge(
                ['ef.id', 'ef.form_id', 'ef.exam_type', 'ef.semester_no', 'ef.admission_id',
                 's.personal_info->gender as gender', 's.university_roll_no as roll_no', 's.enrollment_no',
                 'sa.application_no', 'sa.id as application_id', 'drc.unique_code as code', 'a.account_no',
                 'dr.category as reg_category', 's.personal_info->category as s_category',
                 'dr.dob as reg_dob', 's.personal_info->date_of_birth as s_dob',
                 DB::raw('(nr.id is not null) as in_nominal')],
                $this->identitySelect()
            ))
            ->orderBy('s.university_roll_no')->orderBy('ef.id')
            ->paginate(min((int) $req->input('per_page', 100), 1000));

        // Subjects + paper codes per form (one query, not a join that repeats the student per paper).
        $ids = $page->getCollection()->pluck('id')->all();
        $papers = $ids
            ? DB::table('exam_form_papers as efp')->leftJoin('subjects as sub', 'sub.id', 'efp.subject_id')
                ->whereIn('efp.exam_form_id', $ids)->orderBy('efp.id')
                ->get(['efp.exam_form_id', 'efp.paper_code', 'sub.name as subject'])->groupBy('exam_form_id')
            : collect();

        $page->getCollection()->transform(function ($r) use ($papers) {
            $r = $this->decorate($r);
            $r->dob = $r->reg_dob ?: $r->s_dob;
            $r->category = $r->reg_category ?: $r->s_category;
            $p = $papers->get($r->id, collect());
            $r->subjects = $p->pluck('subject')->filter()->unique()->values()->all();
            $r->paper_codes = $p->pluck('paper_code')->filter()->unique()->values()->all();
            $r->in_nominal = (bool) $r->in_nominal;
            return $r;
        });

        // Counts over the whole fetched set (not just this page).
        $stat = $base()->selectRaw("count(*) as enrolled,
                count(*) filter (where nr.id is not null) as updated,
                count(*) filter (where nr.id is null) as pending")->first();
        $byType = $base(false)->selectRaw("count(*) filter (where ef.exam_type = 'Regular') as regular,
                count(*) filter (where ef.exam_type = 'Back Paper') as back_paper")->first();
        $stat->regular = (int) $byType->regular;
        $stat->back_paper = (int) $byType->back_paper;

        return response()->json(array_merge($page->toArray(), ['stats' => $stat]));
    }

    /** POST /examination/nominal-roll/create {ids?: []} — put accepted forms on the nominal roll (ids, else everything matching the filters). */
    public function nominalRollCreate(Request $req)
    {
        $session = $req->session_year ?? $this->sessionYear();
        $forms = DB::table('exam_forms as ef')->join('admissions as a', 'a.id', 'ef.admission_id')
            ->where('ef.status', 'Accepted')->where('ef.session_year', $session)
            ->when($req->filled('ids'), fn($q) => $q->whereIn('ef.id', (array) $req->ids))
            ->when(!$req->filled('ids') && $req->program_id, fn($q) => $q->where('a.program_id', $req->program_id))
            ->when(!$req->filled('ids') && $req->semester_no, fn($q) => $q->where('ef.semester_no', $req->semester_no))
            ->when(!$req->filled('ids') && $req->exam_type, fn($q) => $q->where('ef.exam_type', $req->exam_type))
            ->get(['ef.id', 'ef.session_year', 'ef.semester_no', 'ef.exam_type', 'a.program_id']);

        $now = now();
        $userId = $req->user()?->id;
        $rows = $forms->map(fn ($f) => [
            'exam_form_id' => $f->id, 'session_year' => $f->session_year, 'program_id' => $f->program_id,
            'semester_no' => $f->semester_no, 'exam_type' => $f->exam_type,
            'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ])->all();

        // exam_form_id is unique: forms already on the roll are skipped by
        // ON CONFLICT DO NOTHING, and the affected-row count is what was added.
        $n = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $n += DB::table('exam_nominal_roll_entries')->insertOrIgnore($chunk);
        }
        return response()->json(['message' => "{$n} record(s) added to the nominal roll.", 'count' => $n]);
    }

    // ══════════════════════════════════════════════════════════════
    // 7. RESULT UPDATE
    // ══════════════════════════════════════════════════════════════
    public function resultIndex(Request $req)
    {
        $q = $this->withIdentity(
            DB::table('exam_forms as ef')
                ->join('admissions as a', 'a.id', 'ef.admission_id')
                ->join('students as s', 's.id', 'a.student_id')
                ->join('programs as p', 'p.id', 'a.program_id')
        )
            ->select(array_merge(
                ['ef.*', 's.personal_info->gender as gender', 's.university_roll_no as roll_no', 'a.account_no', 's.enrollment_no', 'p.short_name as course'],
                $this->identitySelect()
            ))
            ->where('ef.session_year', $req->session_year ?? $this->sessionYear())
            ->when($req->program_id,  fn($q) => $q->where('a.program_id',  $req->program_id))
            ->when($req->semester_no, fn($q) => $q->where('ef.semester_no', $req->semester_no))
            ->when($req->exam_type,   fn($q) => $q->where('ef.exam_type',   $req->exam_type))
            ->when($req->gender,      fn($q) => $q->where('s.personal_info->gender',       $req->gender))
            ->where('ef.status', 'Accepted')
            ->orderBy('s.university_roll_no')
            ->paginate(50);

        $q->getCollection()->transform(fn ($r) => $this->decorate($r));

        return response()->json($q);
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
        $req->validate(['results' => 'required|array', 'results.*.id' => 'required|integer', 'results.*.result' => 'required|string']);
        // Last entry wins for a repeated id, as with the old one-update-per-row loop.
        $byId = collect($req->results)->keyBy(fn ($item) => (int) $item['id']);

        // One UPDATE … FROM (VALUES …) per 500 rows, all-or-nothing.
        DB::transaction(function () use ($byId) {
            foreach ($byId->chunk(500) as $chunk) {
                $values = implode(', ', array_fill(0, $chunk->count(), '(?::bigint, ?::varchar)'));
                $bindings = [now()];
                foreach ($chunk as $id => $item) {
                    array_push($bindings, $id, $item['result']);
                }
                DB::update(
                    "UPDATE exam_forms SET result = v.result, updated_at = ?
                       FROM (VALUES {$values}) AS v(id, result)
                      WHERE exam_forms.id = v.id",
                    $bindings
                );
            }
        });
        return response()->json(['message' => count($req->results) . ' records updated.']);
    }

    // ══════════════════════════════════════════════════════════════
    // 8. MARKSHEET DISTRIBUTION
    // ══════════════════════════════════════════════════════════════
    public function marksheetIndex(Request $req)
    {
        $q = $this->withIdentity(
            DB::table('exam_forms as ef')
                ->join('admissions as a', 'a.id', 'ef.admission_id')
                ->join('students as s', 's.id', 'a.student_id')
                ->join('programs as p', 'p.id', 'a.program_id')
        )
            ->select(array_merge(
                ['ef.*', 's.personal_info->gender as gender', 's.university_roll_no as roll_no', 'a.account_no', 's.enrollment_no', 'p.short_name as course'],
                $this->identitySelect()
            ))
            ->where('ef.session_year', $req->session_year ?? $this->sessionYear())
            ->when($req->program_id,  fn($q) => $q->where('a.program_id',  $req->program_id))
            ->when($req->semester_no, fn($q) => $q->where('ef.semester_no', $req->semester_no))
            ->when($req->exam_type,   fn($q) => $q->where('ef.exam_type',   $req->exam_type))
            ->when($req->gender,      fn($q) => $q->where('s.personal_info->gender',       $req->gender))
            ->where('ef.status', 'Accepted')
            ->orderBy('s.university_roll_no')
            ->paginate(50);

        $q->getCollection()->transform(fn ($r) => $this->decorate($r));

        return response()->json($q);
    }

    public function marksheetUpdateAvailability(Request $req, $id)
    {
        DB::table('exam_forms')->where('id', $id)
            ->update(['marksheet_available' => (bool) $req->available, 'updated_at' => now()]);
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
            ->select('p.short_name as course', 'p.level', 's.personal_info->gender as gender', DB::raw('count(*) as total'))
            ->where('ef.session_year', $sessionYear)
            ->when($req->program_id, fn($q) => $q->where('a.program_id', $req->program_id))
            ->where('ef.status', 'Accepted')
            ->groupBy('p.id', 'p.short_name', 'p.level', 's.personal_info->gender')
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
            ->select('p.short_name as course', 'sub.name as subject', 'efp.exam_type', DB::raw('count(*) as total'))
            ->where('ef.session_year', $sessionYear)
            ->when($req->program_id, fn($q) => $q->where('a.program_id', $req->program_id))
            ->when($req->semester_no, fn($q) => $q->where('ef.semester_no', $req->semester_no))
            ->when($req->gender, fn($q) => $q->where('s.personal_info->gender', $req->gender))
            ->where('ef.status', 'Accepted')
            ->groupBy('p.id', 'p.short_name', 'sub.id', 'sub.name', 'efp.exam_type')
            ->orderBy('p.short_name')->orderBy('sub.name')
            ->get();

        return response()->json($data);
    }

    // ══════════════════════════════════════════════════════════════
    // 10. EXAM CENTRE
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

        $id = DB::table('exam_centres')->insertGetId(array_merge($v->validated(), [
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Centre added.'], 201);
    }

    public function examCentreUpdate(Request $req, $id)
    {
        DB::table('exam_centres')->where('id', $id)->update(array_merge(
            $req->only(['center_code', 'center_name', 'college_code', 'college_name']),
            ['updated_at' => now()]
        ));
        return response()->json(['message' => 'Updated.']);
    }

    public function examCentreDestroy($id)
    {
        DB::table('exam_centres')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    public function examCentreStudents(Request $req)
    {
        $q = $this->withIdentity(
            DB::table('exam_forms as ef')
                ->join('admissions as a', 'a.id', 'ef.admission_id')
                ->join('students as s', 's.id', 'a.student_id')
                ->join('programs as p', 'p.id', 'a.program_id')
                ->leftJoin('exam_form_papers as efp', 'efp.exam_form_id', 'ef.id')
        )
            ->select(array_merge(
                ['ef.*', 's.personal_info->gender as gender', 's.university_roll_no as roll_no', 'p.short_name as course', 'efp.paper_code'],
                $this->identitySelect()
            ))
            ->where('ef.center_code', $req->center_code)
            ->when($req->program_id,  fn($q) => $q->where('a.program_id',  $req->program_id))
            ->when($req->semester_no, fn($q) => $q->where('ef.semester_no', $req->semester_no))
            ->when($req->exam_type,   fn($q) => $q->where('ef.exam_type',   $req->exam_type))
            ->paginate(50);

        $q->getCollection()->transform(fn ($r) => $this->decorate($r));

        return response()->json($q);
    }

    // Lookup student by roll_no (used in P3/P4 forms)
    public function lookupStudent(Request $req)
    {
        $admission = $this->withIdentity(
            DB::table('admissions as a')
                ->join('students as s', 's.id', 'a.student_id')
                ->join('programs as p', 'p.id', 'a.program_id')
        )
            ->where('s.university_roll_no', $req->roll_no)
            ->select(array_merge(['a.*', 's.mobile', 's.address_info->permanent_address as address', 'p.short_name as course'], $this->identitySelect()))
            ->first();

        if (!$admission) return response()->json(['message' => 'Not found.'], 404);
        $admission = $this->decorate($admission);

        $examForm = DB::table('exam_forms')
            ->where('admission_id', $admission->admission_id ?? $admission->id)
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
