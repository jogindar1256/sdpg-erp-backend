<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Student;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Admission::with(['student', 'program', 'application'])
            ->where('organization_id', $request->user()->organization_id);

        if ($request->filled('status'))
            $query->where('status', $request->status);
        if ($request->filled('program_id'))
            $query->where('program_id', $request->program_id);
        if ($request->filled('academic_year'))
            $query->where('academic_year', $request->academic_year);
        if ($request->filled('semester_no'))
            $query->where('semester_no', $request->semester_no);
        if ($request->filled('admission_type'))
            $query->where('admission_type', $request->admission_type);
        if ($request->filled('is_verified'))
            $query->where('is_verified', $request->boolean('is_verified'));
        if ($request->filled('search')) {
            $q = $request->search;
            // Grouped, so the OR branches stay inside this college's rows.
            // applicant_info covers pending admissions, which have no
            // students row to search yet.
            $query->where(function ($outer) use ($q) {
                $outer->whereHas(
                    'student',
                    fn($w) =>
                        $w->where('personal_info->first_name', 'ilike', "%{$q}%")
                            ->orWhere('personal_info->last_name', 'ilike', "%{$q}%")
                            ->orWhere('enrollment_no', 'ilike', "%{$q}%")
                            ->orWhere('mobile', 'like', "%{$q}%")
                )
                    ->orWhere('admission_no', 'ilike', "%{$q}%")
                    ->orWhere('applicant_info->name', 'ilike', "%{$q}%")
                    ->orWhere('applicant_info->mobile', 'like', "%{$q}%")
                    ->orWhere('applicant_info->application_no', 'ilike', "%{$q}%");
            });
        }

        $page = $query->orderBy('admission_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($request->get('per_page', 20));

        // The application view/edit pages need the registration's unique
        // code in the URL (ApplicationController::rejectIfCodeInvalid()).
        $regIds = $page->getCollection()
            ->map(fn($a) => $a->direct_registration_id ?: ($a->application->direct_registration_id ?? null))
            ->filter()->unique()->values()->all();
        $codes = $regIds
            ? DB::table('direct_registrations')->whereIn('id', $regIds)->pluck('unique_code', 'id')
            : collect();
        $page->getCollection()->each(function ($a) use ($codes) {
            $regId = $a->direct_registration_id ?: ($a->application->direct_registration_id ?? null);
            $a->setAttribute('code', $regId ? ($codes[$regId] ?? null) : null);
        });

        return response()->json($page);
    }

    /**
     * GET /admissions/pipeline-summary
     * The six admission-pipeline counters shown above the Manage Admission
     * list. Follows the Session / Program / Semester filters only — not
     * Status or Search, so the counters describe the whole pipeline.
     *
     * Definitions match Registration Status (RegistrationController):
     *   registration          direct_registrations.status = 'registered'
     *   complete_fill         application submitted (any status except draft)
     *   approved_application  application status = 'approved'
     *   paid_fee              admission payment_status = 'paid'
     *   student_id_generated  admitted students that have a student_uid
     *   admission_cancel      admission status = 'cancelled'
     * Semester does not apply to registrations (a registration has none).
     */
    public function pipelineSummary(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $year = $request->input('academic_year');
        $programId = $request->input('program_id');
        $semester = $request->input('semester_no');

        $registration = DB::table('direct_registrations')
            ->whereNull('deleted_at')
            ->where('status', 'registered')
            ->when($year, fn($q) => $q->where('session_year', $year))
            ->when($programId, fn($q) => $q->where('program_id', $programId))
            ->count();

        $applications = fn() => DB::table('student_applications')
            ->whereNull('deleted_at')
            ->where('organization_id', $orgId)
            ->when($year, fn($q) => $q->where('academic_year', $year))
            ->when($programId, fn($q) => $q->where('program_id', $programId))
            ->when($semester, fn($q) => $q->where('semester_no', $semester));

        $admissions = fn() => DB::table('admissions as a')
            ->where('a.organization_id', $orgId)
            ->when($year, fn($q) => $q->where('a.academic_year', $year))
            ->when($programId, fn($q) => $q->where('a.program_id', $programId))
            ->when($semester, fn($q) => $q->where('a.semester_no', $semester));

        return response()->json([
            'registration' => $registration,
            'complete_fill' => $applications()->where('status', '!=', 'draft')->count(),
            'approved_application' => $applications()->where('status', 'approved')->count(),
            'paid_fee' => $admissions()->where('a.payment_status', 'paid')->count(),
            'student_id_generated' => $admissions()
                ->join('students as s', 's.id', 'a.student_id')
                ->whereNotNull('s.student_uid')
                ->where('s.student_uid', '!=', '')
                ->distinct()
                ->count('a.student_id'),
            'admission_cancel' => $admissions()->where('a.status', 'cancelled')->count(),
        ]);
    }

    public function show(Admission $admission): JsonResponse
    {
        $admission->load([
            'student.documents',
            'program',
            'application.selectedSubjectsData',
            'feeReceipts',
            'semesterRegistrations',
        ]);
        return response()->json($admission);
    }

    public function verify(Request $request, Admission $admission): JsonResponse
    {
        $this->authorize('verify-admissions');

        if ($admission->is_verified) {
            return response()->json(['message' => 'Admission is already verified.'], 422);
        }
        if (!$admission->student_id) {
            return response()->json([
                'message' => 'This admission has no student record yet. It is confirmed when the fee receipt is verified.',
            ], 422);
        }

        $admission->update([
            'is_verified' => true,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ]);

        // Generate enrollment number if not set
        $student = $admission->student;
        if (!$student->enrollment_no) {
            $year = now()->format('Y');
            $count = Student::where('organization_id', $admission->organization_id)
                ->whereNotNull('enrollment_no')->count() + 1;
            $student->update([
                'enrollment_no' => 'SDPG-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT),
            ]);
        }

        return response()->json([
            'message' => 'Admission verified successfully.',
            'enrollment_no' => $student->enrollment_no,
        ]);
    }

    public function cancel(Request $request, Admission $admission): JsonResponse
    {
        $request->validate(['cancel_reason' => 'required|string|max:500']);

        if ($admission->status === 'cancelled') {
            return response()->json(['message' => 'Admission is already cancelled.'], 422);
        }

        $admission->update([
            'status' => 'cancelled',
            'cancel_reason' => $request->cancel_reason,
            'cancel_date' => now()->toDateString(),
            'cancelled_by' => $request->user()->id,
        ]);

        // Update student status
        $admission->student?->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Admission cancelled.']);
    }

    // ── Semester Upgrade ──────────────────────────────────────────────────

    public function upgradeList(Request $request): JsonResponse
    {
        // Students eligible for next semester upgrade
        $query = Admission::with(['student', 'program'])
            ->where('organization_id', $request->user()->organization_id)
            ->where('status', 'active')
            ->whereRaw('semester_no < (SELECT total_semesters FROM programs WHERE id = admissions.program_id)');

        if ($request->filled('program_id'))
            $query->where('program_id', $request->program_id);
        if ($request->filled('academic_year'))
            $query->where('academic_year', $request->academic_year);

        return response()->json($query->paginate($request->get('per_page', 20)));
    }

    public function upgrade(Request $request, Admission $admission): JsonResponse
    {
        $request->validate([
            'new_semester_no' => 'required|integer|exists:semester_masters,semester_num',
            'new_academic_year' => 'required|string',
        ]);

        $program = $admission->program;

        if ($request->new_semester_no > $program->total_semesters) {
            return response()->json(['message' => 'Semester exceeds program limit.'], 422);
        }

        // Check if already upgraded
        $exists = Admission::where('student_id', $admission->student_id)
            ->where('program_id', $admission->program_id)
            ->where('semester_no', $request->new_semester_no)
            ->where('academic_year', $request->new_academic_year)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Admission for this semester already exists.'], 422);
        }

        $newAdmission = Admission::create([
            'organization_id' => $admission->organization_id,
            'student_id' => $admission->student_id,
            'program_id' => $admission->program_id,
            'application_id' => $admission->application_id,
            'academic_year' => $request->new_academic_year,
            'semester_no' => $request->new_semester_no,
            'admission_type' => 'upgrade',
            'admission_no' => Admission::generateAdmissionNo($admission->organization_id, $request->new_academic_year),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        return response()->json([
            'message' => "Admission upgraded to Semester {$request->new_semester_no}.",
            'new_admission' => $newAdmission->load('program'),
        ], 201);
    }

    // ── Biometrics ────────────────────────────────────────────────────────

    public function biometrics(Request $request): JsonResponse
    {
        $query = Student::with(['currentAdmission.program'])
            ->where('organization_id', $request->user()->organization_id)
            ->where('status', 'active');

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(
                fn($w) =>
                    $w->where('personal_info->first_name', 'ilike', "%{$q}%")
                        ->orWhere('enrollment_no', 'ilike', "%{$q}%")
                        ->orWhere('mobile', 'like', "%{$q}%")
            );
        }

        return response()->json($query->select([
            'id',
            'enrollment_no',
            'personal_info', // first/middle/last name — flattened by Student::toArray()
            'mobile',
            'photo_path',
            'biometric_id',
            'aadhar_no',
            'status',
        ])->paginate($request->get('per_page', 20)));
    }

    public function updateBiometric(Request $request, Student $student): JsonResponse
    {
        $request->validate(['biometric_id' => 'required|string']);
        $student->update(['biometric_id' => $request->biometric_id]);
        return response()->json(['message' => 'Biometric ID updated.']);
    }

    // ── Education Fee ─────────────────────────────────────────────────────

    public function educationFee(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $query = \App\Models\FeeStructure::with('program')
            ->where('organization_id', $orgId);

        if ($request->filled('program_id'))
            $query->where('program_id', $request->program_id);
        if ($request->filled('academic_year'))
            $query->where('academic_year', $request->academic_year);
        if ($request->filled('semester_no'))
            $query->where('semester_no', $request->semester_no);

        $structures = $query->orderBy('program_id')->orderBy('semester_no')->get();

        $feeHeadNames = DB::table('fee_heads')->pluck('name', 'id');

        $grouped = $structures->groupBy('program_id')->map(function ($items) use ($feeHeadNames) {
            return [
                'program' => $items->first()->program,
                'semesters' => $items->groupBy('semester_no')->map(function ($configRows) use ($feeHeadNames) {
                    $combos = [];
                    foreach ($configRows as $row) {
                        $amountJson = (array) ($row->amount_json ?? []);
                        foreach ($amountJson as $gender => $heads) {
                            $headsOut = [];
                            $total = 0.0;
                            foreach ((array) $heads as $feeHeadId => $amount) {
                                if ((float) $amount <= 0)
                                    continue;
                                $headsOut[] = [
                                    'fee_head' => $feeHeadNames[$feeHeadId] ?? "Fee Head #{$feeHeadId}",
                                    'amount' => (float) $amount,
                                    'type' => $row->admission_type,
                                ];
                                $total += (float) $amount;
                            }
                            if (empty($headsOut)) {
                                continue;
                            }
                            $combos[] = [
                                'gender' => $gender,
                                'category' => $row->category,
                                'total' => $total,
                                'fee_ref_id' => $row->fee_ref_id,
                                'heads' => $headsOut,
                            ];
                        }
                    }
                    return $combos;
                }),
            ];
        })->values();

        return response()->json($grouped);
    }

    // ── Student Ledger ────────────────────────────────────────────────────

    public function ledger(Request $request): JsonResponse
    {
        $request->validate(['student_id' => 'required|exists:students,id']);

        $student = Student::with([
            'currentAdmission.program',
            'feeReceipts' => fn($q) => $q->where('status', 'active')->orderBy('receipt_date'),
        ])->findOrFail($request->student_id);

        $receipts = $student->feeReceipts;
        $totalPaid = $receipts->sum('net_amount');
        $byType = $receipts->groupBy('receipt_type')
            ->map(fn($r) => $r->sum('net_amount'));

        return response()->json([
            'student' => $student->only(['id', 'full_name', 'enrollment_no', 'mobile', 'photo_path']),
            'admission' => $student->currentAdmission,
            'receipts' => $receipts,
            'summary' => [
                'total_paid' => $totalPaid,
                'by_type' => $byType,
                'receipt_count' => $receipts->count(),
            ],
        ]);
    }

    // ── Statistics ────────────────────────────────────────────────────────

    public function statistics(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $year = $request->get('academic_year');

        $query = Admission::where('organization_id', $orgId);
        if ($year)
            $query->where('academic_year', $year);

        return response()->json([
            'total' => $query->count(),
            'by_status' => (clone $query)->selectRaw('status, count(*) as count')
                ->groupBy('status')->pluck('count', 'status'),
            'by_program' => (clone $query)->with('program:id,short_name,samarth_code,level')
                ->selectRaw('program_id, count(*) as count')
                ->groupBy('program_id')->get()
                ->map(fn($a) => ['program' => $a->program?->short_name, 'count' => $a->count]),
            'by_semester' => (clone $query)->selectRaw('semester_no, count(*) as count')
                ->groupBy('semester_no')->orderBy('semester_no')->pluck('count', 'semester_no'),
            'by_admission_type' => (clone $query)->selectRaw('admission_type, count(*) as count')
                ->groupBy('admission_type')->pluck('count', 'admission_type'),
            'by_month' => (clone $query)->selectRaw("to_char(admission_date,'Mon YYYY') as month, count(*) as count")
                ->groupBy('month')->orderBy('month')->pluck('count', 'month'),
            'verified_count' => (clone $query)->where('is_verified', true)->count(),
            'unverified_count' => (clone $query)->where('is_verified', false)->count(),
        ]);
    }

    public function subjectStatistics(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $year = $request->get('academic_year');

        // Pull enrolled subjects from applications
        $apps = \App\Models\StudentApplication::with(['program:id,short_name,samarth_code', 'student:id,full_name,enrollment_no'])
            ->where('organization_id', $orgId)
            ->where('status', 'approved')
            ->when($year, fn($q) => $q->where('academic_year', $year))
            ->get();

        $subjectCounts = [];
        foreach ($apps as $app) {
            $part6 = $app->part_6 ?? null;
            $part6 = is_string($part6) ? (json_decode($part6, true) ?? []) : ($part6 ?? []);
            $subjects = array_merge(
                $part6['selected_subjects'] ?? [],
                $part6['selected_optional_subjects'] ?? []
            );
            foreach ($subjects as $subId) {
                if (!is_numeric($subId)) {
                    continue;
                }
                $subjectCounts[$subId] = ($subjectCounts[$subId] ?? 0) + 1;
            }
        }

        // Fetch subject names
        $subjectIds = array_keys($subjectCounts);
        $subjects = \App\Models\Subject::whereIn('id', $subjectIds)
            ->with('program:id,short_name,samarth_code')
            ->get()
            ->keyBy('id');

        $result = collect($subjectCounts)->map(fn($count, $id) => [
            'subject_id' => $id,
            'subject_name' => $subjects[$id]?->name ?? 'Unknown',
            'subject_code' => $subjects[$id]?->code ?? '',
            'program' => $subjects[$id]?->program?->short_name ?? '',
            'type' => $subjects[$id]?->type ?? '',
            'count' => $count,
        ])->sortByDesc('count')->values();

        return response()->json([
            'total_enrolled' => $apps->count(),
            'subjects' => $result,
        ]);
    }
}
