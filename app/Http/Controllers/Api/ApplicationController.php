<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Concerns\LocksStudentIdentity;
use App\Jobs\GenerateFeeReceiptPdf;
use App\Models\FeeReceipt;
use App\Services\AdmissionNumberService;
use App\Support\TextNormalizer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;


class ApplicationController extends Controller
{
    use LocksStudentIdentity;

    // =========================================================================
    // SHARED HELPER
    // =========================================================================
    private function parseApp(object $sa, ?Request $req = null): object
    {
        $jsonCols = [
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
            'selected_optional_subjects',
        ];

        foreach ($jsonCols as $col) {
            if (isset($sa->$col) && is_string($sa->$col)) {
                $sa->$col = json_decode($sa->$col, true);
            }
        }

        $sa->student = DB::table('students')
            ->where('id', $sa->student_id)
            ->first();

        if ($sa->student) {
            $sa->student->name = trim(implode(' ', array_filter([
                $sa->student->first_name ?? null,
                $sa->student->middle_name ?? null,
                $sa->student->last_name ?? null,
            ])));
            $sa->student->dob   = $sa->student->date_of_birth ?? null;
            $sa->student->state = $sa->student->permanent_state ?? null;
        }

        $sa->program = DB::table('programs')
            ->where('id', $sa->program_id)
            ->first();

        $sa->admission = DB::table('admissions')
            ->where('student_id', $sa->student_id)
            ->where('program_id', $sa->program_id)
            ->orderByDesc('id')
            ->first();

        $viewerUserId = ($req && $req->user() && method_exists($req->user(), 'isStudent') && $req->user()->isStudent())
            ? $req->user()->id
            : null;

        $sa->registration = DB::table('direct_registrations')
            ->where(function ($q) use ($sa, $viewerUserId) {
                if ($viewerUserId) {
                    $q->where('user_id', $viewerUserId);
                }
                if ($sa->student) {
                    $q->orWhere('user_id', $sa->student->user_id);
                    if (!empty($sa->student->mobile)) {
                        $q->orWhereRaw('RIGHT(mobile, 10) = RIGHT(?, 10)', [$sa->student->mobile]);
                    }
                    if (!empty($sa->student->email)) {
                        $q->orWhere('email', $sa->student->email);
                    }
                }
            })
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();
        $sa->has_registration_snapshot = (bool) $sa->registration;

        // Visible proof the lookup actually ran (and what it searched with) —
        // shows up straight in the API response so this can be verified from
        // the browser network tab without needing server log access.
        $sa->registration_lookup_debug = [
            'application_id'    => $sa->id,
            'student_id'        => $sa->student->id ?? null,
            'student_found'     => (bool) $sa->student,
            'viewer_user_id'    => $viewerUserId,
            'searched_user_id'  => $sa->student->user_id ?? null,
            'searched_mobile'   => $sa->student->mobile ?? null,
            'searched_email'    => $sa->student->email ?? null,
            'matched'           => (bool) $sa->registration,
            'matched_registration_id' => $sa->registration->id ?? null,
        ];

        if (!$sa->registration) {
            Log::warning('No direct_registrations match for application identity prefill', $sa->registration_lookup_debug);
        }

        $sa->documents = DB::table('student_application_documents')
            ->where('application_id', $sa->id)
            ->get();

        return $sa;
    }

    // =========================================================================
    // COLLEGE SIDE
    // =========================================================================

    /**
     * GET /college/applications
     */
    public function index(Request $req)
    {
        // Latest admission per (student, program) — drives the "paid" flag
        // (education fee payment status), same source of truth used by
        // RegistrationController's edu_fee_paid.
        $latestAdmission = DB::table('admissions')
            ->select('student_id', 'program_id', DB::raw('MAX(id) as admission_id'))
            ->groupBy('student_id', 'program_id');

        // `students` has no name/father_name/mother_name/dob columns (only
        // first_name/middle_name/last_name + date_of_birth) — those identity
        // fields live on direct_registrations. Pull the latest registration
        // per user via a subquery, same source parseApp() uses.
        $latestReg = DB::table('direct_registrations')
            ->select('user_id', DB::raw('MAX(id) as reg_id'))
            ->whereNull('deleted_at')
            ->groupBy('user_id');

        $q = DB::table('student_applications as sa')
            ->join('students as s', 's.id', 'sa.student_id')
            ->join('programs as p', 'p.id', 'sa.program_id')
            ->leftJoinSub($latestAdmission, 'la', function ($j) {
                $j->on('la.student_id', 'sa.student_id')->on('la.program_id', 'sa.program_id');
            })
            ->leftJoin('admissions as adm', 'adm.id', 'la.admission_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->whereNull('sa.deleted_at')
            ->select(
                'sa.id',
                'sa.application_no',
                'sa.academic_year',
                'sa.application_type',
                'sa.semester_no',
                'sa.status',
                'sa.form_progress',
                'sa.fee_amount',
                'sa.fee_paid',
                'sa.created_at',
                'sa.updated_at',
                's.first_name',
                's.middle_name',
                's.last_name',
                'dr.name',
                'dr.father_name',
                'dr.mother_name',
                's.mobile',
                's.gender',
                's.date_of_birth as dob',
                's.category',
                'p.short_name as class',
                'p.full_name',
                'p.level',
                'adm.payment_status as edu_payment_status'
            )
            ->when($req->academic_year, fn($q) => $q->where('sa.academic_year', $req->academic_year))
            ->when($req->program_id, fn($q) => $q->where('sa.program_id', $req->program_id))
            ->when($req->application_type ?? $req->type, fn($q, $v) => $q->where('sa.application_type', $v))
            ->when($req->status, fn($q) => $q->where('sa.status', $req->status))
            ->when($req->search, fn($q) => $q->where(function ($q2) use ($req) {
                $q2->where('dr.name', 'ilike', "%{$req->search}%")
                    ->orWhere('sa.application_no', 'ilike', "%{$req->search}%")
                    ->orWhere('s.mobile', 'ilike', "%{$req->search}%")
                    ->orWhere('s.aadhar_no', 'ilike', "%{$req->search}%")
                    ->orWhere('s.abc_id', 'ilike', "%{$req->search}%");
            }))
            ->orderByDesc('sa.created_at');

        // Note: filter by application_type via ?application_type=back_paper etc.

        $result = $q->paginate(50);
        $result->getCollection()->transform(function ($row) {
            $row->form_progress = json_decode($row->form_progress ?? '{}', true);
            // Fall back to the students-table name parts if no registration
            // snapshot was found for this row (dr.name came back null).
            if (empty($row->name)) {
                $row->name = trim(implode(' ', array_filter([
                    $row->first_name ?? null,
                    $row->middle_name ?? null,
                    $row->last_name ?? null,
                ]))) ?: null;
            }
            $row->dob = $row->dob ?? null;
            // "Completed" = final-submitted (or further along the pipeline).
            $row->completed = !in_array($row->status, ['draft', 'cancelled'], true);
            // "Paid" — back paper applications track their own exam fee
            // (sa.fee_paid); every other type is gated on the education fee
            // paid against the linked admission record.
            $row->paid = $row->application_type === 'back_paper'
                ? (bool) $row->fee_paid
                : $row->edu_payment_status === 'paid';
            return $row;
        });

        return response()->json($result);
    }

    public function studentShow(Request $req, $id)
    {
        $student = DB::table('students')->where('user_id', $req->user()->id)->first();
        if (!$student)
            return response()->json(['message' => 'Student profile not found.'], 404);

        $sa = DB::table('student_applications')
            ->where('id', $id)
            ->where('student_id', $student->id)   // ownership check
            ->whereNull('deleted_at')
            ->first();

        if (!$sa)
            return response()->json(['message' => 'Application not found.'], 404);

        return response()->json($this->parseApp($sa, $req));
    }

    /**
     * GET /college/applications/office-lookup?q=
     * Header band lookup: returns { student, admission, program } + application_id.
     */
    /**
     * REBUILT: was querying a.reg_no / a.application_no directly off
     * `admissions` — neither column exists there (admissions has no
     * application_no/reg_no at all; the real identifier is student_
     * applications.application_no, and registration_no lives on
     * direct_registrations). Also `admissions` no longer necessarily exists
     * for a given student — it's only created once the college approves an
     * application (see ApplicationController::updateStatus). So this now
     * searches student_applications directly, which is what every caller
     * actually wants (they immediately pull admission.application_id to
     * jump into the application form).
     */
    public function officeLookup(Request $req)
    {
        $v = Validator::make($req->all(), ['q' => 'required|string|min:3']);
        if ($v->fails()) {
            return response()->json(['message' => 'Enter at least 3 characters.'], 422);
        }

        $q = trim($req->q);

        $latestReg = DB::table('direct_registrations')
            ->select('user_id', DB::raw('MAX(id) as reg_id'))
            ->whereNull('deleted_at')
            ->groupBy('user_id');

        $app = DB::table('student_applications as sa')
            ->join('students as s', 's.id', 'sa.student_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->where(function ($qb) use ($q) {
                $qb->where('sa.application_no', $q)
                    ->orWhere('dr.registration_no', $q)
                    ->orWhere('s.university_roll_no', $q)
                    ->orWhere('s.enrollment_no', $q)
                    ->orWhere('s.mobile', $q)
                    ->orWhere('s.aadhar_no', $q)
                    ->orWhere('s.abc_id', $q);
            })
            ->whereNull('sa.deleted_at')
            ->select('sa.*', 'dr.registration_no')
            ->orderByDesc('sa.created_at')
            ->first();

        if (!$app) {
            return response()->json(['message' => 'No record found.'], 404);
        }

        $student = DB::table('students')->where('id', $app->student_id)->first();
        $program = DB::table('programs')->where('id', $app->program_id)->first();

        // Only exists once the college has approved this exact application.
        $admissionRow = DB::table('admissions')->where('application_id', $app->id)->first();

        return response()->json([
            'student' => $student,
            'admission' => array_merge((array) $app, [
                'application_id'     => $app->id,
                'reg_no'             => $app->registration_no,
                'registration_no'    => $app->registration_no,
                'roll_no'            => $student->university_roll_no ?? null,
                'university_roll_no' => $student->university_roll_no ?? null,
                'admission_id'       => $admissionRow->id ?? null,
                'admission_no'       => $admissionRow->admission_no ?? null,
            ]),
            'program' => $program,
        ]);
    }

