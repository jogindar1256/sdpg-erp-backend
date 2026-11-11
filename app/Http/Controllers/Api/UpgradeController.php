<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Support\AcademicSession;
use App\Support\Part6Rules;
use App\Support\Payments;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Semester Upgrade (semesters 2-8; 1st semester is New Admission only).
 *
 * One flow for the college office and the student portal:
 *   context  -> who/what/when: eligibility, Admission Schedule window, the
 *               subjects carried over from the previous semester's admission,
 *               the options to pick from, the fee and what is already paid;
 *   save     -> create / edit the semester_upgrade application (status
 *               'submitted'), in the same part_* shape the regular flow uses so
 *               approval, the education-fee payment and printing keep working;
 *   cancel   -> voids only this session's semester application.
 *
 * Payment and approval stay in ApplicationController (pay/initiate, approve):
 * even target semesters are paid first, odd ones after approval.
 */
class UpgradeController extends Controller
{
    private const ACTIVE = ['draft', 'submitted', 'under_review', 'on_hold', 'approved'];
    private const EDITABLE = ['draft', 'submitted', 'on_hold'];
    private const RESULTS = ['Pass', 'Promoted', 'Not Declared'];

    // ─────────────────────────── office ───────────────────────────

    /** GET /upgrade/context?q=…|student_id=…&session_year=… */
    public function officeContext(Request $req): JsonResponse
    {
        $student = $this->findStudent($req);
        if (!$student) {
            return response()->json(['message' => 'No matching student found.'], 404);
        }
        return response()->json($this->build($student, $this->session($req), 'college'));
    }

