<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Concerns\LocksStudentIdentity;
use App\Jobs\GenerateFeeReceiptPdf;
use App\Models\FeeReceipt;
use App\Services\AdmissionNumberService;
use App\Services\NotificationService;
use App\Support\TextNormalizer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
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
            $sa->student->dob = $sa->student->date_of_birth ?? null;
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

        $sa->registration = $sa->direct_registration_id
            ? DB::table('direct_registrations')->where('id', $sa->direct_registration_id)->whereNull('deleted_at')->first()
            : null;
        $sa->has_registration_snapshot = (bool) $sa->registration;

        $sa->approved_by_name = $sa->approved_by
            ? DB::table('users')->where('id', $sa->approved_by)->value('name')
            : null;
        $latestDecision = DB::table('rejected_applications')
            ->where('student_application_id', $sa->id)
            ->orderByDesc('id')
            ->first();
        $sa->reviewed_by_name = ($latestDecision && $latestDecision->decided_by)
            ? DB::table('users')->where('id', $latestDecision->decided_by)->value('name')
            : null;

        // Full hold/reject decision, for RejectHoldDialog's read-only view
        // and Application Release Details — mirrors the shape
        // holdRejectSearch() returns.
        $sa->ref_no = $latestDecision->ref_no ?? null;
        $sa->decision = $latestDecision->decision ?? null;
        $sa->decision_status = $latestDecision->status ?? null;
        $sa->hold_type = $latestDecision->hold_type ?? null;
        $sa->reason = $latestDecision->reason ?? null;
        $sa->objections = $latestDecision && $latestDecision->objections
            ? json_decode($latestDecision->objections, true)
            : [];
        $sa->submitted_by = $latestDecision->submitted_by ?? null;
        $sa->submitted_at = $latestDecision->submitted_at ?? null;
        $sa->release_due_to = $latestDecision->release_due_to ?? null;
        $sa->release_remarks = $latestDecision->release_remarks ?? null;
        $sa->released_at = $latestDecision->released_at ?? null;
        $sa->released_by_name = ($latestDecision && $latestDecision->released_by)
            ? DB::table('users')->where('id', $latestDecision->released_by)->value('name')
            : null;

        // Fee preview — computeApplicationFee() is read-only (no order
        // created)
        if ($sa->application_type !== 'back_paper') {
            $feePreview = $this->computeApplicationFee($sa);
            $sa->computed_fee_amount = $feePreview['missing_structure'] ? null : $feePreview['total'];
        } else {
            $sa->computed_fee_amount = null;
        }

        $sa->documents = DB::table('student_application_documents')
            ->where('application_id', $sa->id)
            ->get()
            ->map(function ($doc) {

                try {
                    $doc->url = Storage::disk('supabase')->exists($doc->path)
                        ? Storage::disk('supabase')->url($doc->path)
                        : Storage::disk('public')->url($doc->path);
                } catch (\Throwable $e) {
                    $doc->url = Storage::disk('public')->url($doc->path);
                }
                return $doc;
            });

        $photoDoc = $sa->documents->firstWhere('document_type', 'photo');
        $sigDoc = $sa->documents->firstWhere('document_type', 'signature');
        $sa->photo_url = $photoDoc->url ?? null;
        $sa->signature_url = $sigDoc->url ?? null;
        $requiredDocs = $this->buildRequiredDocuments($sa);
        $sa->required_documents_complete = collect($requiredDocs)
            ->where('importance', 'important')
            ->every(fn($d) => $d['uploaded']);

        // Part 5 (Bank Detail) green-check — a Save Draft on this step can
        // legitimately write an all-empty part_5 (e.g. the "Have you a bank
        // account?" radio defaults to '' before the applicant ever touches
        // it, which hides every required field and skips their validation
        // entirely — see Part5Bank.tsx). So "part_5 exists" is NOT the same
        // as "bank detail is actually complete": only count it done once the
        // applicant explicitly said they have no account, or said they do
        // and filled in every field Part5Bank itself requires in that case.
        $p5 = is_array($sa->part_5 ?? null) ? $sa->part_5 : [];
        $sa->bank_detail_complete = ($p5['has_bank_account'] ?? null) === 'no'
            || (($p5['has_bank_account'] ?? null) === 'yes'
                && trim((string) ($p5['name_in_account'] ?? '')) !== ''
                && trim((string) ($p5['bank_name'] ?? '')) !== ''
                && trim((string) ($p5['bank_branch'] ?? '')) !== ''
                && trim((string) ($p5['bank_account_no'] ?? '')) !== ''
                && trim((string) ($p5['bank_ifsc'] ?? '')) !== ''
                && trim((string) ($p5['branch_address'] ?? '')) !== '');

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
        $latestAdmission = DB::table('admissions')
            ->select('student_id', 'program_id', DB::raw('MAX(id) as admission_id'))
            ->groupBy('student_id', 'program_id');

        $latestReg = DB::table('direct_registrations')
            ->select('user_id', DB::raw('MAX(id) as reg_id'))
            ->whereNull('deleted_at')
            ->groupBy('user_id');

        // students is now a LEFT join — was an inner join, which silently
        // dropped every 'regular' application from this list.
        // Identity fields (name/mobile/gender/dob/category/aadhar/abc_id)
        // now COALESCE across three sources, in priority order:
        //   1. s.*      — the students row, when one exists (authoritative
        //                 and current for a returning applicant).
        //   2. dr.*      — the registration linked via the student's own
        //                 user_id (works for a returning applicant even
        //                 before/without a students row in edge cases).
        //   3. drc.*     — the registration linked directly via
        //                 sa.direct_registration_id (the ONLY one of the
        //                 three that works for a fresh application, since
        //                 fresh apps have no student_id yet, so #1 and #2
        //                 both resolve to nothing for them).
        $q = DB::table('student_applications as sa')
            ->leftJoin('students as s', 's.id', 'sa.student_id')
            ->join('programs as p', 'p.id', 'sa.program_id')
            ->leftJoinSub($latestAdmission, 'la', function ($j) {
                $j->on('la.student_id', 'sa.student_id')->on('la.program_id', 'sa.program_id');
            })
            ->leftJoin('admissions as adm', 'adm.id', 'la.admission_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->leftJoin('direct_registrations as drc', 'drc.id', 'sa.direct_registration_id')
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
                DB::raw('COALESCE(dr.name, drc.name) as name'),
                DB::raw('COALESCE(dr.father_name, drc.father_name) as father_name'),
                DB::raw('COALESCE(dr.mother_name, drc.mother_name) as mother_name'),
                DB::raw('COALESCE(s.mobile, dr.mobile, drc.mobile) as mobile'),
                DB::raw('COALESCE(s.gender, dr.gender, drc.gender) as gender'),
                DB::raw('COALESCE(s.date_of_birth::text, dr.dob, drc.dob) as dob'),
                DB::raw('COALESCE(s.category, dr.category, drc.category) as category'),
                DB::raw('COALESCE(s.aadhar_no, drc.aadhar_no) as aadhar_no'),
                DB::raw('COALESCE(s.abc_id, drc.abc_id) as abc_id'),
                'p.short_name as class',
                'p.full_name',
                'p.level',
                'adm.payment_status as edu_payment_status',
                'drc.unique_code as code'
            )
            ->when($req->academic_year, fn($q) => $q->where('sa.academic_year', $req->academic_year))
            ->when($req->program_id, fn($q) => $q->where('sa.program_id', $req->program_id))
            ->when($req->application_type ?? $req->type, fn($q, $v) => $q->where('sa.application_type', $v))
            ->when($req->status, fn($q) => $q->where('sa.status', strtolower(str_replace(' ', '_', $req->status))))
            // exam_mode is only ever populated on part_1 for the
            // office-created semester_upgrade flow (storeOffice()) right now
            ->when($req->exam_mode, fn($q, $v) => $q->whereRaw("sa.part_1->>'exam_mode' = ?", [$v]))
            ->when($req->search, fn($q) => $q->where(function ($q2) use ($req) {
                // Extended to drc (was dr/s only) so a fresh application —
                // no students row, no dr match via user_id — is still
                // searchable by its own direct_registrations data.
                $q2->where('dr.name', 'ilike', "%{$req->search}%")
                    ->orWhere('drc.name', 'ilike', "%{$req->search}%")
                    ->orWhere('sa.application_no', 'ilike', "%{$req->search}%")
                    ->orWhere('s.mobile', 'ilike', "%{$req->search}%")
                    ->orWhere('drc.mobile', 'ilike', "%{$req->search}%")
                    ->orWhere('s.aadhar_no', 'ilike', "%{$req->search}%")
                    ->orWhere('drc.aadhar_no', 'ilike', "%{$req->search}%")
                    ->orWhere('s.abc_id', 'ilike', "%{$req->search}%")
                    ->orWhere('drc.abc_id', 'ilike', "%{$req->search}%");
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
            $row->status_label = str_replace('_', ' ', ucwords($row->status, '_'));
            // "Completed" = final-submitted (or further along the pipeline).
            $row->completed = !in_array($row->status, ['draft', 'cancelled'], true);
            // "Paid" — back paper applications track their own exam fee
            // (sa.fee_paid); every other type is gated on the education fee
            // paid against the linked admission record.
            $row->paid = $row->application_type === 'back_paper'
                ? (bool) $row->fee_paid
                : $row->edu_payment_status === 'paid';

            if ($row->application_type === 'semester_upgrade') {
                $row->previous_semester_no = $row->semester_no ? $row->semester_no - 1 : null;
                $row->upgrade_status = !$row->paid
                    ? 'Pending Fee'
                    : ($row->status === 'approved' ? 'Upgraded' : 'Under Review');
            }
            return $row;
        });

        return response()->json($result);
    }

    public function studentShow(Request $req, $id)
    {
        $sa = $this->ownedStudentApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse)
            return $sa;

        return response()->json($this->parseApp($sa, $req));
    }

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

        // students is a LEFT join — was inner, same bug as index() above:
        // a fresh application's student_id is null until approval, so this
        // lookup could never find one by application_no/mobile/aadhar/abc_id
        // etc. drc (already joined for `code`) is the fallback identity
        // source, same pattern as index() and baseApplicationQueue().
        $app = DB::table('student_applications as sa')
            ->leftJoin('students as s', 's.id', 'sa.student_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->leftJoin('direct_registrations as drc', 'drc.id', 'sa.direct_registration_id')
            ->where(function ($qb) use ($q) {
                $qb->where('sa.application_no', $q)
                    ->orWhere('dr.registration_no', $q)
                    ->orWhere('drc.registration_no', $q)
                    ->orWhere('s.university_roll_no', $q)
                    ->orWhere('s.enrollment_no', $q)
                    ->orWhere('s.mobile', $q)
                    ->orWhere('drc.mobile', $q)
                    ->orWhere('s.aadhar_no', $q)
                    ->orWhere('drc.aadhar_no', $q)
                    ->orWhere('s.abc_id', $q)
                    ->orWhere('drc.abc_id', $q);
            })
            ->whereNull('sa.deleted_at')
            ->select('sa.*', DB::raw('COALESCE(dr.registration_no, drc.registration_no) as registration_no'), 'drc.unique_code as code')
            ->orderByDesc('sa.created_at')
            ->first();

        if (!$app) {
            return response()->json(['message' => 'No record found.'], 404);
        }

        // $app->student_id can be null (fresh, pre-approval) — $student
        // stays null in that case, and every ?-> / ?? read below already
        // handles that.
        $student = $app->student_id ? DB::table('students')->where('id', $app->student_id)->first() : null;
        $program = DB::table('programs')->where('id', $app->program_id)->first();

        // Only exists once the college has approved this exact application.
        $admissionRow = DB::table('admissions')->where('application_id', $app->id)->first();

        return response()->json([
            'student' => $student,
            'admission' => array_merge((array) $app, [
                'application_id' => $app->id,
                'reg_no' => $app->registration_no,
                'registration_no' => $app->registration_no,
                'roll_no' => $student?->university_roll_no,
                'university_roll_no' => $student?->university_roll_no,
                'admission_id' => $admissionRow->id ?? null,
                'admission_no' => $admissionRow->admission_no ?? null,
            ]),
            'program' => $program,
        ]);
    }

    public function upgradeLookup(Request $req)
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

        $student = DB::table('students as s')
            ->leftJoin('student_applications as sa', function ($j) use ($q) {
                $j->on('sa.student_id', 's.id')->where('sa.application_no', $q);
            })
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->where(function ($qb) use ($q) {
                $qb->where('s.university_roll_no', $q)
                    ->orWhere('s.enrollment_no', $q)
                    ->orWhere('s.mobile', $q)
                    ->orWhere('s.aadhar_no', $q)
                    ->orWhere('sa.application_no', $q)
                    ->orWhere('dr.registration_no', $q);
            })
            ->whereNull('s.deleted_at')
            ->select(
                's.*',
                'dr.name',
                'dr.father_name',
                'dr.mother_name',
                'dr.domestic_state',
                'dr.caste_cert_no',
                'dr.registration_no',
                'sa.application_no'
            )
            ->first();

        if (!$student) {
            return response()->json(['message' => 'No matching student found.'], 404);
        }

        $admission = DB::table('admissions as a')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->where('a.student_id', $student->id)
            ->where('a.status', 'active')
            ->orderByDesc('a.id')
            ->select('a.*', 'p.short_name as class', 'p.full_name', 'p.level', 'p.total_semesters')
            ->first();

        if (!$admission) {
            return response()->json(['message' => 'No active admission found for this student.'], 404);
        }

        if ($admission->semester_no >= $admission->total_semesters) {
            return response()->json(['message' => 'Student has already completed the final semester — no further upgrade is possible.'], 422);
        }

        $existingUpgrade = DB::table('student_applications')
            ->where('student_id', $student->id)
            ->where('program_id', $admission->program_id)
            ->where('application_type', 'semester_upgrade')
            ->where('semester_no', $admission->semester_no + 1)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->whereNull('deleted_at')
            ->first();

        $student->name = $student->name ?: trim(implode(' ', array_filter([
            $student->first_name ?? null,
            $student->middle_name ?? null,
            $student->last_name ?? null,
        ])));

        return response()->json([
            'student' => $student,
            'admission' => $admission,
            'next_semester_no' => $admission->semester_no + 1,
            'existing_upgrade_application' => $existingUpgrade,
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
            'application_type' => 'required|in:back_paper,semester_upgrade',
            'semester_no' => 'nullable|integer|exists:semester_masters,semester_num',
            'paper_ids' => 'nullable|array',
            'selected_subjects' => 'nullable|array',
            'compulsory_paper_ids' => 'nullable|array',
            'optional_paper_ids' => 'nullable|array',
            'exam_mode' => 'nullable|string|max:20',
            'course_year' => 'nullable|string|max:20',
            'aadhar_no' => 'nullable|string|max:20',
            'abc_id' => 'nullable|string|max:50',
            'ddurn' => 'nullable|string|max:50',
            'enrollment_no' => 'nullable|string|max:50',
            // Educational Details (part_3)
            'cgpa' => 'nullable|numeric|min:0|max:10',
            'result' => 'nullable|string|max:20',
            // TC & Migration Details (part_4)
            'tc_status' => 'nullable|string|max:30',
            'migration_status' => 'nullable|string|max:30',
            'family_id' => 'nullable|string|max:50',
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

        // Store paper selection in part_7 if provided (back_paper flow — left
        // untouched; semester_upgrade uses the dedicated columns below).
        $part7 = $req->paper_ids ? json_encode(['paper_ids' => $req->paper_ids]) : null;

        $part1 = array_filter([
            'exam_mode' => $req->exam_mode,
            'course_year' => $req->course_year,
            'aadhar_no' => $req->aadhar_no,
            'abc_id' => $req->abc_id,
            'ddurn' => $req->ddurn,
            'enrollment_no' => $req->enrollment_no,
        ], fn($v) => $v !== null && $v !== '');
        $part3 = array_filter([
            'cgpa' => $req->cgpa,
            'result' => $req->result,
        ], fn($v) => $v !== null && $v !== '');
        $part4 = array_filter([
            'tc_status' => $req->tc_status,
            'migration_status' => $req->migration_status,
            'family_id' => $req->family_id,
        ], fn($v) => $v !== null && $v !== '');

        $part6Data = [];
        if ($req->has('compulsory_paper_ids')) {
            $part6Data['selected_subjects'] = array_values($req->compulsory_paper_ids ?? []);
        } elseif ($req->has('selected_subjects')) {
            $part6Data['selected_subjects'] = array_values($req->selected_subjects ?? []);
        }
        if ($req->has('optional_paper_ids')) {
            $part6Data['selected_optional_subjects'] = array_values($req->optional_paper_ids ?? []);
        }
        $part6 = $part6Data ? json_encode($part6Data) : null;

        $progress = array_filter([
            'part1' => !empty($part1),
            'part3' => !empty($part3),
            'part4' => !empty($part4),
            'part6' => !empty($part6Data),
            'part7' => !empty($part7),
        ]);

        $id = DB::table('student_applications')->insertGetId([
            'organization_id' => $req->user()->organization_id,
            'student_id' => $req->student_id,
            'program_id' => $req->program_id,
            'academic_year' => $req->academic_year,
            'application_type' => $req->application_type,
            'semester_no' => $req->semester_no,
            'application_no' => $appNo,
            'status' => 'submitted',   // office-created apps go straight to submitted
            'form_progress' => json_encode($progress),
            'part_1' => $part1 ? json_encode($part1) : null,
            'part_3' => $part3 ? json_encode($part3) : null,
            'part_4' => $part4 ? json_encode($part4) : null,
            'part_6' => $part6,
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
     * POST /college/applications/{id}/approve
     * status is this table's single source of truth for application state;
     * approved_by/approved_at are the only "who/when" columns left here.
     */
    public function approve(Request $req, $id)
    {
        $app = DB::table('student_applications')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$app)
            return response()->json(['message' => 'Application not found.'], 404);

        if ($locked = $this->assertNotLocked($app, 'approved')) {
            return $locked;
        }

        DB::table('student_applications')->where('id', $id)->update([
            'status' => 'approved',
            'approved_by' => $req->user()->id,
            'approved_at' => now(),
            'updated_at' => now(),
        ]);

        $notifyTarget = $this->resolveNotifyTarget($app);
        if ($notifyTarget) {
            // application_approved template: {#var#} = application no,
            // {#var#} = the email the fee-payment link goes to.
            app(NotificationService::class)->sendByTrigger(
                $notifyTarget,
                'application_approved',
                [$app->application_no, $notifyTarget->email]
            );
        } else {
            Log::error("approve(): no mobile/email resolvable to notify for application {$id} (student_id=" . ($app->student_id ?? 'null') . ", user_id=" . ($app->user_id ?? 'null') . ") — application_approved not sent.");
        }

        return response()->json(['message' => 'Application approved.']);
    }
    private function resolveNotifyTarget(object $app): ?object
    {
        if ($app->student_id) {
            $student = DB::table('students')->where('id', $app->student_id)->first();
            if ($student) return $student;
        }
        if ($app->user_id) {
            $student = DB::table('students')->where('user_id', $app->user_id)->first();
            if ($student) return $student;
        }

        $registration = $app->direct_registration_id
            ? DB::table('direct_registrations')->where('id', $app->direct_registration_id)->first()
            : null;
        $user = $app->user_id ? DB::table('users')->where('id', $app->user_id)->first() : null;

        $mobile = $registration->mobile ?? $user->mobile ?? null;
        $email = $registration->email ?? $user->email ?? null;
        if (!$mobile && !$email) {
            return null; // nothing to notify through
        }

        return (object) [
            'id' => null,
            'organization_id' => $app->organization_id,
            'mobile' => $mobile,
            'email' => $email,
        ];
    }

    public function rejectOrHold(Request $req, $id)
    {
        $v = Validator::make($req->all(), [
            'decision' => 'required|in:hold,reject',
            'hold_type' => 'required_if:decision,hold|nullable|in:Highly Respected Objection,General Instruction Objection',
            'reason' => 'required|string',
            'objections' => 'nullable|array|max:3',
            'objections.*' => 'nullable|string',
            'submitted_at' => 'nullable|date',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $submittedByName = $req->user()->name ?? null;

        $app = DB::table('student_applications')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$app)
            return response()->json(['message' => 'Application not found.'], 404);

        $newStatus = $req->decision === 'reject' ? 'rejected' : 'on_hold';

        if ($locked = $this->assertNotLocked($app, $newStatus)) {
            return $locked;
        }

        $org = DB::table('organizations')->where('id', $app->organization_id)->first();
        $refNo = app(AdmissionNumberService::class)->rejectedApplicationRefNo($org);

        DB::transaction(function () use ($app, $req, $newStatus, $refNo, $submittedByName) {
            DB::table('rejected_applications')->insert([
                'organization_id' => $app->organization_id,
                'student_application_id' => $app->id,
                'ref_no' => $refNo,
                'decision' => $req->decision,
                'status' => $req->decision === 'hold' ? 'hold' : 'active',
                'hold_type' => $req->decision === 'hold' ? $req->hold_type : null,
                'reason' => $req->reason,
                'objections' => json_encode(array_values(array_filter($req->input('objections', [])))),
                'submitted_by' => $submittedByName,
                'submitted_at' => $req->submitted_at ? \Carbon\Carbon::parse($req->submitted_at) : now(),
                'decided_by' => $req->user()->id,
                'decided_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('student_applications')->where('id', $app->id)->update([
                'status' => $newStatus,
                'updated_at' => now(),
            ]);
        });

        $this->blockRelatedRecords($app, $newStatus, $req->reason, $req->user()?->id);

        $notifyTarget = $this->resolveNotifyTarget($app);
        if ($notifyTarget) {
            // application_rejected / application_hold templates both take
            // {#var#} = application no, {#var#} = the reason text. The hold
            // template has no dlt_template_id, so sendByTrigger() sends it
            // by email only, per spec.
            $trigger = $req->decision === 'hold' ? 'application_hold' : 'application_rejected';
            app(NotificationService::class)->sendByTrigger(
                $notifyTarget,
                $trigger,
                [$app->application_no, $req->reason]
            );
        } else {
            Log::error("rejectOrHold(): no mobile/email resolvable to notify for application {$id} (student_id=" . ($app->student_id ?? 'null') . ", user_id=" . ($app->user_id ?? 'null') . ") — {$newStatus} notification not sent.");
        }

        $decisionLabel = $req->decision === 'hold' ? 'placed on hold' : 'rejected';
        return response()->json(['message' => "Application {$decisionLabel}.", 'ref_no' => $refNo]);
    }

    public function releaseHold(Request $req, $id)
    {
        $app = DB::table('student_applications')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$app)
            return response()->json(['message' => 'Application not found.'], 404);
        if ($app->status !== 'on_hold') {
            return response()->json(['message' => 'This application is not currently on hold.'], 422);
        }

        $hold = DB::table('rejected_applications')
            ->where('student_application_id', $id)
            ->where('decision', 'hold')
            ->where('status', 'hold')
            ->orderByDesc('id')
            ->first();
        if (!$hold) {
            return response()->json(['message' => 'No active hold record found for this application.'], 422);
        }

        $user = $req->user();
        if (
            $hold->hold_type === 'Highly Respected Objection'
            && !$user->hasAnyRole(['principal', 'proctor', 'super_admin'])
        ) {
            return response()->json(['message' => 'Only a Principal or Proctor can release a Highly Respected Objection hold.'], 403);
        }
        if (
            $hold->hold_type === 'General Instruction Objection'
            && !$user->hasAnyRole(['college_admin', 'super_admin'])
            && !$user->can('verify-admissions')
        ) {
            return response()->json(['message' => 'Only Admin or Verifier authorities can release a General Instruction Objection hold.'], 403);
        }

        $v = Validator::make($req->all(), [
            'release_due_to' => 'nullable|string',
            'release_remarks' => 'nullable|string',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        DB::transaction(function () use ($hold, $app, $req) {
            DB::table('rejected_applications')->where('id', $hold->id)->update([
                'status' => 'released',
                'released_by' => $req->user()->id,
                'released_at' => now(),
                'release_due_to' => $req->release_due_to,
                'release_remarks' => $req->release_remarks,
                'updated_at' => now(),
            ]);

            DB::table('student_applications')->where('id', $app->id)->update([
                'status' => 'submitted',
                'updated_at' => now(),
            ]);

            DB::table('admissions')->where('application_id', $app->id)->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);

        });

        return response()->json(['message' => 'Hold released.']);
    }

    /**
     * GET /college/applications/{id}/hold-reject-slip
     * Only meaningful once a hold/reject decision exists — locked out for
     * anything else, per spec.
     */
    public function holdRejectSlip(Request $req, $id)
    {
        $sa = DB::table('student_applications')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$sa) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        if (!in_array($sa->status, ['on_hold', 'rejected'], true)) {
            return response()->json(['message' => 'This application has no hold or rejection decision to print yet.'], 422);
        }

        $decision = DB::table('rejected_applications')
            ->where('student_application_id', $sa->id)
            ->orderByDesc('id')
            ->first();
        if (!$decision) {
            return response()->json(['message' => 'No hold or rejection record found for this application.'], 422);
        }

        $student = DB::table('students')->where('id', $sa->student_id)->first();
        $program = DB::table('programs')->where('id', $sa->program_id)->first();
        $org = DB::table('organizations')->where('id', $sa->organization_id)->first();
        $decidedByName = $decision->decided_by ? DB::table('users')->where('id', $decision->decided_by)->value('name') : null;
        $releasedByName = $decision->released_by ? DB::table('users')->where('id', $decision->released_by)->value('name') : null;

        $studentName = $student ? trim(implode(' ', array_filter([
            $student->first_name ?? null,
            $student->middle_name ?? null,
            $student->last_name ?? null,
        ]))) : null;

        try {
            $pdf = Pdf::loadView('pdf.hold-reject-slip', [
                'sa' => $sa,
                'decision' => $decision,
                'student' => $student,
                'program' => $program,
                'org' => $org,
                'studentName' => $studentName,
                'decidedByName' => $decidedByName,
                'releasedByName' => $releasedByName,
            ])->setPaper('a4');
            $this->registerHindiFont($pdf);
            return response()->streamDownload(
                fn() => print ($pdf->output()),
                "Application-{$decision->ref_no}-Slip.pdf",
                ['Content-Type' => 'application/pdf']
            );
        } catch (\Throwable $e) {
            Log::error('holdRejectSlip failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Could not generate the slip.'], 500);
        }
    }

    /**
     * Once an application is approved AND fee_paid, there is no going back:
     * the only valid transitions left are cancel or hold. Everything else
     * (re-approve, reject, roll back to submitted/under_review) is refused.
     */
    private function assertNotLocked(object $app, string $newStatus): ?JsonResponse
    {
        if ($app->status === 'approved' && $app->fee_paid && !in_array($newStatus, ['cancelled', 'on_hold'], true)) {
            return response()->json([
                'message' => 'This application is already approved and the fee is paid — it cannot be moved back. Only Cancel or Hold is allowed.',
            ], 422);
        }
        return null;
    }

    /**
     * IMPORTANT: this must NOT touch students.is_blocked/status. That flag
     * is a separate, intentional "Block/Unblock" admin action (see
     * StudentController::update()/updateStatus(), AuthorizationController's
     * block/unblock action handlers, and AmendmentController's dedicated
     * block/unblock amendment type)
     */
    public function blockRelatedRecords(object $app, string $newStatus, ?string $reason, ?int $byUserId): void
    {
        $admission = DB::table('admissions')->where('application_id', $app->id)->first();
        if (!$admission) {
            return;
        }

        $admissionUpdate = [
            'status' => $newStatus, // 'rejected' or 'on_hold' (see rejectOrHold()) — 'rejected' added to the admissions.status check constraint in 2026_09_21_090000_add_rejected_to_admissions_status
            'updated_at' => now(),
        ];
        if ($newStatus === 'cancelled') {
            $admissionUpdate['cancel_reason'] = $reason;
            $admissionUpdate['cancel_date'] = now()->toDateString();
            $admissionUpdate['cancelled_by'] = $byUserId;
        }
        DB::table('admissions')->where('id', $admission->id)->update($admissionUpdate);
    }

    /**
     * The moment an application is approved AND paid:
     *  1. Create the students row FOR REAL if one doesn't already exist for
     *     this applicant — this is the one and only place that happens.
     *     "Student" means admitted; up to this point the applicant was
     *     tracked purely by user_id (see store(), ownedStudentApp()). Built
     *     from part_1 (personal) + part_2 (address) — both guaranteed
     *     present, since submission requires all 8 parts filled — with
     *     direct_registrations as the source for name/mobile/gender/dob
     *     (Part 1 deliberately doesn't re-collect those; they're "locked"
     *     from registration on the frontend).
     *  2. Create the admissions row (this is what "actually admitted" means
     *     in this schema — admissions.application_id is a NOT NULL FK back
     *     to this exact application).
     *  3. Flip students.is_confirmed to true and assign the official
     *     student_code if this is the student's first confirmed admission.
     *     Backfill student_applications.student_id now that it's known.
     * Idempotent — skips everything if already done for this application.
     */
    private function confirmStudentAndCreateAdmission(object $app, int $approvedByUserId): void
    {
        $existing = DB::table('admissions')->where('application_id', $app->id)->first();
        if ($existing) {
            // Already processed (e.g. re-approving after a status bounce) —
            // still sync $app->student_id in memory (see the note below for
            // why this matters to the caller) before returning.
            $app->student_id = $existing->student_id;
            return;
        }

        $studentId = $app->student_id ?? null;
        if (!$studentId) {
            $studentId = $app->user_id
                ? DB::table('students')->where('user_id', $app->user_id)->value('id')
                : null;
        }
        if (!$studentId) {
            $studentId = $this->createStudentFromApprovedApplication($app);
        }
        if (!$studentId) {
            Log::error("confirmStudentAndCreateAdmission: could not create/resolve a students row for application {$app->id} (user_id=" . ($app->user_id ?? 'null') . ") — aborting admission creation.");
            return;
        }

        if (!$app->student_id) {
            DB::table('student_applications')->where('id', $app->id)->update([
                'student_id' => $studentId,
                'updated_at' => now(),
            ]);
        }
        $app->student_id = $studentId;

        $admissionTypeMap = [
            'semester_upgrade' => 'upgrade',
            'regular' => 'regular',
            'back_paper' => 'back_paper',
        ];
        $admissionType = $admissionTypeMap[$app->application_type] ?? 'regular';

        $program = DB::table('programs')->where('id', $app->program_id)->first();
        $student = DB::table('students')->where('id', $studentId)->first();

        $svc = app(AdmissionNumberService::class);

        $admissionNo = $app->academic_year . '-' . str_pad((string) $app->id, 6, '0', STR_PAD_LEFT);

        DB::table('admissions')->insertGetId([
            'organization_id' => $app->organization_id,
            'student_id' => $studentId,
            'program_id' => $app->program_id,
            'application_id' => $app->id,
            'academic_year' => $app->academic_year,
            'semester_no' => $app->semester_no,
            'admission_type' => $admissionType,
            'admission_no' => $admissionNo,
            'admission_date' => now()->toDateString(),
            'is_verified' => true,
            'verified_by' => $approvedByUserId,
            'verified_at' => now(),
            'status' => 'active',
            'file_no' => $svc->fileNo($app->academic_year),
            'record_no' => $svc->recordNo(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentUpdate = [
            'is_confirmed' => true,
            'confirmed_at' => now(),
            'confirmed_application_id' => $app->id,
            'updated_at' => now(),
        ];

        // Assign the permanent Student ID once, on first confirmation only.
        if ($student && empty($student->student_code) && $program) {
            try {
                $studentUpdate['student_code'] = $svc->studentId($app->academic_year, $program, $student->category);
            } catch (\Throwable $e) {
                Log::warning("student_code generation failed for student {$studentId}: " . $e->getMessage());
            }
        }

        DB::table('students')->where('id', $studentId)->update($studentUpdate);
    }

    /**
     * Build the real, final students row from an approved+paid application.
     * Only called once, from confirmStudentAndCreateAdmission() above, when
     * this applicant has no students row yet.
     */
    private function createStudentFromApprovedApplication(object $app): ?int
    {
        if (!$app->user_id) {
            return null; // no owner to attribute this row to — nothing safe to create
        }

        $user = DB::table('users')->where('id', $app->user_id)->first();

        $registration = DB::table('direct_registrations')
            ->where('user_id', $app->user_id)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $part1 = json_decode($app->part_1 ?? '{}', true) ?: [];
        $part2 = json_decode($app->part_2 ?? '{}', true) ?: [];

        $name = $registration->name ?? $user->name ?? null;
        $mobile = $registration->mobile ?? $user->mobile ?? null;
        if (empty($name) || empty($mobile)) {
            return null; // nothing usable to create a real row from
        }

        $parts = preg_split('/\s+/', trim($name));
        $first = array_shift($parts) ?: $name;
        $last = $parts ? array_pop($parts) : '';
        $middle = $parts ? implode(' ', $parts) : null;

        $genderRaw = $part1['gender'] ?? $registration->gender ?? null;
        $genderMap = ['male' => 'male', 'female' => 'female', 'transgender' => 'other', 'other' => 'other', 'trans' => 'other'];
        $gender = $genderMap[strtolower((string) $genderRaw)] ?? 'other';

        $dobRaw = $part1['date_of_birth'] ?? $registration->dob ?? null;
        $dob = null;
        if (!empty($dobRaw)) {
            try {
                $dob = \Carbon\Carbon::parse($dobRaw)->toDateString();
            } catch (\Throwable $e) {
                $dob = null;
            }
        }

        $catMap = ['general' => 'general', 'gen' => 'general', 'obc' => 'obc', 'sc' => 'sc', 'st' => 'st', 'ews' => 'ews'];
        $categoryRaw = $part1['social_category'] ?? $registration->category ?? null;
        $category = $catMap[strtolower((string) $categoryRaw)] ?? 'general';

        $emailToUse = $registration->email ?? $user->email ?? null;
        $aadharNo = $part1['aadhar_no'] ?? $registration->aadhar_no ?? null;

        $permAddress = $part2['perm_house_no'] ?? null;
        $permCity = $part2['perm_mohalla'] ?? null;
        $permDistrict = $part2['perm_district'] ?? null;
        $permState = $part2['perm_state'] ?? $registration->domestic_state ?? null;
        $permPin = $part2['perm_pin_code'] ?? null;

        if (!$dob || !$permAddress || !$permCity || !$permDistrict || !$permState || !$permPin) {
            Log::error("createStudentFromApprovedApplication: incomplete data for application {$app->id} (user_id={$app->user_id}) — missing dob and/or permanent address fields. Cannot create students row.");
            return null;
        }

        // students_email_unique_active / _mobile_unique_active /
        // _aadhar_unique_active are global and now live constraints. Two
        // independent applicants can pass every earlier, session/course-
        // scoped registration check and still collide HERE, at approval
        // time,
        $conflict = DB::table('students')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($mobile, $emailToUse, $aadharNo) {
                $q->where('mobile', $mobile);
                if ($emailToUse) {
                    $q->orWhere('email', $emailToUse);
                }
                if ($aadharNo) {
                    $q->orWhere('aadhar_no', $aadharNo);
                }
            })
            ->first();
        if ($conflict) {
            Log::error("createStudentFromApprovedApplication: mobile/email/aadhar for application {$app->id} (user_id={$app->user_id}) already belongs to students.id={$conflict->id} — refusing to create a duplicate students row. Needs manual review (likely a genuine duplicate identity or a data-entry collision).");
            return null;
        }

        return DB::table('students')->insertGetId([
            'organization_id' => $app->organization_id,
            'user_id' => $app->user_id,
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last ?: $first,
            'gender' => $gender,
            'date_of_birth' => $dob,
            'category' => $category,
            'religion' => $part1['religion'] ?? $registration->religion ?? null,
            'nationality' => $part1['nationality'] ?? $registration->nationality ?? 'Indian',
            'aadhar_no' => $aadharNo,
            'abc_id' => $part1['abc_id'] ?? $registration->abc_id ?? null,
            'email' => $emailToUse,
            'mobile' => $mobile,
            'permanent_address' => $permAddress,
            'permanent_city' => $permCity,
            'permanent_district' => $permDistrict,
            'permanent_state' => $permState,
            'permanent_pin' => $permPin,
            'status' => 'active',
            'is_confirmed' => false, // flipped true right after this returns
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function show(Request $req, $id)
    {
        $sa = DB::table('student_applications')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$sa) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        if ($denied = $this->rejectIfCodeInvalid($req, $sa)) {
            return $denied;
        }

        return response()->json($this->parseApp($sa, $req));
    }

    public function showByNumber(Request $req, string $applicationNo)
    {
        $sa = DB::table('student_applications')
            ->where('application_no', $applicationNo)
            ->whereNull('deleted_at')
            ->first();

        if (!$sa) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        if ($denied = $this->rejectIfCodeInvalid($req, $sa)) {
            return $denied;
        }

        return response()->json($this->parseApp($sa, $req));
    }

    private function rejectIfCodeInvalid(Request $req, object $sa): ?JsonResponse
    {
        if (empty($sa->direct_registration_id)) {
            return null;
        }

        $registration = DB::table('direct_registrations')->where('id', $sa->direct_registration_id)->first();
        $suppliedCode = (string) $req->query('code', '');

        if (!$registration || empty($registration->unique_code) || $suppliedCode === '' || !hash_equals($registration->unique_code, $suppliedCode)) {
            return response()->json([
                'message' => 'This application form requires a valid registration code in the URL. Use the link provided at registration.',
            ], 403);
        }

        return null;
    }

    private function selectDrivenApplicationFields(): array
    {
        return [
            // Part 1 — Course Selection / Personal Details
            'gender',
            'marital_status',
            'social_category',
            'religion',
            'is_minority',
            'blood_group',
            'id_proof_type',
            'is_divyang',
            'nationality',
            'domestic_state',
            // Part 2 — Address & Communication (both address blocks)
            'pres_domestic_area',
            'perm_domestic_area',
            'pres_state',
            'perm_state',
            'pres_address_proof',
            'perm_address_proof',
            // Part 3 — Educational Details (per education_records[] entry)
            'course_name',
            'board_university',
            'institute_name',
            'exam_system',
            'result',
            'number_system',
            'group',
            'drop_subject',
            // Part 4 — TC & Migration Details
            'tc_condition',
            'tc_behavior',
            'mig_condition',
            'mig_reason',
            // Part 5 — Bank Details
            'account_type',
        ];
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
            $col => json_encode(TextNormalizer::upper($req->all(), $this->selectDrivenApplicationFields())),
            'form_progress' => json_encode($prog),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => "Part {$partNo} updated by office.",
            'form_progress' => $prog,
        ]);
    }

    private function registerHindiFont(\Barryvdh\DomPDF\PDF $pdf): void
    {
        $fm = $pdf->getDomPDF()->getFontMetrics();
        $regular = storage_path('fonts/NotoSansDevanagari-Regular.ttf');
        $bold = storage_path('fonts/NotoSansDevanagari-Bold.ttf');
        if (!is_file($regular) || !is_file($bold)) {
            return; // font not installed — Hindi text will fall back silently to DejaVu Sans (tofu boxes)
        }
        $fm->registerFont(['family' => 'Noto Sans Devanagari', 'style' => 'normal', 'weight' => 'normal'], $regular);
        $fm->registerFont(['family' => 'Noto Sans Devanagari', 'style' => 'normal', 'weight' => 'bold'], $bold);
        $fm->registerFont(['family' => 'Noto Sans Devanagari', 'style' => 'italic', 'weight' => 'normal'], $regular);
        $fm->registerFont(['family' => 'Noto Sans Devanagari', 'style' => 'italic', 'weight' => 'bold'], $bold);
    }

    public function buildApplicationFormPdfData(object $sa): array
    {
        $admission = DB::table('admissions')
            ->where('application_id', $sa->id)
            ->orderByDesc('id')
            ->first();

        $student = DB::table('students')->where('id', $sa->student_id)->first();
        $program = DB::table('programs')->where('id', $sa->program_id)->first();
        $org = DB::table('organizations')->where('id', $sa->organization_id)->first();

        // (sa.direct_registration_id) — falls back to a loose match for
        // legacy applications created before that column existed.
        $registration = $sa->direct_registration_id
            ? DB::table('direct_registrations')->where('id', $sa->direct_registration_id)->first()
            : DB::table('direct_registrations')
                ->where('user_id', $student->user_id ?? 0)
                ->where('program_id', $sa->program_id)
                ->orderByDesc('id')
                ->first();

        $documents = DB::table('student_application_documents')
            ->where('application_id', $sa->id)
            ->orderBy('id')
            ->get();

        $toDataUri = function (?string $path) {
            if (!$path)
                return null;
            try {
                foreach (['supabase', 'public'] as $disk) {
                    try {
                        if (Storage::disk($disk)->exists($path)) {
                            $bytes = Storage::disk($disk)->get($path);
                            $mime = Storage::disk($disk)->mimeType($path) ?: 'image/jpeg';
                            return "data:{$mime};base64," . base64_encode($bytes);
                        }
                    } catch (\Throwable $e) {
                        continue; // disk not configured (e.g. supabase creds missing) — try the next one
                    }
                }
            } catch (\Throwable $e) {
                // fall through — no image is better than a fatal PDF request
            }
            return null;
        };
        $photoDoc = $documents->firstWhere('document_type', 'photo');
        $sigDoc = $documents->firstWhere('document_type', 'signature');
        $photoDataUri = $toDataUri($photoDoc->path ?? null);
        $signatureDataUri = $toDataUri($sigDoc->path ?? null);

        $parts = [];
        foreach (range(1, 8) as $n) {
            $col = 'part_' . $n;
            $parts[$n] = !empty($sa->$col) ? json_decode($sa->$col, true) : null;
        }

        $part6 = $parts[6] ?? [];
        $subjectIds = array_filter([
            $part6['major_subject_1'] ?? null,
            $part6['major_subject_2'] ?? null,
            $part6['major_subject_3'] ?? null,
            $part6['minor_subject_1'] ?? null,
        ]);
        $subjectsById = $subjectIds
            ? DB::table('subjects')->whereIn('id', $subjectIds)->get()->keyBy('id')
            : collect();
        $papersBySubject = $subjectIds
            ? DB::table('subject_papers')
                ->whereIn('subject_id', $subjectIds)
                ->where('semester_no', $sa->semester_no)
                ->get()
                ->groupBy('subject_id')
            : collect();
        $vocIds = array_filter([$part6['aec_subject'] ?? null, $part6['sec_subject'] ?? null]);
        $vocById = $vocIds
            ? DB::table('vocational_papers')->whereIn('id', $vocIds)->get()->keyBy('id')
            : collect();

        $subjectRows = [];
        $rowDefs = [
            ['key' => 'major_subject_1', 'label' => 'Major Subject -1', 'kind' => 'subject'],
            ['key' => 'major_subject_2', 'label' => 'Major Subject -2', 'kind' => 'subject'],
            ['key' => 'minor_subject_1', 'label' => 'Minor Subject -1', 'kind' => 'subject'],
            ['key' => 'aec_subject', 'label' => 'Ability Enhancement Course', 'kind' => 'vocational'],
            ['key' => 'sec_subject', 'label' => 'Skill Enhancement Course', 'kind' => 'vocational'],
        ];
        foreach ($rowDefs as $def) {
            $id = $part6[$def['key']] ?? null;
            if (!$id)
                continue;
            if ($def['kind'] === 'subject') {
                $subj = $subjectsById->get($id);
                $papers = $papersBySubject->get($id, collect());
                $subjectRows[] = [
                    'label' => $def['label'],
                    'subject' => $subj->name ?? '—',
                    'paper_code' => $papers->pluck('paper_code')->filter()->implode(', ') ?: '—',
                    'paper_title' => $papers->pluck('paper_name')->filter()->implode(', ') ?: '—',
                ];
            } else {
                $p = $vocById->get($id);
                $subjectRows[] = [
                    'label' => $def['label'],
                    'subject' => $p->group_name ?? '—',
                    'paper_code' => $p->paper_code ?? '—',
                    'paper_title' => $p->paper_name ?? '—',
                ];
            }
        }

        return compact('sa', 'student', 'program', 'org', 'admission', 'registration', 'documents', 'parts', 'subjectRows', 'subjectsById', 'vocById', 'photoDataUri', 'signatureDataUri');
    }

    /**
     * GET /college/applications/{id}/print
     * Full application form PDF — office reprint, gated to a completed
     * (final-submitted or beyond draft) AND education-fee-paid application.
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
        // flow below).
        if (!$sa->fee_paid) {
            return response()->json(['message' => 'Education fee is not paid yet. Print is available only after payment.'], 422);
        }

        try {
            $data = $this->buildApplicationFormPdfData($sa);
            $pdf = Pdf::loadView('pdf.application-form', $data)->setPaper('a4');
            $this->registerHindiFont($pdf);
            return response()->streamDownload(
                fn() => print ($pdf->output()),
                "Application-Form-{$sa->application_no}.pdf",
                ['Content-Type' => 'application/pdf']
            );
        } catch (\Throwable $e) {
            Log::error("Application form PDF failed for {$sa->id}: " . $e->getMessage());
            return response()->json(['message' => 'Could not generate the application form PDF.'], 500);
        }
    }

    /**
     * GET /student/applications/{id}/print
     * The student's own copy of the same PDF as printForm() above, available
     */
    public function studentPrintApplicationForm(Request $req, $id)
    {
        $sa = $this->ownedStudentApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse) {
            return $sa;
        }

        if (in_array($sa->status, ['draft', 'cancelled'], true)) {
            return response()->json(['message' => 'Please complete and submit your application before downloading.'], 422);
        }

        try {
            $data = $this->buildApplicationFormPdfData($sa);
            $pdf = Pdf::loadView('pdf.application-form', $data)->setPaper('a4');
            $this->registerHindiFont($pdf);
            return response()->streamDownload(
                fn() => print ($pdf->output()),
                "Application-Form-{$sa->application_no}.pdf",
                ['Content-Type' => 'application/pdf']
            );
        } catch (\Throwable $e) {
            Log::error("Student application form PDF failed for {$sa->id}: " . $e->getMessage());
            return response()->json(['message' => 'Could not generate the application form PDF.'], 500);
        }
    }

    public function holdRejectSearch(Request $req)
    {
        $latestReg = DB::table('direct_registrations')
            ->select('user_id', DB::raw('MAX(id) as reg_id'))
            ->whereNull('deleted_at')
            ->groupBy('user_id');

        $latestDecisionIds = DB::table('rejected_applications')
            ->select('student_application_id', DB::raw('MAX(id) as decision_id'))
            ->groupBy('student_application_id');

        $q = DB::table('student_applications as sa')
            ->leftJoin('students as s', 's.id', 'sa.student_id')
            ->join('programs as p', 'p.id', 'sa.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->leftJoin('direct_registrations as drc', 'drc.id', 'sa.direct_registration_id')
            ->leftJoinSub($latestDecisionIds, 'ld', 'ld.student_application_id', 'sa.id')
            ->leftJoin('rejected_applications as ra', 'ra.id', 'ld.decision_id')
            ->leftJoin('users as decider', 'decider.id', 'ra.decided_by')
            ->leftJoin('users as releaser', 'releaser.id', 'ra.released_by')
            ->whereNull('sa.deleted_at')
            ->select(
                'sa.id',
                'sa.program_id',
                'sa.application_no',
                'sa.academic_year',
                'sa.application_type',
                'sa.semester_no',
                'sa.status',
                'sa.part_1',
                'sa.part_6',
                'ra.id as decision_id',
                'ra.ref_no',
                'ra.decision',
                'ra.status as decision_status',
                'ra.hold_type',
                'ra.reason',
                'ra.objections',
                'ra.submitted_by',
                'ra.submitted_at',
                'ra.release_due_to',
                'ra.release_remarks',
                'ra.released_at',
                'decider.name as decided_by_name',
                'releaser.name as released_by_name',
                's.first_name',
                's.middle_name',
                's.last_name',
                DB::raw('COALESCE(dr.name, drc.name) as name'),
                DB::raw('COALESCE(dr.father_name, drc.father_name) as father_name'),
                DB::raw('COALESCE(s.mobile, drc.mobile) as mobile'),
                's.photo_path',
                's.signature_path',
                'p.short_name as class',
                'p.full_name'
            )
            ->when($req->id, fn($qq) => $qq->where('sa.id', $req->id))
            ->when($req->code, fn($qq) => $qq->where(function ($q3) use ($req) {
                $q3->where('dr.unique_code', $req->code)
                    ->orWhere('drc.unique_code', $req->code);
            }))
            ->when($req->search, fn($qq) => $qq->where(function ($q2) use ($req) {
                $q2->where('sa.application_no', 'ilike', "%{$req->search}%")
                    ->orWhere('ra.ref_no', 'ilike', "%{$req->search}%")
                    ->orWhere('dr.name', 'ilike', "%{$req->search}%")
                    ->orWhere('drc.name', 'ilike', "%{$req->search}%")
                    ->orWhere('s.mobile', 'ilike', "%{$req->search}%")
                    ->orWhere('drc.mobile', 'ilike', "%{$req->search}%");
            }))
            ->orderByDesc('sa.updated_at');

        $result = $q->paginate(50);

        // Photo/signature live on student_application_documents (Part 7 —
        // Uploaded Documents), document_type 'photo'/'signature' — NOT the
        // students table's photo_path/signature_path, which is a separate,
        // often-empty profile picture from registration/admission and isn't
        // what this screen should be showing. students.* stays only as a
        // last-resort fallback below.
        $appIds = $result->getCollection()->pluck('id');
        $appDocs = DB::table('student_application_documents')
            ->whereIn('application_id', $appIds)
            ->whereIn('document_type', ['photo', 'signature'])
            ->orderByDesc('id')
            ->get()
            ->groupBy('application_id');
        $resolveDocUrl = function (?string $path) {
            if (!$path)
                return null;
            try {
                return Storage::disk('supabase')->exists($path)
                    ? Storage::disk('supabase')->url($path)
                    : Storage::disk('public')->url($path);
            } catch (\Throwable $e) {
                try {
                    return Storage::disk('public')->url($path);
                } catch (\Throwable $e2) {
                    return null;
                }
            }
        };

        $result->getCollection()->transform(function ($row) use ($appDocs, $resolveDocUrl) {
            if (empty($row->name)) {
                $row->name = trim(implode(' ', array_filter([
                    $row->first_name ?? null,
                    $row->middle_name ?? null,
                    $row->last_name ?? null,
                ]))) ?: null;
            }
            $row->objections = $row->objections ? json_decode($row->objections, true) : [];
            $row->part_1 = $row->part_1 ? (is_string($row->part_1) ? json_decode($row->part_1, true) : $row->part_1) : null;
            $row->part_6 = $row->part_6 ? (is_string($row->part_6) ? json_decode($row->part_6, true) : $row->part_6) : null;

            $rowDocs = $appDocs->get($row->id, collect());
            $photoDoc = $rowDocs->firstWhere('document_type', 'photo');
            $sigDoc = $rowDocs->firstWhere('document_type', 'signature');
            $row->photo_path = $resolveDocUrl($photoDoc->path ?? null) ?? $resolveDocUrl($row->photo_path);
            $row->signature_path = $resolveDocUrl($sigDoc->path ?? null) ?? $resolveDocUrl($row->signature_path);

            return $row;
        });

        return response()->json($result);
    }

    public function backPaperPapers(Request $req)
    {
        $v = Validator::make($req->all(), [
            'application_id' => 'nullable|exists:student_applications,id',
            'admission_id' => 'nullable|exists:admissions,id',
            'semester_no' => 'required|integer',
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
            if (!$admission)
                return response()->json(['message' => 'Admission not found.'], 404);
            $programId = $admission->program_id;
            $studentId = $admission->student_id;
        } else {
            return response()->json(['message' => 'application_id or admission_id is required.'], 422);
        }

        $subjectIds = $this->eligibleBackPaperSubjectIds($studentId, $programId);

        // The Subject Master (`subjects`) row *is* the paper — it already
        // carries a real code, per-semester marks, credits, and a `type`
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
        $efId = $this->feeHeadId('EF', $orgId);
        $pfId = $this->feeHeadId('PF', $orgId);

        $papers->transform(function ($p) use ($efId, $pfId) {
            $isPractical = $p->type === 'practical';
            $p->fee_head_id = $isPractical ? $pfId : $efId;
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
    private function eligibleBackPaperSubjectIds(?int $studentId, int $programId): array
    {
        $rows = DB::table('student_applications')
            ->where('student_id', $studentId)
            ->where('program_id', $programId)
            ->whereIn('application_type', ['regular', 'semester_upgrade'])
            ->whereNotNull('part_6')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(2) // current + previous semester's registration/upgrade
            ->pluck('part_6');

        $ids = [];
        foreach ($rows as $raw) {
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            $sel = is_array($decoded) ? ($decoded['selected_subjects'] ?? []) : [];
            if (is_array($sel)) {
                foreach ($sel as $v) {
                    if (is_numeric($v)) {
                        $ids[] = (int) $v;
                    }
                }
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));

        if (!empty($ids)) {
            return $ids;
        }

        return DB::table('allotted_subjects')
            ->where('program_id', $programId)
            ->pluck('subject_id')
            ->map(fn($v) => (int) $v)
            ->all();
    }

    private function feeHeadId(string $code, ?int $orgId = null): ?int
    {
        return DB::table('fee_heads')
            ->where('code', $code)
            ->orderByRaw($orgId ? 'organization_id <> ? asc' : '1', $orgId ? [$orgId] : [])
            ->value('id');
    }

    private function computeBackPaperFee(object $sa, \Illuminate\Support\Collection $paperRows): array
    {
        $feeHeadIds = $paperRows->pluck('fee_head_id')->filter()->unique()->values()->all();
        [$gender, $category] = $this->feeGenderCategory($sa->student_id);

        $result = \App\Models\FeeStructure::breakdownFor(
            $sa->program_id,
            $sa->academic_year,
            [(int) $sa->semester_no],
            'back_paper',
            $gender,
            $category,
            $feeHeadIds ?: []
        );

        $base = $result['total'];
        $breakdown = $result['breakdown'];

        $late = 0.0;
        $schedule = DB::table('back_paper_schedules')
            ->where('program_id', $sa->program_id)
            ->where('semester', (string) $sa->semester_no)
            ->where('session_year', $sa->academic_year)
            ->first();
        if ($schedule && $schedule->late_fee_applicable && $schedule->end_on && now()->gt($schedule->end_on)) {
            $late = (float) $schedule->late_fee;
        }

        return [
            'fee_head_ids' => $feeHeadIds,
            'base_amount' => $base,
            'late_fee' => $late,
            'total' => $base + $late,
            'breakdown' => $breakdown,
            'missing_structure' => $base <= 0 && !empty($feeHeadIds),
        ];
    }

    private function computeApplicationFee(object $sa): array
    {
        $admissionType = 'regular';
        [$gender, $category] = $this->feeGenderCategory($sa->student_id);

        $result = \App\Models\FeeStructure::breakdownFor(
            $sa->program_id,
            $sa->academic_year,
            array_unique([0, (int) $sa->semester_no]),
            $admissionType,
            $gender,
            $category
        );

        return [
            'base_amount' => $result['total'],
            'total' => $result['total'],
            'breakdown' => $result['breakdown'],
            'missing_structure' => $result['total'] <= 0,
        ];
    }

    private function feeGenderCategory(?int $studentId): array
    {
        $student = $studentId ? DB::table('students')->where('id', $studentId)->first() : null;

        $gender = strtolower((string) ($student->gender ?? 'male'));
        $gender = $gender === 'other' ? 'transgender' : $gender;

        $category = strtolower((string) ($student->category ?? 'general'));
        $category = $category === 'general' ? 'gen' : $category;

        return [$gender, $category];
    }

    private function ownedStudentApp(Request $req, $id, ?string $applicationType = null)
    {
        $userId = $req->user()->id;

        $sa = DB::table('student_applications')
            ->where('id', $id)
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->orWhereIn('direct_registration_id', function ($sub) use ($userId) {
                        $sub->select('id')->from('direct_registrations')->where('user_id', $userId);
                    });
            })
            ->when($applicationType, fn($q) => $q->where('application_type', $applicationType))
            ->whereNull('deleted_at')
            ->first();

        if (!$sa)
            return response()->json(['message' => 'Application not found.'], 404);

        return $sa;
    }

    /** POST /college/applications/{id}/pay/initiate (office) */
    public function applicationPayInitiate(Request $req, $id)
    {
        $sa = DB::table('student_applications')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$sa)
            return response()->json(['message' => 'Application not found.'], 404);

        return $this->doApplicationPayInitiate($sa);
    }

    /** POST /student/applications/{id}/pay/initiate (student) */
    public function studentApplicationPayInitiate(Request $req, $id)
    {
        $sa = $this->ownedStudentApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse)
            return $sa;

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
        // Payment unlocks only after office approval (approve()) — not
        // at 'submitted'/'under_review' as before.
        if ($sa->status !== 'approved') {
            return response()->json(['message' => 'The application must be approved by the college before the education fee can be paid.'], 422);
        }
        // Subject selection lives on part_6 only — the old top-level
        // selected_subjects column was always null for real student-
        $part6 = $sa->part_6 ?? null;
        $part6 = is_string($part6) ? (json_decode($part6, true) ?? []) : ($part6 ?? []);
        if (empty($part6['selected_subjects'])) {
            return response()->json(['message' => 'Select subjects before paying the education fee.'], 422);
        }

        $fee = $this->computeApplicationFee($sa);
        if ($fee['missing_structure']) {
            return response()->json(['message' => 'No fee structure configured for this program/session yet. Contact the office.'], 422);
        }

        $rupees = (int) round($fee['total']);
        $amount = $rupees * 100; // paise

        $key = config('services.razorpay.key');
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
                    'amount' => $amount,
                    'currency' => 'INR',
                    'receipt' => 'ADM_' . $sa->application_no,
                    'payment_capture' => 1,
                    'notes' => [
                        'application_id' => (string) $sa->id,
                        'application_no' => $sa->application_no,
                        'name' => $name,
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
            'fee_amount' => $fee['total'],
            'updated_at' => now(),
        ]);

        return response()->json([
            'order_id' => $order['id'],
            'amount' => $amount,
            'amount_rupees' => $rupees,
            'currency' => 'INR',
            'key' => $key,
            'name' => $name,
            'email' => $student->email ?? '',
            'mobile' => $student->mobile ?? '',
            'application_id' => $sa->id,
        ]);
    }

    /** POST /college/applications/{id}/pay/verify (office) */
    public function applicationPayVerify(Request $req, $id)
    {
        $sa = DB::table('student_applications')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$sa)
            return response()->json(['message' => 'Application not found.'], 404);

        return $this->doApplicationPayVerify($sa, $req);
    }

    /** POST /student/applications/{id}/pay/verify (student) */
    public function studentApplicationPayVerify(Request $req, $id)
    {
        $sa = $this->ownedStudentApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse)
            return $sa;

        return $this->doApplicationPayVerify($sa, $req);
    }

    private function doApplicationPayVerify(object $sa, Request $req)
    {
        $req->validate([
            'razorpay_order_id' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);

        $secret = config('services.razorpay.secret');
        $expected = hash_hmac('sha256', $req->razorpay_order_id . '|' . $req->razorpay_payment_id, (string) $secret);

        if (!$secret || !hash_equals($expected, $req->razorpay_signature)) {
            return response()->json(['message' => 'Payment could not be verified.'], 422);
        }

        // Defense in depth — doApplicationPayInitiate() already gates on
        // this, but verify is a separate request and must not trust that
        // nothing changed (rejected/put on hold) in between.
        if ($sa->status !== 'approved') {
            return response()->json(['message' => 'This application is no longer approved for payment.'], 422);
        }
        if ($sa->fee_paid) {
            return response()->json(['message' => 'Education fee already paid.'], 409);
        }

        $fee = $this->computeApplicationFee($sa);
        $isSelfFinance = (bool) DB::table('programs')->where('id', $sa->program_id)->value('is_self_finance');

        $feeHeadNames = DB::table('fee_heads')->whereIn('id', collect($fee['breakdown'])->pluck('fee_head_id')->filter()->all() ?: [0])->pluck('name', 'id');
        $breakdown = collect($fee['breakdown'])->map(fn($b) => [
            'fee_head_id' => $b['fee_head_id'],
            'fee_head_name' => $feeHeadNames[$b['fee_head_id']] ?? 'Fee',
            'amount' => $b['amount'],
        ])->values()->all();

        // Approval already happened (gated above); this is the other half
        // of the "approved AND paid" requirement. confirmStudentAndCreate
        // Admission() now runs FIRST, before the receipt — fee_receipts.
        // admission_id is a real NOT NULL FK
        $feeRefId = null;
        $receipt = DB::transaction(function () use ($sa, $fee, $breakdown, $isSelfFinance, $req, &$feeRefId) {
            $this->confirmStudentAndCreateAdmission($sa, (int) $sa->approved_by);
            $admissionId = DB::table('admissions')->where('application_id', $sa->id)->value('id');

            $r = FeeReceipt::create([
                'organization_id' => $sa->organization_id,
                'student_id' => $sa->student_id,
                'admission_id' => $admissionId,
                'academic_year' => $sa->academic_year,
                'semester_no' => $sa->semester_no,
                'receipt_type' => 'regular_admission',
                'receipt_no' => FeeReceipt::feeReceiptNo($sa->academic_year, 'regular_admission', $isSelfFinance),
                'receipt_date' => now()->toDateString(),
                'total_amount' => $fee['base_amount'],
                'late_fine' => 0,
                'concession' => 0,
                'net_amount' => $fee['total'],
                'payment_mode' => 'online',
                'transaction_id' => $req->razorpay_payment_id,
                'fee_breakdown' => $breakdown,
                'generated_by' => $req->user()->id,
                'status' => 'active',
            ]);

            $program = DB::table('programs')->where('id', $sa->program_id)->first();
            $student = DB::table('students')->where('id', $sa->student_id)->first();
            $feeRefId = app(\App\Services\AdmissionNumberService::class)
                ->feeRefId('student_applications', 'fee_ref_id', $program, $student->category ?? null, $student->gender ?? null);

            DB::table('student_applications')->where('id', $sa->id)->update([
                'fee_paid' => true,
                'paid_at' => now(),
                'payment_ref' => $req->razorpay_payment_id,
                'fee_receipt_id' => $r->id,
                'fee_ref_id' => $feeRefId,
                // status stays 'approved' — approval already happened
                // before payment was even possible, see approve().
                'updated_at' => now(),
            ]);

            return $r;
        });

        GenerateFeeReceiptPdf::dispatch($receipt->id);

        return response()->json([
            'message' => 'Education fee paid successfully. Your admission is confirmed.',
            'application_id' => $sa->id,
            'receipt_no' => $receipt->receipt_no,
            'fee_receipt_id' => $receipt->id,
            'fee_ref_id' => $feeRefId,
        ]);
    }

    /**
     * GET /student/applications/{id}/receipt
     * Download the education-fee receipt PDF once paid. No student-facing
     * fee-receipts route existed before this — FeeReceiptController's
     * fee-receipts/{id}/download is office-only (college-scoped route
     * group). Mirrors its on-demand-generate fallback.
     */
    public function studentReceiptDownload(Request $req, $id)
    {
        $sa = $this->ownedStudentApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse)
            return $sa;

        if (!$sa->fee_receipt_id) {
            return response()->json(['message' => 'No receipt available yet.'], 404);
        }

        $receipt = FeeReceipt::find($sa->fee_receipt_id);
        if (!$receipt) {
            return response()->json(['message' => 'Receipt not found.'], 404);
        }

        if (!$receipt->pdf_path || !Storage::exists($receipt->pdf_path)) {
            GenerateFeeReceiptPdf::dispatchSync($receipt->id);
            $receipt->refresh();
        }

        if (!$receipt->pdf_path || !Storage::exists($receipt->pdf_path)) {
            return response()->json(['message' => 'Receipt PDF could not be generated. Contact the office.'], 500);
        }

        return Storage::download($receipt->pdf_path, "FeeReceipt-{$receipt->receipt_no}.pdf");
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
        if (!$sa)
            return response()->json(['message' => 'Application not found.'], 404);

        return $this->doSaveBackPaperSelection($sa, $req);
    }

    /** PUT /student/applications/{id}/back-paper (student, ownership-checked) */
    public function studentSaveBackPaperSelection(Request $req, $id)
    {
        $sa = $this->ownedStudentBackPaperApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse)
            return $sa;

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
            'paper_ids' => 'required|array|min:1',
            'paper_ids.*' => 'integer|exists:subjects,id',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $paperRows = DB::table('subjects')->whereIn('id', $req->paper_ids)->get();
        $orgId = $req->user()->organization_id ?? DB::table('student_applications')
            ->where('id', $sa->id)->value('organization_id');
        $efId = $this->feeHeadId('EF', $orgId);
        $pfId = $this->feeHeadId('PF', $orgId);
        $paperRows = $paperRows->map(function ($p) use ($efId, $pfId) {
            $p->fee_head_id = $p->type === 'practical' ? $pfId : $efId;
            return $p;
        });

        $fee = $this->computeBackPaperFee($sa, $paperRows);

        $prog = json_decode($sa->form_progress ?? '{}', true);
        $prog['part7'] = true;

        DB::table('student_applications')->where('id', $sa->id)->update([
            'part_7' => json_encode(['paper_ids' => array_values($req->paper_ids)]),
            'form_progress' => json_encode($prog),
            'fee_amount' => $fee['total'],
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Back paper selection saved.',
            'fee' => $fee,
        ]);
    }

    /** POST /college/applications/{id}/back-paper/pay/initiate (office) */
    public function backPaperPayInitiate(Request $req, $id)
    {
        $sa = DB::table('student_applications')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$sa)
            return response()->json(['message' => 'Application not found.'], 404);

        return $this->doBackPaperPayInitiate($sa);
    }

    /** POST /student/applications/{id}/back-paper/pay/initiate (student) */
    public function studentBackPaperPayInitiate(Request $req, $id)
    {
        $sa = $this->ownedStudentBackPaperApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse)
            return $sa;

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

        $key = config('services.razorpay.key');
        $secret = config('services.razorpay.secret');
        if (!$key || !$secret) {
            return response()->json(['message' => 'Payment gateway not configured. Contact the office.'], 503);
        }

        $student = DB::table('students')->where('id', $sa->student_id)->first();

        try {
            $resp = Http::withBasicAuth($key, $secret)
                ->asJson()
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount' => $amount,
                    'currency' => 'INR',
                    'receipt' => 'BP_' . $sa->application_no,
                    'payment_capture' => 1,
                    'notes' => [
                        'application_id' => (string) $sa->id,
                        'application_no' => $sa->application_no,
                        'name' => $student->name ?? '',
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
            'updated_at' => now(),
        ]);

        return response()->json([
            'order_id' => $order['id'],
            'amount' => $amount,
            'amount_rupees' => $rupees,
            'currency' => 'INR',
            'key' => $key,
            'name' => $student->name ?? '',
            'email' => $student->email ?? '',
            'mobile' => $student->mobile ?? '',
            'application_id' => $sa->id,
        ]);
    }

    /** POST /college/applications/{id}/back-paper/pay/verify (office) */
    public function backPaperPayVerify(Request $req, $id)
    {
        $sa = DB::table('student_applications')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$sa)
            return response()->json(['message' => 'Application not found.'], 404);

        return $this->doBackPaperPayVerify($sa, $req);
    }

    /** POST /student/applications/{id}/back-paper/pay/verify (student) */
    public function studentBackPaperPayVerify(Request $req, $id)
    {
        $sa = $this->ownedStudentBackPaperApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse)
            return $sa;

        return $this->doBackPaperPayVerify($sa, $req);
    }

    private function doBackPaperPayVerify(object $sa, Request $req)
    {
        $req->validate([
            'razorpay_order_id' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);

        $secret = config('services.razorpay.secret');
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

        $part7 = json_decode($sa->part_7 ?? '{}', true);
        $paperIds = $part7['paper_ids'] ?? [];
        $paperRows = DB::table('subjects')->whereIn('id', $paperIds)->get();
        $orgId = $sa->organization_id;
        $efId = $this->feeHeadId('EF', $orgId);
        $pfId = $this->feeHeadId('PF', $orgId);
        $paperRows = $paperRows->map(function ($p) use ($efId, $pfId) {
            $p->fee_head_id = $p->type === 'practical' ? $pfId : $efId;
            return $p;
        });
        $fee = $this->computeBackPaperFee($sa, $paperRows);

        $feeHeadNames = DB::table('fee_heads')->whereIn('id', $fee['fee_head_ids'] ?: [0])->pluck('name', 'id');
        $breakdown = collect($fee['breakdown'])->map(fn($b) => [
            'fee_head_id' => $b['fee_head_id'],
            'fee_head_name' => $feeHeadNames[$b['fee_head_id']] ?? 'Fee',
            'amount' => $b['amount'],
        ])->values()->all();
        if ($fee['late_fee'] > 0) {
            $breakdown[] = ['fee_head_id' => null, 'fee_head_name' => 'Late Fee', 'amount' => $fee['late_fee']];
        }

        $isSelfFinance = (bool) DB::table('programs')->where('id', $sa->program_id)->value('is_self_finance');

        $receipt = DB::transaction(fn() => FeeReceipt::create([
            'organization_id' => $orgId,
            'student_id' => $sa->student_id,
            'admission_id' => $admission->id,
            'academic_year' => $sa->academic_year,
            'semester_no' => $sa->semester_no,
            'receipt_type' => 'back_paper',
            'receipt_no' => FeeReceipt::feeReceiptNo($sa->academic_year, 'back_paper', $isSelfFinance),
            'receipt_date' => now()->toDateString(),
            'total_amount' => $fee['base_amount'],
            'late_fine' => $fee['late_fee'],
            'concession' => 0,
            'net_amount' => $fee['total'],
            'payment_mode' => 'online',
            'transaction_id' => $req->razorpay_payment_id,
            'fee_breakdown' => $breakdown,
            'generated_by' => $req->user()->id,
            'status' => 'active',
        ]));

        GenerateFeeReceiptPdf::dispatch($receipt->id);

        DB::table('student_applications')->where('id', $sa->id)->update([
            'fee_paid' => true,
            'paid_at' => now(),
            'payment_ref' => $req->razorpay_payment_id,
            'fee_receipt_id' => $receipt->id,
            'status' => 'submitted',
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Payment successful.',
            'application_id' => $sa->id,
            'receipt_no' => $receipt->receipt_no,
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
        if ($sa instanceof \Illuminate\Http\JsonResponse)
            return $sa;

        return $this->backPaperPayFailed($id);
    }

    /** GET /college/applications/{id}/back-paper/print (office) */
    public function printBackPaperForm($id)
    {
        $sa = DB::table('student_applications')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$sa)
            return response()->json(['message' => 'Application not found.'], 404);

        return $this->doPrintBackPaperForm($sa);
    }

    /** GET /student/applications/{id}/back-paper/print (student) */
    public function studentPrintBackPaperForm(Request $req, $id)
    {
        $sa = $this->ownedStudentBackPaperApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse)
            return $sa;

        return $this->doPrintBackPaperForm($sa);
    }

    private function doPrintBackPaperForm(object $sa)
    {
        if (!$sa->fee_paid) {
            return response()->json(['message' => 'Print is available only after the fee is paid.'], 422);
        }

        $student = DB::table('students')->where('id', $sa->student_id)->first();
        $program = DB::table('programs')->where('id', $sa->program_id)->first();
        $org = DB::table('organizations')->where('id', $sa->organization_id)->first();
        $admission = DB::table('admissions')
            ->where('student_id', $sa->student_id)->where('program_id', $sa->program_id)
            ->orderByDesc('id')->first();

        $part7 = json_decode($sa->part_7 ?? '{}', true);
        $paperIds = $part7['paper_ids'] ?? [];
        $papers = DB::table('subjects')
            ->whereIn('id', $paperIds)
            ->orderBy('name')
            ->get();

        try {
            $pdf = Pdf::loadView('pdf.back-paper-form', compact('sa', 'student', 'program', 'org', 'admission', 'papers'))->setPaper('a4');
            return response()->streamDownload(
                fn() => print ($pdf->output()),
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
        $sa = $this->ownedStudentApp($req, $id, 'back_paper');
        if ($sa instanceof \Illuminate\Http\JsonResponse) {
            return response()->json(['message' => 'Back paper application not found.'], 404);
        }
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
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $student = DB::table('students')->where('user_id', $req->user()->id)->first();
        if (!$student)
            return response()->json(['message' => 'Student profile not found.'], 404);

        $admission = DB::table('admissions')
            ->where('student_id', $student->id)
            ->orderByDesc('id')
            ->first();
        if (!$admission)
            return response()->json(['message' => 'No admission record found.'], 404);

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
        if ($sa instanceof \Illuminate\Http\JsonResponse)
            return $sa;

        if (!$sa->fee_receipt_id) {
            return response()->json(['message' => 'No fee receipt has been generated yet.'], 404);
        }

        $receipt = DB::table('fee_receipts')->where('id', $sa->fee_receipt_id)->first();
        if (!$receipt)
            return response()->json(['message' => 'Fee receipt not found.'], 404);

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
        $userId = $req->user()->id;

        $typeFilter = $req->input('application_type') ?? $req->input('type');

        // Latest hold/reject decision per application, if any — reason text
        // used to live directly on student_applications (rejection_reason/
        // remarks); it now lives on rejected_applications instead.
        $latestDecisionIds = DB::table('rejected_applications')
            ->select('student_application_id', DB::raw('MAX(id) as decision_id'))
            ->groupBy('student_application_id');

        $apps = DB::table('student_applications as sa')
            ->join('programs as p', 'p.id', 'sa.program_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'sa.direct_registration_id')
            ->leftJoinSub($latestDecisionIds, 'ld', 'ld.student_application_id', 'sa.id')
            ->leftJoin('rejected_applications as ra', 'ra.id', 'ld.decision_id')
            ->where(function ($q) use ($userId) {
                // No student_id here — see ownedStudentApp()'s doc comment.
                // sa.user_id covers every application going forward;
                // dr.user_id (the registration it was created from) covers
                // legacy rows created before that column existed.
                $q->where('sa.user_id', $userId)
                    ->orWhere('dr.user_id', $userId);
            })
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
                'ra.reason as rejection_reason',
                'ra.reason as remarks',
                'sa.created_at',
                'sa.updated_at',
                'p.name as program_name',
                'p.short_name',
                'p.level',
                'dr.unique_code as code',
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
            'application_type' => 'required|in:regular,back_paper,semester_upgrade',
            'semester_no' => 'nullable|integer|exists:semester_masters,semester_num',
        ]);

        // No students row is created here, on purpose. "Student" means
        // admitted — that row (and the admissions row) only ever gets
        // created once an application is actually approved, from the real
        // identity+address data the student supplies through the form
        // itself (see confirmStudentAndCreateAdmission()). Ownership of the
        // draft up to that point is tracked directly by user_id — never by
        // student_id, which doesn't exist yet and is not looked up here.
        $userId = $req->user()->id;

        $existing = DB::table('student_applications')
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    // Legacy-row fallback — otherwise a returning applicant
                    // with a pre-refactor draft (no user_id set) would pass
                    // this check and get a second, duplicate application
                    // created.
                    ->orWhereIn('direct_registration_id', function ($sub) use ($userId) {
                        $sub->select('id')->from('direct_registrations')->where('user_id', $userId);
                    });
            })
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

        $registration = DB::table('direct_registrations')
            ->where('user_id', $req->user()->id)
            ->where('program_id', $req->program_id)
            ->where('session_year', $req->academic_year)
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('id')
            ->first();

        $existingStudent = DB::table('students')->where('user_id', $userId)->first();

        $id = DB::table('student_applications')->insertGetId([
            'organization_id' => $req->user()->organization_id,
            'user_id' => $userId,
            'student_id' => $existingStudent->id ?? null,
            'program_id' => $req->program_id,
            'direct_registration_id' => $registration->id ?? null,
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
            // Frontend must carry this as &code= on every URL into the form —
            // show()/showByNumber() reject the request without it once a
            // registration is linked. Null when no registration matched.
            'code' => $registration->unique_code ?? null,
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

        $sa = $this->ownedStudentApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse)
            return $sa;
        $app = $sa;

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
        $clean = TextNormalizer::upper($clean, $this->selectDrivenApplicationFields());

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

    private function studentForContactChange(Request $req, $id): ?object
    {
        $student = DB::table('students')->where('user_id', $req->user()->id)->first();
        if (!$student)
            return null;

        $app = DB::table('student_applications')
            ->where('id', $id)
            ->where('student_id', $student->id)
            ->whereNull('deleted_at')
            ->first();
        if (!$app)
            return null;

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
            // Same fix as StudentRegistrationController's OTP endpoints: don't
            // claim success when the gateway call actually failed.
            if (!config('app.debug')) {
                return response()->json([
                    'message' => 'Could not send the OTP to that mobile number right now. Please try again in a moment.',
                ], 502);
            }
        }

        $masked = substr($req->new_mobile, 0, 2) . 'XXXXXX' . substr($req->new_mobile, -2);
        $response = ['message' => "OTP sent to {$masked}."];
        if (config('app.debug'))
            $response['debug_otp'] = $otp; // only in local/dev

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

        $cached = Cache::get("app_mobile_otp_{$student->id}");
        $pending = Cache::get("app_mobile_otp_value_{$student->id}");

        if (!$cached || !$pending || (string) $cached !== (string) $req->otp) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        // students_mobile_unique_active is global (no session/cancel scoping
        // — one students row per person for life) and now a hard DB
        // constraint. Without this check, a student changing their number to
        // one another student already has gets a raw Postgres exception
        // instead of a clean error.
        $taken = DB::table('students')
            ->where('id', '!=', $student->id)
            ->where('mobile', $pending)
            ->whereNull('deleted_at')
            ->exists();
        if ($taken) {
            return response()->json(['message' => 'That mobile number is already registered to another student account.'], 422);
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
                fn($m) => $m->to($req->new_email)->subject('SDPG College — Email Verification OTP')
            );
        } catch (\Throwable $e) {
            Log::error('Application email OTP send failed: ' . $e->getMessage());
            Log::info("APPLICATION EMAIL OTP for student {$student->id}: {$otp}");
        }

        $response = ['message' => 'OTP sent to your new email.'];
        if (config('app.debug'))
            $response['debug_otp'] = $otp;

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

        $cached = Cache::get("app_email_otp_{$student->id}");
        $pending = Cache::get("app_email_otp_value_{$student->id}");

        if (!$cached || !$pending || (string) $cached !== (string) $req->otp) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        // students_email_unique_active is global and now a hard DB
        // constraint — same reasoning as the mobile check above.
        $taken = DB::table('students')
            ->where('id', '!=', $student->id)
            ->where('email', $pending)
            ->whereNull('deleted_at')
            ->exists();
        if ($taken) {
            return response()->json(['message' => 'That email is already registered to another student account.'], 422);
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

        $sa = $this->ownedStudentApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse)
            return $sa;
        $app = $sa;

        if ($app->status !== 'draft') {
            return response()->json(['message' => "Application is already {$app->status}."], 422);
        }

        $missing = $this->missingPriorParts($app);
        if ($missing) {
            return response()->json([
                'message' => 'Complete every part before final submit. Missing: ' . implode(', ', $missing) . '.',
                'missing_parts' => $missing,
            ], 422);
        }

        $part8 = json_decode($app->part_8 ?? '{}', true) ?: [];
        $part8 = array_merge($part8, [
            'declaration_accepted' => true,
            'principal_ack' => (bool) $req->input('principal_ack', $part8['principal_ack'] ?? false),
            'fact_confirmations' => $req->input('fact_confirmations', $part8['fact_confirmations'] ?? []),
        ]);

        $prog = json_decode($app->form_progress ?? '{}', true) ?: [];
        $prog['part8'] = true;

        DB::table('student_applications')->where('id', $id)->update([
            'status' => 'submitted',
            'declaration_accepted' => true,
            'declaration_at' => now(),
            'part_8' => json_encode($part8),
            'form_progress' => json_encode($prog),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Application submitted successfully.',
            'application_no' => $app->application_no,
            'status' => 'submitted',
        ]);
    }

    /**
     * POST /college/applications/{id}/submit
     * Office-side final submit — same status transition as the student's own
     * submit() above, minus the student-ownership check (mirrors
     * updatePartOffice() above it). Needed for office-initiated applications
     * (see StudentRegistrationController::officeInitApplication()) where
     * there is no student in the loop to ever click "Final Submit" —
     * without this, an office-filled application had no way out of 'draft'.
     */
    public function submitOffice(Request $req, $id)
    {
        $req->validate(['declaration_accepted' => 'required|accepted']);

        $app = DB::table('student_applications')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$app) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        if ($app->status !== 'draft') {
            return response()->json(['message' => "Application is already {$app->status}."], 422);
        }

        $missing = $this->missingPriorParts($app);
        if ($missing) {
            return response()->json([
                'message' => 'Complete every part before final submit. Missing: ' . implode(', ', $missing) . '.',
                'missing_parts' => $missing,
            ], 422);
        }

        $part8 = json_decode($app->part_8 ?? '{}', true) ?: [];
        $part8 = array_merge($part8, [
            'declaration_accepted' => true,
            'principal_ack' => (bool) $req->input('principal_ack', $part8['principal_ack'] ?? false),
            'fact_confirmations' => $req->input('fact_confirmations', $part8['fact_confirmations'] ?? []),
        ]);

        $prog = json_decode($app->form_progress ?? '{}', true) ?: [];
        $prog['part8'] = true;

        DB::table('student_applications')->where('id', $id)->update([
            'status' => 'submitted',
            'declaration_accepted' => true,
            'declaration_at' => now(),
            'part_8' => json_encode($part8),
            'form_progress' => json_encode($prog),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Application submitted successfully.',
            'application_no' => $app->application_no,
            'status' => 'submitted',
        ]);
    }

    /**
     * Guard for submit()/submitOffice() — mirrors the frontend's own
     * priorPartsComplete check (Part8Declaration.tsx / HorizontalStepper's
     * step-9 unlock) so Final Submit can't be forced past it with a raw API
     * call. Parts 1-4 and 6 just need to have been saved at least once
     * (same "touched" definition the stepper itself still uses for those
     * parts); Bank Detail and Documents use the same real-completeness
     * checks parseApp() exposes as bank_detail_complete /
     * required_documents_complete, since "part_5 exists" was exactly the
     * false-green-tick bug this whole audit started from.
     *
     * @return string[] Human-readable list of what's missing (empty = complete).
     */
    private function missingPriorParts(object $app): array
    {
        $missing = [];
        $labels = [1 => 'Personal Detail', 2 => 'Address & Communication', 3 => 'Educational Detail', 4 => 'TC & Migration Detail', 6 => 'Subject & Paper Selection'];
        foreach ($labels as $n => $label) {
            $col = "part_{$n}";
            if (empty($app->$col)) {
                $missing[] = $label;
            }
        }

        $p5 = is_array($app->part_5 ?? null) ? $app->part_5 : ($app->part_5 ? json_decode($app->part_5, true) : []);
        $bankComplete = ($p5['has_bank_account'] ?? null) === 'no'
            || (($p5['has_bank_account'] ?? null) === 'yes'
                && trim((string) ($p5['name_in_account'] ?? '')) !== ''
                && trim((string) ($p5['bank_name'] ?? '')) !== ''
                && trim((string) ($p5['bank_branch'] ?? '')) !== ''
                && trim((string) ($p5['bank_account_no'] ?? '')) !== ''
                && trim((string) ($p5['bank_ifsc'] ?? '')) !== ''
                && trim((string) ($p5['branch_address'] ?? '')) !== '');
        if (!$bankComplete) {
            $missing[] = 'Bank Detail';
        }

        $requiredDocs = $this->buildRequiredDocuments($app);
        $docsComplete = collect($requiredDocs)->where('importance', 'important')->every(fn($d) => $d['uploaded']);
        if (!$docsComplete) {
            $missing[] = 'Required Documents';
        }

        return $missing;
    }

    private function buildRequiredDocuments(object $sa): array
    {
        $admissionMode = $sa->application_type === 'back_paper' ? 'Back Paper' : 'Regular';

        $masterRows = DB::table('enclosure_masters as em')
            ->join('enclosure_types as et', 'et.id', 'em.enclosure_type_id')
            ->where('em.program_id', $sa->program_id)
            ->where('em.semester_no', (string) $sa->semester_no)
            ->where('em.admission_mode', $admissionMode)
            ->where('em.scan_copy', true)
            ->where('et.is_active', true)
            ->orderBy('et.sort_order')
            ->get(['em.condition', 'et.key', 'et.name as document_name']);

        // part_1/part_4 arrive as a raw JSON string from every direct DB::table
        // caller of this method (studentRequiredDocuments()/requiredDocumentsOffice()),
        // but parseApp() below calls this AFTER already json_decode-ing every
        // part_N column into an array — json_decode() on a non-string throws
        // a TypeError in PHP 8, so both shapes have to be accepted here.
        $part1 = is_array($sa->part_1 ?? null) ? $sa->part_1 : ($sa->part_1 ? json_decode($sa->part_1, true) : []);
        $part4 = is_array($sa->part_4 ?? null) ? $sa->part_4 : ($sa->part_4 ? json_decode($sa->part_4, true) : []);

        // "did the student fill the related detail in the application form" —
        // keyword-matched against the admin-typed document_name since Master
        // Settings has no formal link to a specific part/field.
        $isFilled = function (string $name) use ($part1, $part4): bool {
            $n = strtolower($name);
            if (str_contains($n, 'migration'))
                return !empty($part4['has_migration']);
            if (str_contains($n, 'tc') || str_contains($n, 'transfer certificate'))
                return ($part4['tc_condition'] ?? null) === 'have_original_tc';
            if (str_contains($n, 'caste'))
                return !empty($part1['caste_cert_no']);
            if (str_contains($n, 'domicile'))
                return !empty($part1['domicile_cert_no']);
            if (str_contains($n, 'income'))
                return !empty($part1['income_cert_no']);
            if (str_contains($n, 'aadhar'))
                return !empty($part1['aadhar_no']);
            return false;
        };

        $rows = [
            ['key' => 'photo', 'label' => 'Applicant Photo Upload', 'accept' => 'image/*', 'importance' => 'important'],
            ['key' => 'signature', 'label' => 'Applicant Signature Upload', 'accept' => 'image/*', 'importance' => 'important'],
        ];

        foreach ($masterRows as $r) {
            $condition = $r->condition ?? '';
            if ($condition === 'Mandatory') {
                $importance = 'important';
            } elseif ($condition === 'Conditional') {
                $importance = $isFilled($r->document_name) ? 'important' : 'optional';
            } else {
                $importance = 'optional';
            }
            $rows[] = [
                'key' => $r->key,
                'label' => $r->document_name,
                'accept' => 'image/*,.pdf',
                'importance' => $importance,
            ];
        }

        // Upload status + enclosure number = real application_no + a running
        // sequence over this same fixed order (replaces the old client-side
        // "Appli.no.+01" placeholder computed from Object.keys().length).
        $docs = DB::table('student_application_documents')
            ->where('application_id', $sa->id)
            ->get()
            ->keyBy('document_type');

        $seq = 0;
        foreach ($rows as &$row) {
            $doc = $docs->get($row['key']);
            if ($doc) {
                $seq++;
                $url = null;
                try {
                    $url = Storage::disk('supabase')->url($doc->path);
                } catch (\Throwable $e) {
                    $url = Storage::disk('public')->url($doc->path);
                }
                $row['uploaded'] = true;
                $row['url'] = $url;
                $row['filename'] = $doc->filename;
                $row['enclosure_no'] = ($sa->application_no ?? $sa->id) . '-' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
            } else {
                $row['uploaded'] = false;
                $row['url'] = null;
                $row['filename'] = null;
                $row['enclosure_no'] = null;
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * GET /student/applications/{id}/documents/required
     */
    public function studentRequiredDocuments(Request $req, $id)
    {
        $sa = $this->ownedStudentApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse)
            return $sa;

        return response()->json(['data' => $this->buildRequiredDocuments($sa)]);
    }

    /**
     * GET /applications/{id}/documents/required  (office)
     */
    public function requiredDocumentsOffice($id)
    {
        $sa = DB::table('student_applications')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$sa)
            return response()->json(['message' => 'Application not found.'], 404);

        return response()->json(['data' => $this->buildRequiredDocuments($sa)]);
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

        $sa = $this->ownedStudentApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse)
            return $sa;
        $app = $sa;

        if (in_array($app->status, ['submitted', 'approved'])) {
            return response()->json(['message' => 'Cannot upload to a submitted application.'], 422);
        }

        $docType = $req->input('document_type');
        // Supabase Storage (S3-compatible) — see config/filesystems.php's
        // 'supabase' disk. Replaces local disk so files don't depend on this
        // server's own filesystem / storage:link symlink.
        $path = $req->file('file')->store("student-applications/{$id}", 'supabase');

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
            'url' => Storage::disk('supabase')->url($path),
            'filename' => $req->file('file')->getClientOriginalName(),
        ]);
    }

    /**
     * POST /applications/{id}/documents  (office)
     * Same as uploadStudentDocument() above but for office staff acting on a
     * student's behalf (e.g. TC / marksheet upload on the semester-upgrade
     * forms)
     */
    public function uploadStudentDocumentOffice(Request $req, $id)
    {
        $req->validate([
            'file' => 'required|file|max:2048|mimes:jpg,jpeg,png,pdf',
            'document_type' => 'required|string|max:60',
        ]);

        $app = DB::table('student_applications')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$app)
            return response()->json(['message' => 'Application not found.'], 404);

        $docType = $req->input('document_type');
        $path = $req->file('file')->store("student-applications/{$id}", 'supabase');

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
            'url' => Storage::disk('supabase')->url($path),
            'filename' => $req->file('file')->getClientOriginalName(),
        ]);
    }

    public function deleteStudentDocument(Request $req, $id, $documentType)
    {
        $sa = $this->ownedStudentApp($req, $id);
        if ($sa instanceof \Illuminate\Http\JsonResponse)
            return $sa;
        $app = $sa;

        if (in_array($app->status, ['submitted', 'approved'])) {
            return response()->json(['message' => 'Cannot modify documents on a submitted application.'], 422);
        }

        $doc = DB::table('student_application_documents')
            ->where('application_id', $id)
            ->where('document_type', $documentType)
            ->first();

        if (!$doc)
            return response()->json(['message' => 'Document not found.'], 404);

        if ($doc->path) {
            try {
                Storage::disk('supabase')->delete($doc->path);
            } catch (\Throwable $e) {
            }
            Storage::disk('public')->delete($doc->path);
        }
        DB::table('student_application_documents')->where('id', $doc->id)->delete();

        return response()->json(['message' => 'Document removed.']);
    }

    /**
     * DELETE /applications/{id}/documents/{document_type}  (office)
     * Same as deleteStudentDocument() above but for office staff — no
     * ownership check, no submitted/approved lock, mirrors
     * uploadStudentDocumentOffice() vs uploadStudentDocument().
     */
    public function deleteStudentDocumentOffice(Request $req, $id, $documentType)
    {
        $app = DB::table('student_applications')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$app)
            return response()->json(['message' => 'Application not found.'], 404);

        $doc = DB::table('student_application_documents')
            ->where('application_id', $id)
            ->where('document_type', $documentType)
            ->first();

        if (!$doc)
            return response()->json(['message' => 'Document not found.'], 404);

        if ($doc->path) {
            try {
                Storage::disk('supabase')->delete($doc->path);
            } catch (\Throwable $e) {
            }
            Storage::disk('public')->delete($doc->path);
        }
        DB::table('student_application_documents')->where('id', $doc->id)->delete();

        return response()->json(['message' => 'Document removed.']);
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
        $type = $req->query('type', 'regular');

        $regRows = DB::table('direct_registrations')
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id);
                if (!empty($user->mobile))
                    $q->orWhere('mobile', $user->mobile);
            })
            ->where('session_year', $session)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->get();

        if ($regRows->isEmpty()) {
            return response()->json([
                'data' => [],
                'session_year' => $session,
                'message' => 'No registration found for the current session. Please register first.',
            ]);
        }

        if ($type === 'regular') {
            $programIds = $regRows->pluck('program_id')->filter()->unique()->values()->all();

            $programs = DB::table('programs')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->whereIn('id', $programIds)
                ->select('id', 'name', 'short_name', 'level', 'duration_years')
                ->orderBy('name')
                ->get();

            return response()->json([
                'data' => $programs,
                'session_year' => $session,
            ]);
        }
        $regTypes = $regRows->pluck('reg_type')->map(fn($t) => strtoupper((string) $t))->unique()->all();
        $levelMap = ['UG' => 'UG', 'PG' => 'PG', 'BED' => 'BEd', 'BEED' => 'BEd'];
        $levels = array_values(array_unique(array_map(fn($t) => $levelMap[$t] ?? $t, $regTypes)));

        $programs = DB::table('programs')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereIn('level', $levels)
            ->select('id', 'name', 'short_name', 'level', 'duration_years')
            ->orderBy('level')->orderBy('name')
            ->get();

        return response()->json([
            'data' => $programs,
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

        $row->name = $reg->name ?? null;
        $row->father_name = $reg->father_name ?? null;
        $row->mother_name = $reg->mother_name ?? null;
        $row->dob = $row->date_of_birth ?? ($reg->dob ?? null);

        return response()->json($row);
    }
}