    /**
     * POST /college/applications
     * Office creates an application on behalf of a student (back paper, upgrade, etc.)
     */
    public function storeOffice(Request $req)
    {
        $req->validate([
            'student_id' => 'required|exists:students,id',
            'program_id' => 'required|exists:programs,id',
            'academic_year' => 'required|string|max:10',
            'application_type' => 'required|in:back_paper,semester_upgrade,lateral',
            'semester_no' => 'nullable|integer|min:1|max:12',
            'paper_ids' => 'nullable|array',
        ]);

        $existing = DB::table('student_applications')
            ->where('student_id', $req->student_id)
            ->where('program_id', $req->program_id)
            ->where('academic_year', $req->academic_year)
            ->where('application_type', $req->application_type)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'An active application already exists.',
                'id' => $existing->id,
            ], 409);
        }

        $seq = DB::table('student_applications')->count() + 1;
        $appNo = 'SA-' . date('Y') . '-' . str_pad($seq, 6, '0', STR_PAD_LEFT);

        // Store paper selection in part_7 if provided
        $part7 = $req->paper_ids ? json_encode(['paper_ids' => $req->paper_ids]) : null;

        $id = DB::table('student_applications')->insertGetId([
            'organization_id' => $req->user()->organization_id,
            'student_id' => $req->student_id,
            'program_id' => $req->program_id,
            'academic_year' => $req->academic_year,
            'application_type' => $req->application_type,
            'semester_no' => $req->semester_no,
            'application_no' => $appNo,
            'status' => 'submitted',   // office-created apps go straight to submitted
            'form_progress' => json_encode($part7 ? ['part7' => true] : []),
            'part_7' => $part7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'id' => $id,
            'application_no' => $appNo,
            'message' => 'Application created by office.',
        ], 201);
    }

    /**
     * PATCH /college/applications/{id}/status
     * College sets application status: approved | rejected | submitted | on_hold | cancelled
     */
    public function updateStatus(Request $req, $id)
    {
        $req->validate([
            'status' => 'required|in:approved,rejected,submitted,on_hold,cancelled',
            'reason' => 'nullable|string|max:500',
        ]);

        $app = DB::table('student_applications')
            ->where('id', $id)->whereNull('deleted_at')->first();
        if (!$app)
            return response()->json(['message' => 'Application not found.'], 404);

        $update = [
            'status'      => $req->status,
            'remarks'     => $req->reason ?? $app->remarks,
            'reviewed_by' => $req->user()->id,
            'reviewed_at' => now(),
            'updated_at'  => now(),
        ];

        if ($req->status === 'approved') {
            // Gate: the education fee must be paid before the college can
            // accept the application. Only once BOTH are true (fee paid +
            // college accepts, right here) does this become "an actual
            // student" — admissions row created, students row confirmed.
            if (!$app->fee_paid) {
                return response()->json(['message' => 'Education fee must be paid before the application can be approved.'], 422);
            }

            $update['approved_by'] = $req->user()->id;
            $update['approved_at'] = now();

            DB::transaction(function () use ($app, $req, $id, $update) {
                $this->confirmStudentAndCreateAdmission($app, $req->user()->id);
                DB::table('student_applications')->where('id', $id)->update($update);
            });

            return response()->json(['message' => "Status updated to {$req->status}."]);
        }

        DB::table('student_applications')->where('id', $id)->update($update);

        return response()->json(['message' => "Status updated to {$req->status}."]);
    }

    /**
     * The moment an application is approved (fee already verified paid):
     *  1. Create the admissions row (this is what "actually admitted" means
     *     in this schema — admissions.application_id is a NOT NULL FK back
     *     to this exact application).
     *  2. Flip students.is_confirmed to true and assign the official
     *     student_code if this is the student's first confirmed admission.
     * Idempotent — skips both steps if already done for this application.
     */
    private function confirmStudentAndCreateAdmission(object $app, int $approvedByUserId): void
    {
        $existing = DB::table('admissions')->where('application_id', $app->id)->first();
        if ($existing) {
            return; // already processed (e.g. re-approving after a status bounce)
        }

        $admissionTypeMap = [
            'fresh'            => 'regular',
            'semester_upgrade' => 'upgrade',
            'lateral'          => 'lateral',
            'back_paper'       => 'back_paper',
        ];
        $admissionType = $admissionTypeMap[$app->application_type] ?? 'regular';

        $program = DB::table('programs')->where('id', $app->program_id)->first();
        $student = DB::table('students')->where('id', $app->student_id)->first();

        $svc = app(AdmissionNumberService::class);

        $admissionNo = $app->academic_year . '-' . str_pad((string) $app->id, 6, '0', STR_PAD_LEFT);

        $admissionId = DB::table('admissions')->insertGetId([
            'organization_id' => $app->organization_id,
            'student_id'      => $app->student_id,
            'program_id'      => $app->program_id,
            'application_id'  => $app->id,
            'academic_year'   => $app->academic_year,
            'semester_no'     => $app->semester_no,
            'admission_type'  => $admissionType,
            'admission_no'    => $admissionNo,
            'admission_date'  => now()->toDateString(),
            'is_verified'     => true,
            'verified_by'     => $approvedByUserId,
            'verified_at'     => now(),
            'status'          => 'active',
            'file_no'         => $svc->fileNo($app->academic_year),
            'record_no'       => $svc->recordNo(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $studentUpdate = [
            'is_confirmed'             => true,
            'confirmed_at'             => now(),
            'confirmed_application_id' => $app->id,
            'updated_at'               => now(),
        ];

        // Assign the permanent Student ID once, on first confirmation only.
        if ($student && empty($student->student_code) && $program) {
            try {
                $studentUpdate['student_code'] = $svc->studentId($app->academic_year, $program, $student->category);
            } catch (\Throwable $e) {
                Log::warning("student_code generation failed for student {$app->student_id}: " . $e->getMessage());
            }
        }

        DB::table('students')->where('id', $app->student_id)->update($studentUpdate);
    }

    /**
     * GET /college/applications/{id}   (also used by student side)
     * Full detail — all parts parsed, documents attached.
     */
    public function show(Request $req, $id)
    {
        $sa = DB::table('student_applications')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$sa) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        return response()->json($this->parseApp($sa, $req));
    }

    /**
     * PUT /college/applications/{id}/part/{part}
     * Office edits any part — no student ownership check.
     */
    public function updatePartOffice(Request $req, $id, $part)
    {
        $partNo = (int) $part;
        if ($partNo < 1 || $partNo > 8) {
            return response()->json(['message' => "Invalid part {$part}. Must be 1-8."], 422);
        }

        $app = DB::table('student_applications')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$app)
            return response()->json(['message' => 'Application not found.'], 404);

        $col = 'part_' . $partNo;
        $key = 'part' . $partNo;
        $prog = json_decode($app->form_progress ?? '{}', true);
        $prog[$key] = true;

        DB::table('student_applications')->where('id', $id)->update([
            $col => json_encode(TextNormalizer::upper($req->all())),
            'form_progress' => json_encode($prog),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => "Part {$partNo} updated by office.",
            'form_progress' => $prog,
        ]);
    }

    /**
     * GET /college/applications/{id}/print
     * Full application form PDF — only once the application is completed
     * (final-submitted or beyond draft) AND the education fee is paid.
     */
    public function printForm($id)
    {
        $sa = DB::table('student_applications')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$sa) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        if (in_array($sa->status, ['draft', 'cancelled'], true)) {
            return response()->json(['message' => 'Application form is not completed yet.'], 422);
        }

        // NOTE: admissions has no payment_status column — fee-paid status
        // lives on student_applications.fee_paid (set by the pay/verify
        // flow below). admissions existing at all already implies approval.
        if (!$sa->fee_paid) {
            return response()->json(['message' => 'Education fee is not paid yet. Print is available only after payment.'], 422);
        }

        $admission = DB::table('admissions')
            ->where('application_id', $sa->id)
            ->orderByDesc('id')
            ->first();

        $student = DB::table('students')->where('id', $sa->student_id)->first();
        $program = DB::table('programs')->where('id', $sa->program_id)->first();
        $org     = DB::table('organizations')->where('id', $sa->organization_id)->first();

        $parts = [];
        foreach (range(1, 8) as $n) {
            $col = 'part_' . $n;
            $parts[$n] = !empty($sa->$col) ? json_decode($sa->$col, true) : null;
        }

        try {
            $pdf = Pdf::loadView('pdf.application-form', compact('sa', 'student', 'program', 'org', 'admission', 'parts'))->setPaper('a4');
            return response()->streamDownload(
                fn () => print($pdf->output()),
                "Application-Form-{$sa->application_no}.pdf",
                ['Content-Type' => 'application/pdf']
            );
        } catch (\Throwable $e) {
            Log::error("Application form PDF failed for {$sa->id}: " . $e->getMessage());
            return response()->json(['message' => 'Could not generate the application form PDF.'], 500);
        }
    }

    /**
     * GET /college/applications/hold
     */
    public function holdIndex(Request $req)
    {
        // Same schema note as index(): students has no name/father_name —
        // pull those from the latest direct_registrations row per user.
        $latestReg = DB::table('direct_registrations')
            ->select('user_id', DB::raw('MAX(id) as reg_id'))
            ->whereNull('deleted_at')
            ->groupBy('user_id');

        $q = DB::table('student_applications as sa')
            ->join('students as s', 's.id', 'sa.student_id')
            ->join('programs as p', 'p.id', 'sa.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->where('sa.status', 'on_hold')
            ->whereNull('sa.deleted_at')
            ->select(
                'sa.id',
                'sa.application_no',
                'sa.academic_year',
                'sa.application_type',
                'sa.semester_no',
                'sa.status',
                'sa.remarks',
                'sa.updated_at',
                's.first_name',
                's.middle_name',
                's.last_name',
                'dr.name',
                'dr.father_name',
                's.mobile',
                'p.short_name as class',
                'p.full_name'
            )
            ->when($req->search, fn($q) => $q->where(function ($q2) use ($req) {
                $q2->where('sa.application_no', 'ilike', "%{$req->search}%")
                    ->orWhere('dr.name', 'ilike', "%{$req->search}%")
                    ->orWhere('s.mobile', 'ilike', "%{$req->search}%");
            }))
            ->orderByDesc('sa.updated_at');

        $result = $q->paginate(50);
        $result->getCollection()->transform(function ($row) {
            if (empty($row->name)) {
                $row->name = trim(implode(' ', array_filter([
                    $row->first_name ?? null,
                    $row->middle_name ?? null,
                    $row->last_name ?? null,
                ]))) ?: null;
            }
            return $row;
        });

        return response()->json($result);
    }

    /**
     * POST /college/applications/hold
     */
    public function holdStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'application_id' => 'required|exists:student_applications,id',
            'reason' => 'required|string|min:10',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        DB::table('student_applications')
            ->where('id', $req->application_id)
            ->update([
                'status' => 'on_hold',
                'remarks' => $req->reason,
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Application placed on hold.']);
    }

    /**
     * PATCH /college/applications/{id}/release-hold
     */
    public function holdRelease($id)
    {
        DB::table('student_applications')
            ->where('id', $id)
            ->update(['status' => 'submitted', 'updated_at' => now()]);

        return response()->json(['message' => 'Hold released.']);
    }

    public function backPaperPapers(Request $req)
    {
        $v = Validator::make($req->all(), [
            'application_id' => 'nullable|exists:student_applications,id',
            'admission_id'   => 'nullable|exists:admissions,id',
            'semester_no'    => 'required|integer',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $sa = $req->application_id
            ? DB::table('student_applications')->where('id', $req->application_id)->whereNull('deleted_at')->first()
            : null;

        if ($sa) {
            $programId = $sa->program_id;
            $studentId = $sa->student_id;
        } elseif ($req->admission_id) {
            $admission = DB::table('admissions')->find($req->admission_id);
            if (!$admission) return response()->json(['message' => 'Admission not found.'], 404);
            $programId = $admission->program_id;
            $studentId = $admission->student_id;
        } else {
            return response()->json(['message' => 'application_id or admission_id is required.'], 422);
        }

        $subjectIds = $this->eligibleBackPaperSubjectIds($studentId, $programId);

        // The Subject Master (`subjects`) row *is* the paper — it already
        // carries a real code, per-semester marks, credits, and a `type`
        // (compulsory/optional/elective/practical/project) that tells us
        // whether it prices against the Practical or Examination fee head.
        $papers = DB::table('subjects as sub')
            ->where('sub.program_id', $programId)
            ->where('sub.semester_no', (int) $req->semester_no)
            ->where('sub.is_active', true)
            ->whereNull('sub.deleted_at')
            ->when(!empty($subjectIds), fn($q) => $q->whereIn('sub.id', $subjectIds))
            ->select('sub.*')
            ->orderBy('sub.name')
            ->get();

        $orgId = $req->user()->organization_id;
        $efId  = $this->feeHeadId('EF', $orgId);
        $pfId  = $this->feeHeadId('PF', $orgId);

        $papers->transform(function ($p) use ($efId, $pfId) {
            $isPractical = $p->type === 'practical';
            $p->fee_head_id   = $isPractical ? $pfId : $efId;
            $p->fee_head_code = $isPractical ? 'PF' : 'EF';
            return $p;
        });

        return response()->json($papers);
    }

    /**
     * Subject ids the student actually selected during their fresh /
     * semester-upgrade application(s) for this program — the "already
     * selected while new reg and semester upgrade" set. Falls back to every
     * subject allotted to the program if no selection history exists yet.
     */
    private function eligibleBackPaperSubjectIds(int $studentId, int $programId): array
    {
        $rows = DB::table('student_applications')
            ->where('student_id', $studentId)
            ->where('program_id', $programId)
            ->whereIn('application_type', ['fresh', 'semester_upgrade'])
            ->whereNotNull('selected_subjects')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(2) // current + previous semester's registration/upgrade
            ->pluck('selected_subjects');

        $ids = [];
        foreach ($rows as $raw) {
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (is_array($decoded)) {
                $ids = array_merge($ids, array_map('intval', $decoded));
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));

        if (!empty($ids)) {
            return $ids;
        }

        // No selection history yet (e.g. office-created back-paper for a
        // legacy student) — fall back to every subject allotted to the
        // program so the list is never empty.
        return DB::table('allotted_subjects')
            ->where('program_id', $programId)
            ->pluck('subject_id')
            ->map(fn($v) => (int) $v)
            ->all();
    }

    /**
     * fee_heads.code carries a *global* unique constraint in this schema
     * (see 2024_01_01_000004_create_fee_heads_table), so a code lookup is
     * organization-agnostic by construction — but we still prefer the
     * caller's own organization row when more than one happens to exist.
     */
    private function feeHeadId(string $code, ?int $orgId = null): ?int
    {
        return DB::table('fee_heads')
            ->where('code', $code)
            ->orderByRaw($orgId ? 'organization_id <> ? asc' : '1', $orgId ? [$orgId] : [])
            ->value('id');
    }

    /**
     * Sum the master fee_structures amount for the fee heads implied by the
     * selected papers (EF always, +PF if any selected paper is Practical),
     * for (program, academic_year, semester_no, admission_type=back_paper).
     * Adds the back_paper_schedules late fee when the schedule's window has
     * closed. Never a hardcoded per-paper amount — entirely master-driven.
     */
    private function computeBackPaperFee(object $sa, \Illuminate\Support\Collection $paperRows): array
    {
        $feeHeadIds = $paperRows->pluck('fee_head_id')->filter()->unique()->values()->all();

        $structures = DB::table('fee_structures')
            ->where('program_id', $sa->program_id)
            ->where('academic_year', $sa->academic_year)
            ->where('semester_no', (int) $sa->semester_no)
            ->where('admission_type', 'back_paper')
            ->where('is_active', true)
            ->whereIn('fee_head_id', $feeHeadIds ?: [0])
            ->get();

        $base = (float) $structures->sum('amount');

        $late = 0.0;
        $schedule = DB::table('back_paper_schedules')
            ->where('program_id', $sa->program_id)
            ->where('semester', (string) $sa->semester_no)
            ->where('session_year', $sa->academic_year)
            ->first();
        if ($schedule && $schedule->late_fee_applicable && $schedule->end_on && now()->gt($schedule->end_on)) {
            $late = (float) $schedule->late_fee;
        }

        $breakdown = $structures->map(fn($s) => [
            'fee_head_id' => $s->fee_head_id,
            'amount'      => (float) $s->amount,
        ])->values()->all();

        return [
            'fee_head_ids' => $feeHeadIds,
            'base_amount'  => $base,
            'late_fee'     => $late,
            'total'        => $base + $late,
            'breakdown'    => $breakdown,
            'missing_structure' => $base <= 0 && !empty($feeHeadIds),
        ];
    }

    /**
     * Education fee for a fresh / semester_upgrade / lateral application —
     * sums every active fee_structures row for this program + academic_year
     * + admission_type ('regular' for fresh/upgrade/lateral) whose
     * semester_no is either 0 (applies to all semesters) or matches the
     * application's own semester_no. fee_structures is keyed by program +
     * semester + fee_head, not by individual subject, so "based on selected
     * subjects" is expressed as: which subjects the student picked decides
     * which program/semester combination they're being charged for — there
     * is no per-subject amount in this schema to add/remove line by line.
     */
    private function computeApplicationFee(object $sa): array
    {
        $admissionType = 'regular'; // fresh, semester_upgrade and lateral all price off the 'regular' fee_structures rows

        $structures = DB::table('fee_structures')
            ->where('program_id', $sa->program_id)
            ->where('academic_year', $sa->academic_year)
            ->whereIn('semester_no', array_unique([0, (int) $sa->semester_no]))
            ->where('admission_type', $admissionType)
            ->where('is_active', true)
            ->get();

        $base = (float) $structures->sum('amount');

        $breakdown = $structures->map(fn ($s) => [
            'fee_head_id' => $s->fee_head_id,
            'amount'      => (float) $s->amount,
        ])->values()->all();

        return [
            'base_amount'        => $base,
            'total'              => $base,
            'breakdown'          => $breakdown,
            'missing_structure'  => $base <= 0,
        ];
    }

    /** Shared ownership check for the student-side education-fee pay endpoints. */
    private function ownedStudentApp(Request $req, $id)
    {
        $student = DB::table('students')->where('user_id', $req->user()->id)->first();
        if (!$student) return response()->json(['message' => 'Student profile not found.'], 404);

        $sa = DB::table('student_applications')
            ->where('id', $id)
            ->where('student_id', $student->id)
            ->whereNull('deleted_at')
            ->first();

        if (!$sa) return response()->json(['message' => 'Application not found.'], 404);

        return $sa;
    }

    /** POST /college/applications/{id}/pay/initiate (office) */
    public function applicationPayInitiate(Request $req, $id)
    {
        $sa = DB::table('student_applications')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$sa) return response()->json(['message' => 'Application not found.'], 404);

        return $this->doApplicationPayInitiate($sa);
    }

    /** POST /student/applications/{id}/pay/initiate (student) */
    public function studentApplicationPayInitiate(Request $req, $id)
    {
        $sa = $this->ownedStudentApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse) return $sa;

        return $this->doApplicationPayInitiate($sa);
    }

    private function doApplicationPayInitiate(object $sa)
    {
        if ($sa->application_type === 'back_paper') {
            return response()->json(['message' => 'Use the back-paper payment endpoint for this application.'], 422);
        }
        if ($sa->fee_paid) {
            return response()->json(['message' => 'Education fee already paid.'], 409);
        }
        if (!in_array($sa->status, ['submitted', 'under_review'], true)) {
            return response()->json(['message' => 'Submit the application before paying the education fee.'], 422);
        }
        if (empty($sa->selected_subjects)) {
            return response()->json(['message' => 'Select subjects before paying the education fee.'], 422);
        }

        $fee = $this->computeApplicationFee($sa);
        if ($fee['missing_structure']) {
            return response()->json(['message' => 'No fee structure configured for this program/session yet. Contact the office.'], 422);
        }

        $rupees = (int) round($fee['total']);
        $amount = $rupees * 100; // paise

        $key    = config('services.razorpay.key');
        $secret = config('services.razorpay.secret');
        if (!$key || !$secret) {
            return response()->json(['message' => 'Payment gateway not configured. Contact the office.'], 503);
        }

        $student = DB::table('students')->where('id', $sa->student_id)->first();
        $name = $student ? trim(implode(' ', array_filter([$student->first_name ?? null, $student->middle_name ?? null, $student->last_name ?? null]))) : '';

        try {
            $resp = Http::withBasicAuth($key, $secret)
                ->asJson()
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount'          => $amount,
                    'currency'        => 'INR',
                    'receipt'         => 'ADM_' . $sa->application_no,
                    'payment_capture' => 1,
                    'notes'           => [
                        'application_id' => (string) $sa->id,
                        'application_no' => $sa->application_no,
                        'name'           => $name,
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::error('Razorpay order request failed (application fee): ' . $e->getMessage());
            return response()->json(['message' => 'Could not reach the payment gateway.'], 502);
        }

        if ($resp->failed()) {
            Log::error('Razorpay order error (application fee): ' . $resp->body());
            return response()->json(['message' => 'Could not create the payment order.'], 502);
        }

        $order = $resp->json();

        DB::table('student_applications')->where('id', $sa->id)->update([
            'razorpay_order_id' => $order['id'],
            'fee_amount'        => $fee['total'],
            'updated_at'        => now(),
        ]);

        return response()->json([
            'order_id'       => $order['id'],
            'amount'         => $amount,
            'amount_rupees'  => $rupees,
            'currency'       => 'INR',
            'key'            => $key,
            'name'           => $name,
            'email'          => $student->email ?? '',
            'mobile'         => $student->mobile ?? '',
            'application_id' => $sa->id,
        ]);
    }

    /** POST /college/applications/{id}/pay/verify (office) */
    public function applicationPayVerify(Request $req, $id)
    {
        $sa = DB::table('student_applications')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$sa) return response()->json(['message' => 'Application not found.'], 404);

        return $this->doApplicationPayVerify($sa, $req);
    }

    /** POST /student/applications/{id}/pay/verify (student) */
    public function studentApplicationPayVerify(Request $req, $id)
    {
        $sa = $this->ownedStudentApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse) return $sa;

        return $this->doApplicationPayVerify($sa, $req);
    }

    private function doApplicationPayVerify(object $sa, Request $req)
    {
        $req->validate([
            'razorpay_order_id'   => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature'  => 'required|string',
        ]);

        $secret   = config('services.razorpay.secret');
        $expected = hash_hmac('sha256', $req->razorpay_order_id . '|' . $req->razorpay_payment_id, (string) $secret);

        if (!$secret || !hash_equals($expected, $req->razorpay_signature)) {
            return response()->json(['message' => 'Payment could not be verified.'], 422);
        }

        $fee = $this->computeApplicationFee($sa);
        $isSelfFinance = (bool) DB::table('programs')->where('id', $sa->program_id)->value('is_self_finance');

        $feeHeadNames = DB::table('fee_heads')->whereIn('id', collect($fee['breakdown'])->pluck('fee_head_id')->filter()->all() ?: [0])->pluck('name', 'id');
        $breakdown = collect($fee['breakdown'])->map(fn ($b) => [
            'fee_head_id'   => $b['fee_head_id'],
            'fee_head_name' => $feeHeadNames[$b['fee_head_id']] ?? 'Fee',
            'amount'        => $b['amount'],
        ])->values()->all();

        $receipt = DB::transaction(fn () => FeeReceipt::create([
            'organization_id' => $sa->organization_id,
            'student_id'      => $sa->student_id,
            'admission_id'    => null, // admissions row doesn't exist until college approves — linked later
            'academic_year'   => $sa->academic_year,
            'semester_no'     => $sa->semester_no,
            'receipt_type'    => 'regular_admission',
            'receipt_no'      => FeeReceipt::feeReceiptNo($sa->academic_year, 'regular_admission', $isSelfFinance),
            'receipt_date'    => now()->toDateString(),
            'total_amount'    => $fee['base_amount'],
            'late_fine'       => 0,
            'concession'      => 0,
            'net_amount'      => $fee['total'],
            'payment_mode'    => 'online',
            'transaction_id'  => $req->razorpay_payment_id,
            'fee_breakdown'   => $breakdown,
            'generated_by'    => $req->user()->id,
            'status'          => 'active',
        ]));

        GenerateFeeReceiptPdf::dispatch($receipt->id);

        DB::table('student_applications')->where('id', $sa->id)->update([
            'fee_paid'       => true,
            'paid_at'        => now(),
            'payment_ref'    => $req->razorpay_payment_id,
            'fee_receipt_id' => $receipt->id,
            'status'         => 'under_review', // fee paid — now awaiting college's accept/reject decision
            'updated_at'     => now(),
        ]);

        return response()->json([
            'message'        => 'Education fee paid successfully. Your application is now under review by the college.',
            'application_id' => $sa->id,
            'receipt_no'     => $receipt->receipt_no,
            'fee_receipt_id' => $receipt->id,
        ]);
    }

    /** POST /college/applications/{id}/pay/failed (office) */
    public function applicationPayFailed($id)
    {
        Log::info("Education fee payment reported failed/abandoned for application {$id}.");
        return response()->json(['message' => 'Payment marked as failed. You can retry.']);
    }

    /** POST /student/applications/{id}/pay/failed (student) */
    public function studentApplicationPayFailed(Request $req, $id)
    {
        Log::info("Education fee payment reported failed/abandoned for application {$id} by student {$req->user()->id}.");
        return response()->json(['message' => 'Payment marked as failed. You can retry.']);
    }

    // =========================================================================
    // BACK PAPER — save selection, pay (Razorpay), print
    // Core (_prefixed) methods take an already-loaded/authorized $sa row and
    // are shared by the office endpoints (no ownership check) and the
    // student endpoints (ownership-checked wrappers below).
    // =========================================================================

    /** PUT /college/applications/{id}/back-paper (office) */
    public function saveBackPaperSelection(Request $req, $id)
    {
        $sa = DB::table('student_applications')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$sa) return response()->json(['message' => 'Application not found.'], 404);

        return $this->doSaveBackPaperSelection($sa, $req);
    }

    /** PUT /student/applications/{id}/back-paper (student, ownership-checked) */
    public function studentSaveBackPaperSelection(Request $req, $id)
    {
        $sa = $this->ownedStudentBackPaperApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse) return $sa;

        return $this->doSaveBackPaperSelection($sa, $req);
    }

    private function doSaveBackPaperSelection(object $sa, Request $req)
    {
        if ($sa->application_type !== 'back_paper') {
            return response()->json(['message' => 'Not a back paper application.'], 422);
        }
        if ($sa->fee_paid) {
            return response()->json(['message' => 'Fee already paid — papers can no longer be changed.'], 422);
        }

        $v = Validator::make($req->all(), [
            'paper_ids'   => 'required|array|min:1',
            'paper_ids.*' => 'integer|exists:subjects,id',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $paperRows = DB::table('subjects')->whereIn('id', $req->paper_ids)->get();
        $orgId = $req->user()->organization_id ?? DB::table('student_applications')
            ->where('id', $sa->id)->value('organization_id');
        $efId  = $this->feeHeadId('EF', $orgId);
        $pfId  = $this->feeHeadId('PF', $orgId);
        $paperRows = $paperRows->map(function ($p) use ($efId, $pfId) {
            $p->fee_head_id = $p->type === 'practical' ? $pfId : $efId;
            return $p;
        });

        $fee = $this->computeBackPaperFee($sa, $paperRows);

        $prog = json_decode($sa->form_progress ?? '{}', true);
        $prog['part7'] = true;

        DB::table('student_applications')->where('id', $sa->id)->update([
            'part_7'         => json_encode(['paper_ids' => array_values($req->paper_ids)]),
            'form_progress'  => json_encode($prog),
            'fee_amount'     => $fee['total'],
            'updated_at'     => now(),
        ]);

        return response()->json([
            'message' => 'Back paper selection saved.',
            'fee'     => $fee,
        ]);
    }

    /** POST /college/applications/{id}/back-paper/pay/initiate (office) */
    public function backPaperPayInitiate(Request $req, $id)
    {
        $sa = DB::table('student_applications')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$sa) return response()->json(['message' => 'Application not found.'], 404);

        return $this->doBackPaperPayInitiate($sa);
    }

    /** POST /student/applications/{id}/back-paper/pay/initiate (student) */
    public function studentBackPaperPayInitiate(Request $req, $id)
    {
        $sa = $this->ownedStudentBackPaperApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse) return $sa;

        return $this->doBackPaperPayInitiate($sa);
    }

    private function doBackPaperPayInitiate(object $sa)
    {
        if ($sa->fee_paid) {
            return response()->json(['message' => 'Fee already paid.'], 409);
        }
        if (!$sa->fee_amount || (float) $sa->fee_amount <= 0) {
            return response()->json(['message' => 'Select papers and save before paying.'], 422);
        }

        $rupees = (int) round((float) $sa->fee_amount);
        $amount = $rupees * 100; // paise

        $key    = config('services.razorpay.key');
        $secret = config('services.razorpay.secret');
        if (!$key || !$secret) {
            return response()->json(['message' => 'Payment gateway not configured. Contact the office.'], 503);
        }

        $student = DB::table('students')->where('id', $sa->student_id)->first();

        try {
            $resp = Http::withBasicAuth($key, $secret)
                ->asJson()
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount'          => $amount,
                    'currency'        => 'INR',
                    'receipt'         => 'BP_' . $sa->application_no,
                    'payment_capture' => 1,
                    'notes'           => [
                        'application_id' => (string) $sa->id,
                        'application_no' => $sa->application_no,
                        'name'           => $student->name ?? '',
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::error('Razorpay order request failed (back paper): ' . $e->getMessage());
            return response()->json(['message' => 'Could not reach the payment gateway.'], 502);
        }

        if ($resp->failed()) {
            Log::error('Razorpay order error (back paper): ' . $resp->body());
            return response()->json(['message' => 'Could not create the payment order.'], 502);
        }

        $order = $resp->json();

        DB::table('student_applications')->where('id', $sa->id)->update([
            'razorpay_order_id' => $order['id'],
            'updated_at'        => now(),
        ]);

        return response()->json([
            'order_id'       => $order['id'],
            'amount'         => $amount,
            'amount_rupees'  => $rupees,
            'currency'       => 'INR',
            'key'            => $key,
            'name'           => $student->name ?? '',
            'email'          => $student->email ?? '',
            'mobile'         => $student->mobile ?? '',
            'application_id' => $sa->id,
        ]);
    }

    /** POST /college/applications/{id}/back-paper/pay/verify (office) */
    public function backPaperPayVerify(Request $req, $id)
    {
        $sa = DB::table('student_applications')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$sa) return response()->json(['message' => 'Application not found.'], 404);

        return $this->doBackPaperPayVerify($sa, $req);
    }

    /** POST /student/applications/{id}/back-paper/pay/verify (student) */
    public function studentBackPaperPayVerify(Request $req, $id)
    {
        $sa = $this->ownedStudentBackPaperApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse) return $sa;

        return $this->doBackPaperPayVerify($sa, $req);
    }

    private function doBackPaperPayVerify(object $sa, Request $req)
    {
        $req->validate([
            'razorpay_order_id'   => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature'  => 'required|string',
        ]);

        $secret   = config('services.razorpay.secret');
        $expected = hash_hmac('sha256', $req->razorpay_order_id . '|' . $req->razorpay_payment_id, (string) $secret);

        if (!$secret || !hash_equals($expected, $req->razorpay_signature)) {
            return response()->json(['message' => 'Payment could not be verified.'], 422);
        }

        $admission = DB::table('admissions')
            ->where('student_id', $sa->student_id)
            ->where('program_id', $sa->program_id)
            ->orderByDesc('id')
            ->first();

        if (!$admission) {
            return response()->json(['message' => 'No admission record found for this student/program — cannot generate a fee receipt.'], 422);
        }

        $part7      = json_decode($sa->part_7 ?? '{}', true);
        $paperIds   = $part7['paper_ids'] ?? [];
        $paperRows  = DB::table('subjects')->whereIn('id', $paperIds)->get();
        $orgId      = $sa->organization_id;
        $efId       = $this->feeHeadId('EF', $orgId);
        $pfId       = $this->feeHeadId('PF', $orgId);
        $paperRows  = $paperRows->map(function ($p) use ($efId, $pfId) {
            $p->fee_head_id = $p->type === 'practical' ? $pfId : $efId;
            return $p;
        });
        $fee = $this->computeBackPaperFee($sa, $paperRows);

        $feeHeadNames = DB::table('fee_heads')->whereIn('id', $fee['fee_head_ids'] ?: [0])->pluck('name', 'id');
        $breakdown = collect($fee['breakdown'])->map(fn($b) => [
            'fee_head_id'   => $b['fee_head_id'],
            'fee_head_name' => $feeHeadNames[$b['fee_head_id']] ?? 'Fee',
            'amount'        => $b['amount'],
        ])->values()->all();
        if ($fee['late_fee'] > 0) {
            $breakdown[] = ['fee_head_id' => null, 'fee_head_name' => 'Late Fee', 'amount' => $fee['late_fee']];
        }

        $isSelfFinance = (bool) DB::table('programs')->where('id', $sa->program_id)->value('is_self_finance');

        $receipt = DB::transaction(fn () => FeeReceipt::create([
            'organization_id' => $orgId,
            'student_id'      => $sa->student_id,
            'admission_id'    => $admission->id,
            'academic_year'   => $sa->academic_year,
            'semester_no'     => $sa->semester_no,
            'receipt_type'    => 'back_paper',
            'receipt_no'      => FeeReceipt::feeReceiptNo($sa->academic_year, 'back_paper', $isSelfFinance),
            'receipt_date'    => now()->toDateString(),
            'total_amount'    => $fee['base_amount'],
            'late_fine'       => $fee['late_fee'],
            'concession'      => 0,
            'net_amount'      => $fee['total'],
            'payment_mode'    => 'online',
            'transaction_id'  => $req->razorpay_payment_id,
            'fee_breakdown'   => $breakdown,
            'generated_by'    => $req->user()->id,
            'status'          => 'active',
        ]));

        GenerateFeeReceiptPdf::dispatch($receipt->id);

        DB::table('student_applications')->where('id', $sa->id)->update([
            'fee_paid'       => true,
            'paid_at'        => now(),
            'payment_ref'    => $req->razorpay_payment_id,
            'fee_receipt_id' => $receipt->id,
            'status'         => 'submitted',
            'updated_at'     => now(),
        ]);

        return response()->json([
            'message'        => 'Payment successful.',
            'application_id' => $sa->id,
            'receipt_no'     => $receipt->receipt_no,
            'fee_receipt_id' => $receipt->id,
        ]);
    }

    /** POST /college/applications/{id}/back-paper/pay/failed (office) */
    public function backPaperPayFailed($id)
    {
        Log::info("Back paper payment reported failed/abandoned for application {$id}.");
        return response()->json(['message' => 'Payment marked as failed. You can retry.']);
    }

    /** POST /student/applications/{id}/back-paper/pay/failed (student) */
    public function studentBackPaperPayFailed(Request $req, $id)
    {
        $sa = $this->ownedStudentBackPaperApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse) return $sa;

        return $this->backPaperPayFailed($id);
    }

    /** GET /college/applications/{id}/back-paper/print (office) */
    public function printBackPaperForm($id)
    {
        $sa = DB::table('student_applications')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$sa) return response()->json(['message' => 'Application not found.'], 404);

        return $this->doPrintBackPaperForm($sa);
    }

    /** GET /student/applications/{id}/back-paper/print (student) */
    public function studentPrintBackPaperForm(Request $req, $id)
    {
        $sa = $this->ownedStudentBackPaperApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse) return $sa;

        return $this->doPrintBackPaperForm($sa);
    }

    private function doPrintBackPaperForm(object $sa)
    {
        if (!$sa->fee_paid) {
            return response()->json(['message' => 'Print is available only after the fee is paid.'], 422);
        }

        $student = DB::table('students')->where('id', $sa->student_id)->first();
        $program = DB::table('programs')->where('id', $sa->program_id)->first();
        $org     = DB::table('organizations')->where('id', $sa->organization_id)->first();
        $admission = DB::table('admissions')
            ->where('student_id', $sa->student_id)->where('program_id', $sa->program_id)
            ->orderByDesc('id')->first();

        $part7    = json_decode($sa->part_7 ?? '{}', true);
        $paperIds = $part7['paper_ids'] ?? [];
        $papers = DB::table('subjects')
            ->whereIn('id', $paperIds)
            ->orderBy('name')
            ->get();

        try {
            $pdf = Pdf::loadView('pdf.back-paper-form', compact('sa', 'student', 'program', 'org', 'admission', 'papers'))->setPaper('a4');
            return response()->streamDownload(
                fn () => print($pdf->output()),
                "BackPaper-Application-{$sa->application_no}.pdf",
                ['Content-Type' => 'application/pdf']
            );
        } catch (\Throwable $e) {
            Log::error("Back paper form PDF failed for {$sa->id}: " . $e->getMessage());
            return response()->json(['message' => 'Could not generate the back paper form PDF.'], 500);
        }
    }

    /**
     * Loads a back_paper student_applications row for the authenticated
     * student, or returns a JSON error response. Mirrors the ownership
     * check every other student-side endpoint in this controller performs.
     */
    private function ownedStudentBackPaperApp(Request $req, $id)
    {
        $student = DB::table('students')->where('user_id', $req->user()->id)->first();
        if (!$student) return response()->json(['message' => 'Student profile not found.'], 404);

        $sa = DB::table('student_applications')
            ->where('id', $id)
            ->where('student_id', $student->id)
            ->where('application_type', 'back_paper')
            ->whereNull('deleted_at')
            ->first();

        if (!$sa) return response()->json(['message' => 'Back paper application not found.'], 404);

        return $sa;
    }

    /**
     * GET /student/applications/back-paper/papers?semester_no=
     * Preview of eligible papers (+ their master fee head) for the student's
     * own current admission, used before a back_paper application row even
     * exists yet (semester picker on the "start a back paper" screen).
     */
    public function studentBackPaperPapers(Request $req)
    {
        $v = Validator::make($req->all(), ['semester_no' => 'required|integer']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $student = DB::table('students')->where('user_id', $req->user()->id)->first();
        if (!$student) return response()->json(['message' => 'Student profile not found.'], 404);

        $admission = DB::table('admissions')
            ->where('student_id', $student->id)
            ->orderByDesc('id')
            ->first();
        if (!$admission) return response()->json(['message' => 'No admission record found.'], 404);

        $fake = new Request(array_merge($req->all(), ['admission_id' => $admission->id]));
        $fake->setUserResolver($req->getUserResolver());

        return $this->backPaperPapers($fake);
    }

    /**
     * GET /student/applications/{id}/back-paper/receipt
     * Students can't hit the office-only /fee-receipts/{id}/download route
     * (portal:college,super_admin), so this ownership-checked wrapper serves
     * the same generated PDF for their own back-paper fee receipt.
     */
    public function studentDownloadBackPaperReceipt(Request $req, $id)
    {
        $sa = $this->ownedStudentBackPaperApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse) return $sa;

        if (!$sa->fee_receipt_id) {
            return response()->json(['message' => 'No fee receipt has been generated yet.'], 404);
        }

        $receipt = DB::table('fee_receipts')->where('id', $sa->fee_receipt_id)->first();
        if (!$receipt) return response()->json(['message' => 'Fee receipt not found.'], 404);

        if (!$receipt->pdf_path || !Storage::exists($receipt->pdf_path)) {
            \App\Jobs\GenerateFeeReceiptPdf::dispatchSync($receipt->id);
            $receipt = DB::table('fee_receipts')->where('id', $receipt->id)->first();
        }

        return Storage::download($receipt->pdf_path, "FeeReceipt-{$receipt->receipt_no}.pdf");
    }

    // =========================================================================
    // STUDENT SIDE
    // =========================================================================

    /**
     * GET /student/applications
     */
    public function myApplications(Request $req)
    {
        $student = DB::table('students')->where('user_id', $req->user()->id)->first();
        if (!$student)
            return response()->json(['data' => []]);

        $typeFilter = $req->input('application_type') ?? $req->input('type');

        $apps = DB::table('student_applications as sa')
            ->join('programs as p', 'p.id', 'sa.program_id')
            ->where('sa.student_id', $student->id)
            ->whereNull('sa.deleted_at')
            ->when($typeFilter, fn($q) => $q->where('sa.application_type', $typeFilter))
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

    /**
     * POST /student/applications
     */
    public function store(Request $req)
    {
        // Accept 'type' as alias for 'application_type'
        if ($req->has('type') && !$req->has('application_type')) {
            $req->merge(['application_type' => $req->type]);
        }

        $req->validate([
            'program_id' => 'required|exists:programs,id',
            'academic_year' => 'required|string|max:10',
            'application_type' => 'required|in:fresh,back_paper,semester_upgrade,lateral',
            'semester_no' => 'nullable|integer|min:1|max:12',
        ]);

        $student = DB::table('students')->where('user_id', $req->user()->id)->first();
        if (!$student)
            return response()->json(['message' => 'Student profile not found.'], 404);

        $existing = DB::table('student_applications')
            ->where('student_id', $student->id)
            ->where('program_id', $req->program_id)
            ->where('academic_year', $req->academic_year)
            ->where('application_type', $req->application_type)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'You already have an active application for this program and year.',
                'id' => $existing->id,
            ], 409);
        }

        $seq = DB::table('student_applications')->count() + 1;
        $appNo = 'SA-' . date('Y') . '-' . str_pad($seq, 6, '0', STR_PAD_LEFT);

        $id = DB::table('student_applications')->insertGetId([
            'organization_id' => $req->user()->organization_id,
            'student_id' => $student->id,
            'program_id' => $req->program_id,
            'academic_year' => $req->academic_year,
            'application_type' => $req->application_type,
            'semester_no' => $req->semester_no,
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

    /**
     * PUT /student/applications/{id}/part/{part}
     * Student saves one part. Blocked if submitted or approved.
     */
    public function updatePart(Request $req, $id, $part)
    {
        $partNo = (int) $part;
        if ($partNo < 1 || $partNo > 8) {
            return response()->json(['message' => "Invalid part {$part}. Must be 1-8."], 422);
        }

        $student = DB::table('students')->where('user_id', $req->user()->id)->first();
        if (!$student)
            return response()->json(['message' => 'Student profile not found.'], 404);

        $app = DB::table('student_applications')
            ->where('id', $id)
            ->where('student_id', $student->id)
            ->whereNull('deleted_at')
            ->first();

        if (!$app)
            return response()->json(['message' => 'Application not found.'], 404);

        if (in_array($app->status, ['submitted', 'approved'])) {
            return response()->json(['message' => 'Cannot edit a submitted or approved application.'], 422);
        }

        $col = 'part_' . $partNo;
        $key = 'part' . $partNo;
        $prog = json_decode($app->form_progress ?? '{}', true);
        $prog[$key] = true;

        $clean = $this->stripLockedIdentity($req->all(), $req->user()->id);
        // Backstop: uppercase free text server-side too, not just in the
        // browser, so the saved data is normalized regardless of client.
        $clean = TextNormalizer::upper($clean);

        DB::table('student_applications')->where('id', $id)->update([
            $col => json_encode($clean),
            'form_progress' => json_encode($prog),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => "Part {$partNo} saved.",
            'form_progress' => $prog,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // CONTACT (MOBILE / EMAIL) CHANGE — OTP-gated
    // ══════════════════════════════════════════════════════════════
    /**
     * mobile/email stay in $lockedIdentityFields (see LocksStudentIdentity)
     * so updatePart() above always strips them out of a raw part-save — this
     * is the only path a student can actually change either one from the
     * application form. Same real OTP mechanism StudentRegistrationController
     * already uses for registration (Cache-backed, SmsService/Mail::raw), not
     * the AmendmentController stub that never actually checks the code.
     * Writes straight to `students.mobile`/`students.email` on verify, not
     * into part_2 JSON, so there's one source of truth for contact info.
     */
    private function studentForContactChange(Request $req, $id): ?object
    {
        $student = DB::table('students')->where('user_id', $req->user()->id)->first();
        if (!$student) return null;

        $app = DB::table('student_applications')
            ->where('id', $id)
            ->where('student_id', $student->id)
            ->whereNull('deleted_at')
            ->first();
        if (!$app) return null;

        return $student;
    }

    public function sendContactMobileOtp(Request $req, $id)
    {
        $student = $this->studentForContactChange($req, $id);
        if (!$student)
            return response()->json(['message' => 'Application not found.'], 404);

        $v = Validator::make($req->all(), ['new_mobile' => 'required|digits:10']);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        if ($req->new_mobile === $student->mobile) {
            return response()->json(['message' => 'That is already your registered mobile number.'], 422);
        }

        $otp = rand(100000, 999999);
        Cache::put("app_mobile_otp_{$student->id}", $otp, now()->addMinutes(10));
        Cache::put("app_mobile_otp_value_{$student->id}", $req->new_mobile, now()->addMinutes(10));

        $sent = app(\App\Services\SmsService::class)->sendOtp($req->new_mobile, $otp, null);
        if (!$sent) {
            Log::info("APPLICATION MOBILE OTP for student {$student->id}: {$otp}");
        }

        $masked   = substr($req->new_mobile, 0, 2) . 'XXXXXX' . substr($req->new_mobile, -2);
        $response = ['message' => "OTP sent to {$masked}."];
        if (config('app.debug')) $response['debug_otp'] = $otp; // only in local/dev

        return response()->json($response);
    }

    public function verifyContactMobileOtp(Request $req, $id)
    {
        $student = $this->studentForContactChange($req, $id);
        if (!$student)
            return response()->json(['message' => 'Application not found.'], 404);

        $v = Validator::make($req->all(), ['otp' => 'required|digits:6']);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $cached  = Cache::get("app_mobile_otp_{$student->id}");
        $pending = Cache::get("app_mobile_otp_value_{$student->id}");

        if (!$cached || !$pending || (string) $cached !== (string) $req->otp) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        DB::table('students')->where('id', $student->id)
            ->update(['mobile' => $pending, 'updated_at' => now()]);

        Cache::forget("app_mobile_otp_{$student->id}");
        Cache::forget("app_mobile_otp_value_{$student->id}");

        return response()->json(['message' => 'Mobile number updated.', 'mobile' => $pending]);
    }

    public function sendContactEmailOtp(Request $req, $id)
    {
        $student = $this->studentForContactChange($req, $id);
        if (!$student)
            return response()->json(['message' => 'Application not found.'], 404);

        $v = Validator::make($req->all(), ['new_email' => 'required|email']);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        if (strcasecmp($req->new_email, (string) $student->email) === 0) {
            return response()->json(['message' => 'That is already your registered email.'], 422);
        }

        $otp = rand(100000, 999999);
        Cache::put("app_email_otp_{$student->id}", $otp, now()->addMinutes(10));
        Cache::put("app_email_otp_value_{$student->id}", $req->new_email, now()->addMinutes(10));

        try {
            Mail::raw(
                "Your SDPG College email verification OTP is: {$otp}\n\nValid for 10 minutes. Do not share it with anyone.",
                fn ($m) => $m->to($req->new_email)->subject('SDPG College — Email Verification OTP')
            );
        } catch (\Throwable $e) {
            Log::error('Application email OTP send failed: ' . $e->getMessage());
            Log::info("APPLICATION EMAIL OTP for student {$student->id}: {$otp}");
        }

        $response = ['message' => 'OTP sent to your new email.'];
        if (config('app.debug')) $response['debug_otp'] = $otp;

        return response()->json($response);
    }

    public function verifyContactEmailOtp(Request $req, $id)
    {
        $student = $this->studentForContactChange($req, $id);
        if (!$student)
            return response()->json(['message' => 'Application not found.'], 404);

        $v = Validator::make($req->all(), ['otp' => 'required|digits:6']);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $cached  = Cache::get("app_email_otp_{$student->id}");
        $pending = Cache::get("app_email_otp_value_{$student->id}");

        if (!$cached || !$pending || (string) $cached !== (string) $req->otp) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        DB::table('students')->where('id', $student->id)
            ->update(['email' => $pending, 'updated_at' => now()]);

        Cache::forget("app_email_otp_{$student->id}");
        Cache::forget("app_email_otp_value_{$student->id}");

        return response()->json(['message' => 'Email updated.', 'email' => $pending]);
    }

    /**
     * POST /student/applications/{id}/submit
     */
    public function submit(Request $req, $id)
    {
        $req->validate(['declaration_accepted' => 'required|accepted']);

        $student = DB::table('students')->where('user_id', $req->user()->id)->first();
        if (!$student)
            return response()->json(['message' => 'Student profile not found.'], 404);

        $app = DB::table('student_applications')
            ->where('id', $id)
            ->where('student_id', $student->id)
            ->whereNull('deleted_at')
            ->first();

        if (!$app)
            return response()->json(['message' => 'Application not found.'], 404);
        if ($app->status !== 'draft') {
            return response()->json(['message' => "Application is already {$app->status}."], 422);
        }

        DB::table('student_applications')->where('id', $id)->update([
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

    /**
     * POST /student/applications/{id}/documents
     * Upload a document file. One file per document_type per application.
     */
    public function uploadStudentDocument(Request $req, $id)
    {
        $req->validate([
            'file' => 'required|file|max:2048|mimes:jpg,jpeg,png,pdf',
            'document_type' => 'required|string|max:60',
        ]);

        $student = DB::table('students')->where('user_id', $req->user()->id)->first();
        if (!$student)
            return response()->json(['message' => 'Student profile not found.'], 404);

        $app = DB::table('student_applications')
            ->where('id', $id)
            ->where('student_id', $student->id)
            ->whereNull('deleted_at')
            ->first();

        if (!$app)
            return response()->json(['message' => 'Application not found.'], 404);

        if (in_array($app->status, ['submitted', 'approved'])) {
            return response()->json(['message' => 'Cannot upload to a submitted application.'], 422);
        }

        $docType = $req->input('document_type');
        $path = $req->file('file')->store("student-applications/{$id}", 'public');

        DB::table('student_application_documents')->updateOrInsert(
            ['application_id' => $id, 'document_type' => $docType],
            [
                'path' => $path,
                'filename' => $req->file('file')->getClientOriginalName(),
                'status' => 'uploaded',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Document uploaded.',
            'url' => Storage::url($path),
            'filename' => $req->file('file')->getClientOriginalName(),
        ]);
    }

    /**
     * GET /student/programs
     * Programs available for student applications (new application form).
     */
    public function studentPrograms(Request $req)
    {
        // Current session (July cutoff, matches the frontend).
        $y = (int) date('Y');
        $session = (int) date('n') >= 7 ? "{$y}-" . ($y + 1) : ($y - 1) . "-{$y}";

        $user = $req->user();

        // Levels the student registered for in the CURRENT session.
        $regTypes = DB::table('direct_registrations')
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id);
                if (!empty($user->mobile)) $q->orWhere('mobile', $user->mobile);
            })
            ->where('session_year', $session)
            ->whereNull('deleted_at')
            ->pluck('reg_type')
            ->map(fn ($t) => strtoupper((string) $t))
            ->unique()
            ->all();

        if (empty($regTypes)) {
            return response()->json([
                'data'         => [],
                'session_year' => $session,
                'message'      => 'No registration found for the current session. Please register first.',
            ]);
        }

        // reg_type (UG|PG|BED) -> programs.level (UG|PG|BEd).
        $levelMap = ['UG' => 'UG', 'PG' => 'PG', 'BED' => 'BEd', 'BEED' => 'BEd'];
        $levels   = array_values(array_unique(array_map(fn ($t) => $levelMap[$t] ?? $t, $regTypes)));

        $programs = DB::table('programs')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereIn('level', $levels)
            ->select('id', 'name', 'short_name', 'level', 'duration_years')
            ->orderBy('level')->orderBy('name')
            ->get();

        return response()->json([
            'data'         => $programs,
            'session_year' => $session,
        ]);
    }

    /**
     * GET /student/applications/upgrade/self
     * Pre-fills the student upgrade form with current admission data.
     */
    public function upgradeSelf(Request $req)
    {
        $student = DB::table('students')->where('user_id', $req->user()->id)->first();
        if (!$student)
            return response()->json(['message' => 'Student profile not found.'], 404);

        $row = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->where('s.id', $student->id)
            ->select(
                'a.*',
                's.mobile',
                's.gender',
                's.date_of_birth',
                's.category',
                's.aadhar_no',
                's.abc_id',
                's.ddurn',
                'p.short_name as class',
                'p.full_name',
                'p.level'
            )
            ->latest('a.id')
            ->first();

        if (!$row)
            return response()->json(['message' => 'No enrolled record found.'], 404);

        $reg = DB::table('direct_registrations')
            ->where(function ($q) use ($student) {
                $q->where('user_id', $student->user_id);
                if (!empty($student->mobile)) {
                    $q->orWhereRaw('RIGHT(mobile, 10) = RIGHT(?, 10)', [$student->mobile]);
                }
            })
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $row->name          = $reg->name ?? null;
        $row->father_name   = $reg->father_name ?? null;
        $row->mother_name   = $reg->mother_name ?? null;
        $row->dob           = $row->date_of_birth ?? ($reg->dob ?? null);

        return response()->json($row);
    }
}