    /** POST /upgrade/save */
    public function officeSave(Request $req): JsonResponse
    {
        $student = $this->findStudent($req);
        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }
        return $this->save($req, $student, 'college');
    }

    /** POST /upgrade/{id}/cancel */
    public function officeCancel(Request $req, $id): JsonResponse
    {
        return $this->cancel($req, (int) $id, null);
    }

    /**
     * GET /upgrade/not-admitted — the After-Upgrade record: students sitting in
     * semester n (n < last) who have not taken admission in a later semester.
     * Filters: program_id, session_year (upgrade-application status shown for it),
     * semester_no (the TARGET semester), search, per_page.
     */
    public function notAdmitted(Request $req): JsonResponse
    {
        $session = $this->session($req);
        $q = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->where('a.status', 'active')
            ->whereNull('s.deleted_at')
            ->whereRaw('CAST(a.semester_no AS INTEGER) < p.total_semesters')
            ->whereNotExists(function ($x) {
                $x->select(DB::raw(1))->from('admissions as b')
                    ->whereColumn('b.student_id', 'a.student_id')
                    ->whereColumn('b.program_id', 'a.program_id')
                    ->where('b.status', 'active')
                    ->whereRaw('CAST(b.semester_no AS INTEGER) > CAST(a.semester_no AS INTEGER)');
            })
            ->when($req->filled('program_id'), fn($w) => $w->where('a.program_id', $req->program_id))
            ->when($req->filled('semester_no'), fn($w) => $w->whereRaw('CAST(a.semester_no AS INTEGER) = ?', [(int) $req->semester_no - 1]))
            ->when($req->filled('search'), function ($w) use ($req) {
                $t = '%' . trim($req->search) . '%';
                $w->where(fn($z) => $z->where('s.mobile', 'like', $t)
                    ->orWhere('s.university_roll_no', 'like', $t)
                    ->orWhere('s.enrollment_no', 'like', $t)
                    ->orWhere('s.student_uid', 'like', $t));
            })
            ->select(
                's.*', 'p.short_name as course', 'p.id as program_id',
                'a.semester_no as current_semester', 'a.academic_year as admitted_session',
                DB::raw("(select sa.status from student_applications sa where sa.student_id = a.student_id and sa.program_id = a.program_id and sa.application_type = 'semester_upgrade' and sa.academic_year = " . DB::getPdo()->quote($session) . " and sa.deleted_at is null order by sa.id desc limit 1) as upgrade_status"),
                DB::raw("(select sa.application_no from student_applications sa where sa.student_id = a.student_id and sa.program_id = a.program_id and sa.application_type = 'semester_upgrade' and sa.academic_year = " . DB::getPdo()->quote($session) . " and sa.deleted_at is null order by sa.id desc limit 1) as upgrade_application_no")
            )
            ->orderBy('p.short_name')->orderBy('a.semester_no')->orderBy('s.id');

        $page = $q->paginate(min(200, max(1, (int) $req->get('per_page', 25))));
        $page->getCollection()->transform(function ($row) {
            $row = Student::flat($row);
            $row->name = $row->name ?? Student::fullName($row);
            $row->target_semester = (int) $row->current_semester + 1;
            return $row;
        });
        return response()->json($page);
    }

    // ─────────────────────────── student ───────────────────────────

    /** GET /student/upgrade/context?session_year=… */
    public function studentContext(Request $req): JsonResponse
    {
        $student = $this->ownStudent($req);
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }
        return response()->json($this->build($student, $this->session($req), 'student'));
    }

    /** POST /student/upgrade/save */
    public function studentSave(Request $req): JsonResponse
    {
        $student = $this->ownStudent($req);
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }
        return $this->save($req, $student, 'student');
    }

    /** POST /student/upgrade/{id}/cancel */
    public function studentCancel(Request $req, $id): JsonResponse
    {
        $student = $this->ownStudent($req);
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }
        return $this->cancel($req, (int) $id, (int) $student->id);
    }

    /**
     * POST /student/upgrade/registration — "Accept & Continue".
     * Creates this semester's registration (a direct_registrations row, semester_no = target,
     * grouped with the student's earlier registrations by reg_group_id). The registration fee
     * comes from the Registration Fee master: no master entry = blocked, 0 = registered at once,
     * > 0 = a pending row the student pays from the registration dashboard.
     */
    public function studentStartRegistration(Request $req): JsonResponse
    {
        $student = $this->ownStudent($req);
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }
        return $this->startRegistration($req, $student, 'student');
    }

    /** POST /upgrade/registration (office, on behalf of the student) — same rules as the student's. */
    public function officeStartRegistration(Request $req): JsonResponse
    {
        $student = $this->findStudent($req);
        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }
        return $this->startRegistration($req, $student, 'college');
    }

    private function startRegistration(Request $req, object $student, string $mode): JsonResponse
    {
        $session = $this->session($req);
        $ctx = $this->build($student, $session, $mode);
        if (!$ctx['eligible']) {
            return response()->json(['message' => $ctx['reason'] ?: 'Registration is not open for you.'], 422);
        }
        if (!empty($ctx['registration'])) {
            return response()->json(['message' => 'Registration already started.', 'registration' => $ctx['registration'], 'context' => $ctx]);
        }
        $fee = $ctx['registration_fee'];
        if (empty($fee['configured'])) {
            return response()->json(['message' => "The college has not set the registration fee for semester {$ctx['target_semester']}. Please contact the college office."], 422);
        }

        // Identity is copied from the student's first (lowest-semester) registration.
        $src = $this->registrationOf($student);
        if (!$src) {
            return response()->json(['message' => 'No earlier registration found to continue from.'], 422);
        }
        $groupId = (int) ($src->reg_group_id ?: $src->id);
        $first = DB::table('direct_registrations')->where('reg_group_id', $groupId)->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')->orderBy('semester_no')->orderBy('id')->first() ?: $src;

        $sem = (int) $ctx['target_semester'];
        $amount = (float) $fee['amount'];
        $ctrl = app(\App\Http\Controllers\Api\StudentRegistrationController::class);
        $row = (array) $first;
        foreach (['id', 'registration_no', 'unique_code', 'pdf_path', 'created_at', 'updated_at', 'deleted_at', 'approved_by',
                  'approved_at', 'remarks', 'cancelled_by', 'cancelled_at', 'cancel_reason'] as $k) {
            unset($row[$k]);
        }
        $newId = DB::transaction(function () use ($row, $ctrl, $session, $sem, $amount, $groupId, $ctx, $student) {
            return (int) DB::table('direct_registrations')->insertGetId(array_merge($row, [
                'registration_no' => $ctrl->generateRegNo($session, $ctx['program']['id']),
                'unique_code' => \App\Http\Controllers\Api\StudentRegistrationController::generateUniqueCode((string) ($row['reg_type'] ?? 'UG'), $ctx['program']['id'], $session),
                'program_id' => $ctx['program']['id'],
                'user_id' => $student->user_id ?? ($row['user_id'] ?? null),
                'session_year' => $session,
                'semester_no' => $sem,
                'reg_group_id' => $groupId,
                'reg_date' => now()->toDateString(),
                'fee_amount' => $amount,
                'status' => $amount > 0 ? 'pending' : 'registered',
                'phone_verified' => true,
                'email_verified' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        });

        return response()->json([
            'message' => $amount > 0 ? 'Registration created. Pay the registration fee to continue.' : 'Registration complete. You can fill the upgrade form now.',
            'registration_id' => $newId,
            'context' => $this->build($student, $session, $mode),
        ], 201);
    }

    // ─────────────────────────── core ───────────────────────────

    /** Upgrades are only ever for the current session — a requested session_year is ignored. */
    private function session(Request $req): string
    {
        return (string) AcademicSession::current();
    }

    private function ownStudent(Request $req): ?object
    {
        return Student::flat(DB::table('students')->where('user_id', $req->user()->id)->whereNull('deleted_at')->first());
    }

    /** Office lookup: student_id, or q = roll no / enrollment / mobile / aadhar / application no / registration no. */
    private function findStudent(Request $req): ?object
    {
        if ($req->filled('student_id')) {
            return Student::flat(DB::table('students')->where('id', $req->student_id)->whereNull('deleted_at')->first());
        }
        $q = trim((string) ($req->input('q', $req->query('q', ''))));
        if (mb_strlen($q) < 3) {
            return null;
        }
        $row = DB::table('students')->whereNull('deleted_at')
            ->where(fn($w) => $w->where('university_roll_no', $q)->orWhere('enrollment_no', $q)
                ->orWhere('mobile', $q)->orWhere('aadhar_no', $q)->orWhere('student_uid', $q))
            ->first();
        if (!$row) {
            $sid = DB::table('student_applications')->where('application_no', $q)->whereNull('deleted_at')->value('student_id');
            $row = $sid ? DB::table('students')->where('id', $sid)->whereNull('deleted_at')->first() : null;
        }
        if (!$row) {
            $uid = DB::table('direct_registrations')->where('registration_no', $q)->value('user_id');
            $row = $uid ? DB::table('students')->where('user_id', $uid)->whereNull('deleted_at')->first() : null;
        }
        return Student::flat($row);
    }

    private function registrationOf(object $student): ?object
    {
        return DB::table('direct_registrations')
            ->where(function ($q) use ($student) {
                if (!empty($student->user_id)) {
                    $q->where('user_id', $student->user_id);
                }
                if (!empty($student->mobile)) {
                    $q->orWhereRaw('RIGHT(mobile, 10) = RIGHT(?, 10)', [$student->mobile]);
                }
            })
            ->whereNull('deleted_at')->orderByDesc('id')->first();
    }

    private function json($v): array
    {
        return is_array($v) ? $v : (json_decode((string) $v, true) ?: []);
    }

    private function addressLine(object $s, string $p): ?string
    {
        $parts = array_filter(array_map(fn($k) => trim((string) ($s->{"{$p}_{$k}"} ?? '')), ['address', 'city', 'district', 'state', 'pin']));
        return $parts ? implode(', ', $parts) : null;
    }

    private function profile(object $s, ?object $reg): array
    {
        return [
            'id' => $s->id,
            'student_uid' => $s->student_uid ?? null,
            'name' => ($s->name ?? null) ?: (Student::fullName($s) ?: ($reg->name ?? null)),
            'father_name' => ($s->father_name ?? null) ?: ($reg->father_name ?? null),
            'mother_name' => ($s->mother_name ?? null) ?: ($reg->mother_name ?? null),
            'spouse_name' => ($s->spouse_name ?? null) ?: ($reg->spouse_name ?? null),
            'category' => ($s->category ?? null) ?: ($reg->category ?? null),
            'gender' => ($s->gender ?? null) ?: ($reg->gender ?? null),
            'date_of_birth' => ($s->date_of_birth ?? null) ?: ($reg->dob ?? null),
            'domestic_state' => $reg->domestic_state ?? null,
            'mobile' => ($s->mobile ?? null) ?: ($reg->mobile ?? null),
            'email' => ($s->email ?? null) ?: ($reg->email ?? null),
            'registration_no' => $reg->registration_no ?? null,
            'caste_cert_no' => $reg->caste_cert_no ?? null,
            'university_roll_no' => $s->university_roll_no ?? null,
            'aadhar_no' => ($s->aadhar_no ?? null) ?: ($reg->aadhar_no ?? null),
            'abc_id' => ($s->abc_id ?? null) ?: ($reg->abc_id ?? null),
            'ddurn' => ($s->ddurn ?? null) ?: ($reg->ddurn_no ?? null),
            'enrollment_no' => $s->enrollment_no ?? null,
            'family_id' => $reg->family_id ?? null,
            'stream' => $reg->stream ?? null,
            'permanent_address' => $this->addressLine($s, 'permanent'),
            'correspondence_address' => ($s->same_as_permanent ?? false) ? $this->addressLine($s, 'permanent') : $this->addressLine($s, 'correspondence'),
        ] + $this->imageUrls($s);
    }

    /**
     * Photo / signature. They are uploaded with the application (Part 7 documents,
     * document_type photo|signature) and students.photo_path is often empty, so the
     * latest application document wins and the students.* column is only a fallback.
     * Files may sit on the supabase or the public disk, hence FileUrls (not Storage::url).
     */
    private function imageUrls(object $s): array
    {
        $docs = DB::table('student_application_documents as d')
            ->join('student_applications as sa', 'sa.id', '=', 'd.application_id')
            ->where(function ($q) use ($s) {
                $q->where('sa.student_id', $s->id);
                if (!empty($s->user_id)) {
                    $q->orWhere('sa.user_id', $s->user_id);
                }
            })
            ->whereIn('d.document_type', ['photo', 'signature'])
            ->orderByDesc('d.id')
            ->get(['d.id', 'd.document_type', 'd.path', 'd.disk']);
        \App\Support\FileUrls::forDocuments($docs);

        $out = [];
        foreach (['photo' => 'photo_path', 'signature' => 'signature_path'] as $type => $col) {
            $url = $docs->firstWhere('document_type', $type)->url ?? null;
            if (!$url && !empty($s->$col)) {
                $url = \App\Support\FileUrls::urls([$col => $s->$col])[$col] ?? null;
            }
            $out[$type . '_url'] = $url;
        }
        return $out;
    }

    /** Everything the form needs. Never throws for "not eligible" — returns eligible=false + reason. */
    private function build(object $student, string $session, string $mode): array
    {
        $reg = $this->registrationOf($student);
        $out = [
            'mode' => $mode,
            'student' => $this->profile($student, $reg),
            'session_year' => $session,
            'eligible' => false,
            'reason' => null,
        ];

        $adm = DB::table('admissions as a')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->where('a.student_id', $student->id)->where('a.status', 'active')
            ->orderByDesc('a.id')
            ->select('a.*', 'p.short_name as course', 'p.full_name', 'p.level', 'p.total_semesters', 'p.samarth_code')
            ->first();
        if (!$adm) {
            $out['reason'] = 'No active admission found for this student.';
            return $out;
        }
        $program = DB::table('programs')->where('id', $adm->program_id)->first();
        $formType = Part6Rules::formType($program) ?? 'UG';
        $sem = (int) $adm->semester_no + 1;
        $out += [
            'program' => ['id' => (int) $adm->program_id, 'short_name' => $adm->course, 'full_name' => $adm->full_name,
                'level' => $adm->level, 'samarth_code' => $adm->samarth_code, 'total_semesters' => (int) $adm->total_semesters],
            'form_type' => $formType,                       // UG | PG | BED
            'admission' => ['id' => $adm->id, 'semester_no' => (int) $adm->semester_no, 'academic_year' => $adm->academic_year],
            'target_semester' => $sem,
            'course_year' => (int) ceil($sem / 2),
            'pay_before_approval' => $sem % 2 === 0,        // even: pay first; odd: after approval
        ];

        if ($sem > (int) $adm->total_semesters) {
            $out['reason'] = 'Student has already completed the final semester — no further upgrade is possible.';
            return $out;
        }
        if ($session < (string) $adm->academic_year) {
            $out['reason'] = "Session cannot be earlier than the previous admission session ({$adm->academic_year}).";
            return $out;
        }

        // Admission Schedule window (course + target semester + session).
        $sched = DB::table('application_schedules')
            ->where('program_id', $adm->program_id)->where('session_year', $session)
            ->where('semester_no', (string) $sem)->where('exam_mode', '!=', 'Back Paper')
            ->orderByDesc('id')->first();
        $now = now();
        $state = 'not_scheduled';
        if ($sched) {
            $state = $now->lt(Carbon::parse($sched->start_admission)) ? 'not_open'
                : ($now->lte(Carbon::parse($sched->close_admission)) ? 'open' : 'closed');
        }
        $out['window'] = [
            'state' => $state,
            'start' => $sched->start_admission ?? null,
            'close' => $sched->close_admission ?? null,
            'late_fee_applicable' => (bool) ($sched->late_fee_applicable ?? false),
            'late_fee' => (float) ($sched->late_fee ?? 0),
        ];
        // Student: only inside the window. Office: may proceed without / outside it (the form shows a
        // "semester upgrade is not started" notice first) — `window.state` tells the form which case it is.
        $windowOk = $mode === 'college' ? true : $state === 'open';
        if (!$windowOk) {
            $out['reason'] = match (true) {
                $state === 'not_scheduled' => "No Admission Schedule is set for semester {$sem}, session {$session}.",
                $state === 'not_open' => 'Semester ' . $sem . ' admission opens on ' . Carbon::parse($sched->start_admission)->format('d-m-Y h:i A') . '.',
                default => 'Semester ' . $sem . ' admission closed on ' . Carbon::parse($sched->close_admission)->format('d-m-Y h:i A') . '.',
            };
        }

        // This session's application for the target semester.
        $apps = DB::table('student_applications')
            ->where(function ($q) use ($student) {
                $q->where('student_id', $student->id);
                if (!empty($student->user_id)) {
                    $q->orWhere('user_id', $student->user_id);
                }
            })
            ->where('application_type', 'semester_upgrade')->where('program_id', $adm->program_id)
            ->where('semester_no', $sem)->where('academic_year', $session)
            ->whereNull('deleted_at')->orderByDesc('id')->get();
        $active = $apps->first(fn($a) => in_array($a->status, self::ACTIVE, true));
        $cancelled = !$active && $apps->contains(fn($a) => $a->status === 'cancelled');
        if ($cancelled && !$out['reason']) {
            $out['reason'] = "The semester {$sem} application was cancelled for session {$session}; the student can apply again in the next session.";
        }

        // Subjects: this application's if saved, else carried over from the previous admission.
        $prevApp = !empty($adm->application_id) ? DB::table('student_applications')->where('id', $adm->application_id)->first() : null;
        $prev6 = $prevApp ? $this->json($prevApp->part_6) : [];
        $own6 = $active ? $this->json($active->part_6) : [];
        $src6 = $own6 ?: $prev6;

        $subjectName = fn($ids) => $ids ? DB::table('subjects')->whereIn('id', $ids)->pluck('name', 'id') : collect();
        if ($formType === 'UG') {
            $ids = Part6Rules::subjectSlots($prev6) ?: Part6Rules::subjectSlots($reg ?? []);
            $names = $subjectName($ids);
            $out['subjects'] = collect($ids)->map(fn($id, $i) => ['slot' => $i + 1, 'id' => (int) $id, 'name' => $names[$id] ?? ('Subject #' . $id)])->values()->all();
        } else {
            $sid = (int) ($prev6['subject_id'] ?? ($reg->subject_id ?? 0));
            $names = $subjectName($sid ? [$sid] : []);
            $out['subjects'] = $sid ? [['slot' => 1, 'id' => $sid, 'name' => $names[$sid] ?? ('Subject #' . $sid)]] : [];
        }
        $out['subject_id'] = $out['subjects'][0]['id'] ?? null;   // PG / B.Ed.

        // Drop / ITRP: UG 3rd year. 5th semester: drop one of the three, pick the ITRP subject
        // from the remaining two; the one left over becomes ITRP in the 6th automatically.
        $subjIds = collect($out['subjects'])->pluck('id')->all();
        $out['drop_itrp'] = ['mode' => 'none'];
        if ($formType === 'UG' && $sem === 5) {
            $out['drop_itrp'] = ['mode' => 'choose'];
        } elseif ($formType === 'UG' && $sem === 6) {
            $d = (int) ($prev6['drop_subject'] ?? 0);
            $i = (int) ($prev6['itrp_subject'] ?? 0);
            $left = array_values(array_diff($subjIds, array_filter([$d, $i])));
            $out['drop_itrp'] = ['mode' => 'auto', 'drop_subject' => $d ?: null, 'itrp_subject' => $left[0] ?? null];
        }

        // Options for the target semester.
        $voc = DB::table('vocational_papers')->where('program_id', $adm->program_id)
            ->where('session_year', $session)->where('semester_no', (string) $sem)
            ->orderBy('group_no')->orderBy('paper_name')->get(['id', 'paper_code', 'paper_name', 'group_no', 'group_name']);
        $pick = fn($re) => $voc->filter(fn($p) => preg_match($re, (string) $p->group_name))->values()->all();
        $out['options'] = [
            'aec' => $pick('/\bAEC\b|ability\s*enhancement/i'),
            'sec' => $pick('/\bSEC\b|skill\s*enhancement/i'),
        ];
        if ($formType !== 'UG') {
            $papers = Part6Rules::papersFor((object) ['program_id' => $adm->program_id, 'semester_no' => $sem, 'academic_year' => $session], $formType, $out['subject_id']);
            $row = fn($p) => ['id' => (int) $p->id, 'paper_code' => $p->paper_code, 'paper_name' => $p->paper_name, 'credits' => $p->credits ?? null];
            $out['options']['compulsory'] = $papers->where('selection_type', 'Compulsory')->map($row)->values()->all();
            $out['options']['optional'] = [
                1 => $papers->filter(fn($p) => $p->selection_type === 'Optional' && (int) $p->optional_group === 1)->map($row)->values()->all(),
                2 => $papers->filter(fn($p) => $p->selection_type === 'Optional' && (int) $p->optional_group === 2)->map($row)->values()->all(),
            ];
            $out['options']['teaching'] = Part6Rules::teachingAllowed($formType, $sem)
                ? $papers->where('selection_type', 'Teaching Subject')->map($row)->values()->all() : [];
        } else {
            // Papers of the three subjects for this semester (display only).
            $papers = $subjIds ? DB::table('subject_papers')->where('program_id', $adm->program_id)
                ->where('semester_no', (string) $sem)->where('session_year', $session)
                ->whereIn('subject_id', $subjIds)->orderBy('subject_id')->orderBy('paper_code')
                ->get(['subject_id', 'paper_code', 'paper_name', 'paper_type']) : collect();
            $out['subject_papers'] = $papers->groupBy('subject_id')->map(fn($r) => $r->values())->all();
        }

        // Saved form data (or blank), falling back to the student's profile.
        $p1 = $active ? $this->json($active->part_1) : [];
        $p3 = $active ? $this->json($active->part_3) : [];
        $p4 = $active ? $this->json($active->part_4) : [];
        $profile = $out['student'];
        $out['form'] = [
            'aadhar_no' => $p1['aadhar_no'] ?? $profile['aadhar_no'],
            'abc_id' => $p1['abc_id'] ?? $profile['abc_id'],
            'ddurn' => $p1['ddurn'] ?? $profile['ddurn'],
            'enrollment_no' => $p1['enrollment_no'] ?? $profile['enrollment_no'],
            'family_id' => $p1['family_id'] ?? $profile['family_id'],
            'email' => $p1['email'] ?? $profile['email'],
            'cgpa' => $p3['cgpa'] ?? null,
            'result' => $p3['result'] ?? null,
            'tc_status' => $p4['tc_status'] ?? ($prevApp ? ($this->json($prevApp->part_4)['tc_status'] ?? null) : null) ?? 'Not Attached',
            'migration_status' => $p4['migration_status'] ?? ($prevApp ? ($this->json($prevApp->part_4)['migration_status'] ?? null) : null) ?? 'Not Applicable',
            'aec_subject' => $own6['aec_subject'] ?? null,
            'sec_subject' => $own6['sec_subject'] ?? null,
            'drop_subject' => $own6['drop_subject'] ?? null,
            'itrp_subject' => $own6['itrp_subject'] ?? null,
            'optional_paper_ids' => $own6['optional_paper_ids'] ?? [],
            'teaching_paper_ids' => $own6['teaching_paper_ids'] ?? [],
        ];

        // Fee (preview from the master until saved; then from the saved selection) and what is paid.
        $feeSa = $active ?: (object) [
            'id' => null, 'student_id' => $student->id, 'user_id' => $student->user_id ?? null,
            'organization_id' => $adm->organization_id ?? null, 'program_id' => $adm->program_id,
            'academic_year' => $session, 'semester_no' => $sem, 'application_type' => 'semester_upgrade',
            'direct_registration_id' => $reg->id ?? null, 'part_1' => [], 'part_3' => [], 'part_6' => $prev6,
        ];
        try {
            $fee = app(ApplicationController::class)->computeApplicationFee($feeSa);
            $out['fee'] = [
                'total' => (float) $fee['total'], 'misc_total' => (float) $fee['misc_total'],
                'missing_structure' => (bool) $fee['missing_structure'],
                'breakdown' => collect($fee['breakdown'])->map(fn($b) => ['label' => $b['label'] ?? null, 'fee_head_id' => $b['fee_head_id'] ?? null, 'amount' => (float) $b['amount'], 'misc' => !empty($b['misc'])])->values()->all(),
            ];
        } catch (\Throwable $e) {
            report($e);
            $out['fee'] = null;
        }
        $out['registration_fee'] = $this->registrationFee($adm->program_id, $session, $sem, $profile);

        // This semester's registration (a direct_registrations row) — the gate in front of the form.
        $semReg = $this->semesterRegistration($student, (int) $adm->program_id, $session, $sem);
        $out['registration'] = $semReg ? $this->registrationInfo($semReg) : null;
        $out['registration_gate'] = !$semReg ? 'none' : ($out['registration']['complete'] ? 'done' : 'pending');
        $out['registration_preview'] = [
            'name' => $profile['name'], 'father_name' => $profile['father_name'], 'mother_name' => $profile['mother_name'],
            'spouse_name' => $profile['spouse_name'], 'dob' => $profile['date_of_birth'], 'gender' => $profile['gender'],
            'category' => $profile['category'], 'domestic_state' => $profile['domestic_state'], 'mobile' => $profile['mobile'],
            'email' => $profile['email'], 'aadhar_no' => $profile['aadhar_no'], 'abc_id' => $profile['abc_id'],
            'previous_registration_no' => $reg->registration_no ?? null,
            'program' => $adm->course, 'program_full' => $adm->full_name, 'reg_type' => $reg->reg_type ?? $formType,
            'session' => $session, 'semester' => $sem, 'fee' => $out['registration_fee'],
        ];

        $out['application'] = null;
        if ($active) {
            $paid = Payments::paidApplicationPayment((int) $active->id);
            $receipt = $paid && $paid->fee_receipt_id ? DB::table('fee_receipts')->where('id', $paid->fee_receipt_id)->first() : null;
            $status = $active->status;
            $out['application'] = [
                'id' => (int) $active->id,
                'application_no' => $active->application_no,
                'status' => $status,
                'created_at' => $active->created_at,
                'submitted_at' => $active->declaration_at ?? $active->created_at,
                'approved_at' => $active->approved_at ?? null,
                'editable' => in_array($status, self::EDITABLE, true) && !$paid,
                'cancellable' => !$paid,
                'fee_paid' => (bool) $paid,
                'paid_at' => $paid->paid_at ?? null,
                'pay_ref' => $receipt->transaction_id ?? ($paid->gateway_payment_id ?? null),
                'paid_amount' => (float) ($receipt->net_amount ?? 0),
                'receipt_no' => $receipt->receipt_no ?? null,
                'fee_receipt_id' => $receipt->id ?? null,
                'can_pay' => !$paid && ($out['pay_before_approval']
                    ? in_array($status, ['submitted', 'under_review', 'approved'], true)
                    : $status === 'approved'),
            ];
        }

        if (!$out['reason']) {
            $out['eligible'] = true;
        }
        // The form itself opens only after this semester's registration is complete (student portal).
        // Same gate for the office: registration (accept, then pay if there is a fee) comes before the form.
        $out['form_open'] = $out['eligible'] && $out['registration_gate'] === 'done';
        return $out;
    }

    /** This session's non-cancelled registration row for the target semester. */
    private function semesterRegistration(object $student, int $programId, string $session, int $sem): ?object
    {
        return DB::table('direct_registrations')
            ->where(function ($q) use ($student) {
                if (!empty($student->user_id)) {
                    $q->where('user_id', $student->user_id);
                }
                if (!empty($student->mobile)) {
                    $q->orWhereRaw('RIGHT(mobile, 10) = RIGHT(?, 10)', [$student->mobile]);
                }
            })
            ->where('program_id', $programId)->where('session_year', $session)->where('semester_no', $sem)
            ->where('status', '!=', 'cancelled')->whereNull('deleted_at')->orderByDesc('id')->first();
    }

    private function registrationInfo(object $r): array
    {
        $paid = Payments::paidRegistrationPayment((int) $r->id);
        $fee = (float) ($r->fee_amount ?? 0);
        return [
            'id' => (int) $r->id,
            'registration_no' => $r->registration_no,
            'semester_no' => (int) $r->semester_no,
            'session_year' => $r->session_year,
            'status' => $r->status,
            'reg_date' => $r->reg_date,
            'fee' => $fee,
            'free' => $fee <= 0,
            'paid' => (bool) $paid,
            'paid_at' => $paid->paid_at ?? null,
            'receipt_no' => $paid->receipt_no ?? null,
            'complete' => $r->status === 'registered' || (bool) $paid,
        ];
    }

    /** Registration Fee row — shown only when the Registration Fee master has an amount for this semester. */
    private function registrationFee($programId, string $session, int $sem, array $profile): array
    {
        $row = DB::table('registration_fees')->where('program_id', $programId)->where('session_year', $session)
            ->where('semester_no', (string) $sem)->where('registration_mode', 'Regular')->orderByDesc('id')->first();
        $amount = 0;
        $configured = false;
        if ($row && $row->amounts) {
            $a = $this->json($row->amounts);
            $g = strtolower((string) ($profile['gender'] ?: 'male'));
            $c = strtolower((string) ($profile['category'] ?: 'gen'));
            $c = in_array($c, ['general', 'ur', 'unreserved'], true) ? 'gen' : $c;
            $key = array_key_exists("{$g}_{$c}", $a) ? "{$g}_{$c}" : (array_key_exists("{$g}_gen", $a) ? "{$g}_gen" : null);
            if ($key !== null) {
                $configured = true;
                $amount = (float) $a[$key];
            }
        }
        // configured = the master has an entry (0 is a valid "free" entry); no entry blocks registration.
        return ['configured' => $configured, 'allowed' => $amount > 0, 'amount' => $amount];
    }

    private function save(Request $req, object $student, string $mode): JsonResponse
    {
        $session = $this->session($req);
        $ctx = $this->build($student, $session, $mode);
        if (!$ctx['eligible']) {
            return response()->json(['message' => $ctx['reason'] ?: 'Not eligible for semester upgrade.'], 422);
        }
        if (empty($ctx['form_open'])) {
            return response()->json(['message' => 'Complete this semester\'s registration first (Registration Dashboard), then fill the upgrade form.'], 422);
        }
        $app = $ctx['application'];
        if ($app && !$app['editable']) {
            return response()->json(['message' => 'This application can no longer be edited (' . ($app['fee_paid'] ? 'fee paid' : $app['status']) . ').'], 422);
        }

        $v = Validator::make($req->all(), [
            'aadhar_no' => 'required|string|max:20',
            'abc_id' => 'required|string|max:50',
            'enrollment_no' => 'required|string|max:50',
            'ddurn' => 'nullable|string|max:50',
            'family_id' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:150',
            'cgpa' => 'required|numeric|min:0|max:10',
            'result' => 'required|in:' . implode(',', self::RESULTS),
            'tc_status' => 'required|in:Attached,Not Attached,Not Required',
            'migration_status' => 'nullable|in:Attached,Not Attached,Not Applicable,Yes,No',
            'aec_subject' => 'nullable|integer', 'sec_subject' => 'nullable|integer',
            'drop_subject' => 'nullable|integer', 'itrp_subject' => 'nullable|integer',
            'optional_paper_ids' => 'nullable|array', 'teaching_paper_ids' => 'nullable|array',
        ]);
        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first(), 'errors' => $v->errors()], 422);
        }

        $opt = $ctx['options'];
        $form = $ctx['form_type'];
        $subjIds = collect($ctx['subjects'])->pluck('id')->map(fn($x) => (int) $x)->all();
        $part6 = ['form_type' => $form === 'UG' ? null : $form, 'upgrade_from_semester' => $ctx['admission']['semester_no']];
        $part6 = array_filter($part6, fn($x) => $x !== null);

        $in = fn(string $k) => $req->filled($k) ? (int) $req->input($k) : null;
        $vocIds = fn(array $list) => array_map(fn($p) => (int) $p->id, $list);

        // AEC / SEC — must be one of the configured papers; AEC is required whenever any is configured.
        foreach (['aec' => 'AEC', 'sec' => 'SEC'] as $k => $label) {
            $val = $in($k . '_subject');
            $allowed = $vocIds($opt[$k]);
            if ($val && !in_array($val, $allowed, true)) {
                return response()->json(['message' => "Selected {$label} is not available for this semester."], 422);
            }
            if ($k === 'aec' && $allowed && !$val) {
                return response()->json(['message' => 'Select the AEC (Ability Enhancement Course).'], 422);
            }
            if ($val) {
                $part6[$k . '_subject'] = $val;
            }
        }

        if ($form === 'UG') {
            if (count($subjIds) < 3) {
                return response()->json(['message' => 'The three subjects of the previous admission were not found; fix the admission record first.'], 422);
            }
            foreach ($subjIds as $i => $id) {
                $part6['subject_' . ($i + 1)] = $id;
            }
            $part6['selected_subjects'] = $subjIds;
            $di = $ctx['drop_itrp'];
            if ($di['mode'] === 'choose') {
                $drop = $in('drop_subject');
                $itrp = $in('itrp_subject');
                if (!$drop || !in_array($drop, $subjIds, true)) {
                    return response()->json(['message' => 'Select the subject to drop (one of your three subjects).'], 422);
                }
                if (!$itrp || $itrp === $drop || !in_array($itrp, $subjIds, true)) {
                    return response()->json(['message' => 'Select the ITRP subject from the two subjects that remain after the drop.'], 422);
                }
                $part6['drop_subject'] = $drop;
                $part6['itrp_subject'] = $itrp;
            } elseif ($di['mode'] === 'auto') {
                $part6['drop_subject'] = $di['drop_subject'];
                $part6['itrp_subject'] = $di['itrp_subject'];
            }
        } else {
            if ($form === 'PG') {
                $part6['subject_id'] = $ctx['subject_id'];
            }
            $compulsory = array_map('intval', array_column($opt['compulsory'], 'id'));
            $part6['compulsory_paper_ids'] = $compulsory;
            $part6['selected_subjects'] = $compulsory ?: ($ctx['subject_id'] ? [(int) $ctx['subject_id']] : []);
            $picked = Part6Rules::pickOptional(array_map('intval', (array) $req->input('optional_paper_ids', [])),
                collect([1, 2])->flatMap(fn($g) => collect($opt['optional'][$g])->map(fn($p) => (object) ['id' => $p['id'], 'selection_type' => 'Optional', 'optional_group' => $g]))->values());
            foreach ([1, 2] as $g) {
                if ($opt['optional'][$g] && !array_intersect($picked, array_column($opt['optional'][$g], 'id'))) {
                    return response()->json(['message' => "Select Optional Paper -{$g}."], 422);
                }
            }
            $part6['optional_paper_ids'] = $picked;
            $part6['selected_optional_subjects'] = $picked;
            if ($form === 'BED' && $opt['teaching']) {
                $tIds = array_column($opt['teaching'], 'id');
                $t = array_values(array_intersect(array_map('intval', (array) $req->input('teaching_paper_ids', [])), $tIds));
                if (count($t) < min(Part6Rules::TEACHING_SLOTS, count($tIds))) {
                    return response()->json(['message' => 'Select the Teaching Subjects.'], 422);
                }
                $part6['teaching_paper_ids'] = $t;
            }
        }

        $profile = $ctx['student'];
        $part1 = array_filter([
            'exam_mode' => 'Regular',
            'course_year' => (string) $ctx['course_year'],
            'aadhar_no' => $req->aadhar_no, 'abc_id' => $req->abc_id, 'ddurn' => $req->ddurn,
            'enrollment_no' => $req->enrollment_no, 'family_id' => $req->family_id, 'email' => $req->email,
            'domestic_state' => $profile['domestic_state'],
        ], fn($x) => $x !== null && $x !== '');
        $part3 = ['cgpa' => (string) $req->cgpa, 'result' => $req->result];
        $part4 = array_filter(['tc_status' => $req->tc_status, 'migration_status' => $req->migration_status], fn($x) => $x !== null && $x !== '');
        $progress = ['part1' => true, 'part3' => true, 'part4' => true, 'part6' => true];

        $reg = $this->registrationOf($student);
        $row = [
            'part_1' => json_encode($part1), 'part_3' => json_encode($part3), 'part_4' => json_encode($part4),
            'part_6' => json_encode($part6), 'form_progress' => json_encode($progress), 'updated_at' => now(),
        ];

        if ($app) {
            DB::table('student_applications')->where('id', $app['id'])->update($row + ['status' => 'submitted']);
            $id = (int) $app['id'];
            $appNo = $app['application_no'];
        } else {
            $n = ((int) DB::table('student_applications')->max('id')) + 1;
            do {
                $appNo = 'SA-' . date('Y') . '-' . str_pad((string) $n++, 6, '0', STR_PAD_LEFT);
            } while (DB::table('student_applications')->where('application_no', $appNo)->exists());
            $id = (int) DB::table('student_applications')->insertGetId($row + [
                'organization_id' => $req->user()->organization_id,
                'user_id' => $student->user_id ?? null,
                'student_id' => $student->id,
                'program_id' => $ctx['program']['id'],
                'direct_registration_id' => ($ctx['registration']['id'] ?? null) ?: ($reg->id ?? null),
                'academic_year' => $session,
                'application_type' => 'semester_upgrade',
                'semester_no' => $ctx['target_semester'],
                'application_no' => $appNo,
                'status' => 'submitted',
                'declaration_at' => now(),
                'created_at' => now(),
            ]);
        }
        app(ApplicationController::class)->syncAdmissionForApplication($id);

        return response()->json([
            'id' => $id, 'application_no' => $appNo,
            'message' => $app ? 'Application updated.' : 'Application saved.',
            'context' => $this->build($student, $session, $mode),
        ], $app ? 200 : 201);
    }

    private function cancel(Request $req, int $id, ?int $onlyStudentId): JsonResponse
    {
        $sa = DB::table('student_applications')->where('id', $id)->where('application_type', 'semester_upgrade')->whereNull('deleted_at')->first();
        if (!$sa || ($onlyStudentId && (int) $sa->student_id !== $onlyStudentId)) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        if (!in_array($sa->status, self::ACTIVE, true)) {
            return response()->json(['message' => "An application that is {$sa->status} cannot be cancelled."], 422);
        }
        if (Payments::applicationFeePaid($id)) {
            return response()->json(['message' => 'The fee is already paid — this application cannot be cancelled here.'], 422);
        }
        DB::table('student_applications')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        DB::table('admissions')->where('application_id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        return response()->json(['message' => 'Application cancelled for this session. The student can apply again in the next session.']);
    }
}
