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

        if ($request->filled('status'))          $query->where('status', $request->status);
        if ($request->filled('program_id'))      $query->where('program_id', $request->program_id);
        if ($request->filled('academic_year'))   $query->where('academic_year', $request->academic_year);
        if ($request->filled('semester_no'))     $query->where('semester_no', $request->semester_no);
        if ($request->filled('admission_type'))  $query->where('admission_type', $request->admission_type);
        if ($request->filled('is_verified'))     $query->where('is_verified', $request->boolean('is_verified'));
        if ($request->filled('search')) {
            $q = $request->search;
            $query->whereHas('student', fn($w) =>
                $w->where('first_name', 'ilike', "%{$q}%")
                  ->orWhere('last_name', 'ilike', "%{$q}%")
                  ->orWhere('enrollment_no', 'ilike', "%{$q}%")
                  ->orWhere('mobile', 'like', "%{$q}%")
            )->orWhere('admission_no', 'ilike', "%{$q}%");
        }

        return response()->json(
            $query->orderBy('admission_date', 'desc')
                  ->paginate($request->get('per_page', 20))
        );
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

        $admission->update([
            'is_verified' => true,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ]);

        // Generate enrollment number if not set
        $student = $admission->student;
        if (!$student->enrollment_no) {
            $year  = now()->format('Y');
            $count = Student::where('organization_id', $admission->organization_id)
                            ->whereNotNull('enrollment_no')->count() + 1;
            $student->update([
                'enrollment_no' => 'SDPG-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT),
            ]);
        }

        return response()->json([
            'message'        => 'Admission verified successfully.',
            'enrollment_no'  => $student->enrollment_no,
        ]);
    }

    public function cancel(Request $request, Admission $admission): JsonResponse
    {
        $request->validate(['cancel_reason' => 'required|string|max:500']);

        if ($admission->status === 'cancelled') {
            return response()->json(['message' => 'Admission is already cancelled.'], 422);
        }

        $admission->update([
            'status'       => 'cancelled',
            'cancel_reason'=> $request->cancel_reason,
            'cancel_date'  => now()->toDateString(),
            'cancelled_by' => $request->user()->id,
        ]);

        // Update student status
        $admission->student->update(['status' => 'cancelled']);

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

        if ($request->filled('program_id'))    $query->where('program_id', $request->program_id);
        if ($request->filled('academic_year')) $query->where('academic_year', $request->academic_year);

        return response()->json($query->paginate($request->get('per_page', 20)));
    }

    public function upgrade(Request $request, Admission $admission): JsonResponse
    {
        $request->validate([
            'new_semester_no'  => 'required|integer|min:1|max:12',
            'new_academic_year'=> 'required|string',
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
            'student_id'      => $admission->student_id,
            'program_id'      => $admission->program_id,
            'application_id'  => $admission->application_id,
            'academic_year'   => $request->new_academic_year,
            'semester_no'     => $request->new_semester_no,
            'admission_type'  => 'upgrade',
            'admission_no'    => Admission::generateAdmissionNo($admission->organization_id, $request->new_academic_year),
            'admission_date'  => now()->toDateString(),
            'status'          => 'active',
        ]);

        return response()->json([
            'message'      => "Admission upgraded to Semester {$request->new_semester_no}.",
            'new_admission'=> $newAdmission->load('program'),
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
            $query->where(fn($w) =>
                $w->where('first_name', 'ilike', "%{$q}%")
                  ->orWhere('enrollment_no', 'ilike', "%{$q}%")
                  ->orWhere('mobile', 'like', "%{$q}%")
            );
        }

        return response()->json($query->select([
            'id', 'enrollment_no', 'first_name', 'middle_name', 'last_name',
            'mobile', 'photo_path', 'biometric_id', 'aadhar_no', 'status',
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

        $query = \App\Models\FeeStructure::with(['program', 'feeHead'])
            ->where('organization_id', $orgId);

        if ($request->filled('program_id'))    $query->where('program_id', $request->program_id);
        if ($request->filled('academic_year')) $query->where('academic_year', $request->academic_year);
        if ($request->filled('semester_no'))   $query->where('semester_no', $request->semester_no);

        $structures = $query->orderBy('program_id')->orderBy('semester_no')->get();

        // Group by program → semester
        $grouped = $structures->groupBy('program_id')->map(function ($items) {
            return [
                'program'   => $items->first()->program,
                'semesters' => $items->groupBy('semester_no')->map(fn($s) => [
                    'total'  => $s->sum('amount'),
                    'heads'  => $s->map(fn($h) => [
                        'fee_head' => $h->feeHead->name,
                        'amount'   => $h->amount,
                        'type'     => $h->admission_type,
                    ]),
                ]),
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

        $receipts   = $student->feeReceipts;
        $totalPaid  = $receipts->sum('net_amount');
        $byType     = $receipts->groupBy('receipt_type')
                               ->map(fn($r) => $r->sum('net_amount'));

        return response()->json([
            'student'    => $student->only(['id','full_name','enrollment_no','mobile','photo_path']),
            'admission'  => $student->currentAdmission,
            'receipts'   => $receipts,
            'summary'    => [
                'total_paid'    => $totalPaid,
                'by_type'       => $byType,
                'receipt_count' => $receipts->count(),
            ],
        ]);
    }

    // ── Statistics ────────────────────────────────────────────────────────

    public function statistics(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $year  = $request->get('academic_year');

        $query = Admission::where('organization_id', $orgId);
        if ($year) $query->where('academic_year', $year);

        return response()->json([
            'total'              => $query->count(),
            'by_status'          => (clone $query)->selectRaw('status, count(*) as count')
                                        ->groupBy('status')->pluck('count', 'status'),
            'by_program'         => (clone $query)->with('program:id,short_name,level')
                                        ->selectRaw('program_id, count(*) as count')
                                        ->groupBy('program_id')->get()
                                        ->map(fn($a) => ['program' => $a->program?->short_name, 'count' => $a->count]),
            'by_semester'        => (clone $query)->selectRaw('semester_no, count(*) as count')
                                        ->groupBy('semester_no')->orderBy('semester_no')->pluck('count', 'semester_no'),
            'by_admission_type'  => (clone $query)->selectRaw('admission_type, count(*) as count')
                                        ->groupBy('admission_type')->pluck('count', 'admission_type'),
            'by_month'           => (clone $query)->selectRaw("to_char(admission_date,'Mon YYYY') as month, count(*) as count")
                                        ->groupBy('month')->orderBy('month')->pluck('count', 'month'),
            'verified_count'     => (clone $query)->where('is_verified', true)->count(),
            'unverified_count'   => (clone $query)->where('is_verified', false)->count(),
        ]);
    }

    public function subjectStatistics(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $year  = $request->get('academic_year');

        // Pull enrolled subjects from applications
        $apps = \App\Models\StudentApplication::with(['program:id,short_name', 'student:id,full_name,enrollment_no'])
            ->where('organization_id', $orgId)
            ->where('status', 'approved')
            ->when($year, fn($q) => $q->where('academic_year', $year))
            ->get();

        // Count subjects selected across all applications
        $subjectCounts = [];
        foreach ($apps as $app) {
            $subjects = array_merge(
                $app->selected_subjects ?? [],
                $app->selected_optional_subjects ?? []
            );
            foreach ($subjects as $subId) {
                $subjectCounts[$subId] = ($subjectCounts[$subId] ?? 0) + 1;
            }
        }

        // Fetch subject names
        $subjectIds = array_keys($subjectCounts);
        $subjects   = \App\Models\Subject::whereIn('id', $subjectIds)
                         ->with('program:id,short_name')
                         ->get()
                         ->keyBy('id');

        $result = collect($subjectCounts)->map(fn($count, $id) => [
            'subject_id'   => $id,
            'subject_name' => $subjects[$id]?->name ?? 'Unknown',
            'subject_code' => $subjects[$id]?->code ?? '',
            'program'      => $subjects[$id]?->program?->short_name ?? '',
            'type'         => $subjects[$id]?->type ?? '',
            'count'        => $count,
        ])->sortByDesc('count')->values();

        return response()->json([
            'total_enrolled' => $apps->count(),
            'subjects'       => $result,
        ]);
    }

    // University view
    public function universityView(Request $request): JsonResponse
    {
        return response()->json(
            Admission::with(['student:id,full_name,enrollment_no,mobile', 'program:id,name,level', 'organization:id,name'])
                ->where('status', 'active')
                ->paginate(20)
        );
    }
}
