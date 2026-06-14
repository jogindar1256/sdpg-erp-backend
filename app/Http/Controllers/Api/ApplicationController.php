<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentApplication;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApplicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StudentApplication::with(['student', 'program'])
            ->where('organization_id', $request->user()->organization_id);

        if ($request->filled('status'))           $query->where('status', $request->status);
        if ($request->filled('academic_year'))    $query->where('academic_year', $request->academic_year);
        if ($request->filled('application_type')) $query->where('application_type', $request->application_type);
        if ($request->filled('program_id'))       $query->where('program_id', $request->program_id);
        if ($request->filled('search')) {
            $q = $request->search;
            $query->whereHas('student', fn($w) =>
                $w->where('first_name', 'ilike', "%{$q}%")
                  ->orWhere('mobile', 'like', "%{$q}%")
                  ->orWhere('enrollment_no', 'ilike', "%{$q}%")
            )->orWhere('application_no', 'ilike', "%{$q}%");
        }

        return response()->json(
            $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 20))
        );
    }

    public function show(StudentApplication $application): JsonResponse
    {
        $application->load(['student.documents', 'program', 'reviewedBy', 'approvedBy', 'admission']);
        return response()->json($application);
    }

    /**
     * Student starts a new application (creates draft)
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'program_id'       => 'required|exists:programs,id',
            'academic_year'    => 'required|string',
            'application_type' => 'required|in:fresh,back_paper,semester_upgrade,lateral',
            'semester_no'      => 'required|integer|min:1|max:12',
        ]);

        $student = $request->user()->student;

        // Prevent duplicate application
        $existing = StudentApplication::where('student_id', $student->id)
            ->where('academic_year', $request->academic_year)
            ->where('application_type', $request->application_type)
            ->where('semester_no', $request->semester_no)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->first();

        if ($existing) {
            return response()->json([
                'message'     => 'You already have an active application for this semester.',
                'application' => $existing,
            ], 422);
        }

        $application = StudentApplication::create([
            'organization_id'  => $student->organization_id,
            'student_id'       => $student->id,
            'program_id'       => $request->program_id,
            'academic_year'    => $request->academic_year,
            'application_no'   => StudentApplication::generateApplicationNo($request->academic_year, $student->organization_id),
            'application_type' => $request->application_type,
            'semester_no'      => $request->semester_no,
            'status'           => 'draft',
            'form_progress'    => [],
        ]);

        return response()->json($application, 201);
    }

    /**
     * Update specific form part (1-8)
     */
    public function updatePart(Request $request, StudentApplication $application, int $part): JsonResponse
    {
        $this->authorize('update', $application);

        if (!in_array($application->status, ['draft', 'submitted'])) {
            return response()->json(['message' => 'This application cannot be edited.'], 422);
        }

        $dataMap = [
            1 => $this->validatePart1($request),
            2 => $this->validatePart2($request),
            6 => $this->validatePart6($request),  // Subjects
            7 => $this->validatePart7($request),  // Documents
            8 => $this->validatePart8($request),  // Declaration
        ];

        $updateData = $dataMap[$part] ?? $request->validated();
        $progress = $application->form_progress ?? [];
        $progress["part{$part}"] = true;

        $application->update(array_merge($updateData, ['form_progress' => $progress]));

        return response()->json(['message' => "Part {$part} saved.", 'application' => $application]);
    }

    /**
     * Student submits the application
     */
    public function submit(StudentApplication $application): JsonResponse
    {
        $this->authorize('update', $application);

        if (!$application->isFormComplete()) {
            return response()->json(['message' => 'Please complete all parts of the application form.'], 422);
        }

        $application->update([
            'status'       => 'submitted',
            'declaration_at' => now(),
        ]);

        return response()->json(['message' => 'Application submitted successfully.', 'application' => $application]);
    }

    /**
     * Office: Approve application → triggers Admission creation
     */
    public function approve(Request $request, StudentApplication $application): JsonResponse
    {
        $request->validate(['remarks' => 'nullable|string']);

        if ($application->status !== 'submitted' && $application->status !== 'under_review') {
            return response()->json(['message' => 'Only submitted applications can be approved.'], 422);
        }

        DB::transaction(function () use ($request, $application) {
            $application->update([
                'status'      => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'remarks'     => $request->remarks,
            ]);

            // Auto-create admission on approval
            \App\Models\Admission::create([
                'organization_id' => $application->organization_id,
                'student_id'      => $application->student_id,
                'program_id'      => $application->program_id,
                'application_id'  => $application->id,
                'academic_year'   => $application->academic_year,
                'semester_no'     => $application->semester_no,
                'admission_type'  => $application->application_type,
                'admission_no'    => \App\Models\Admission::generateAdmissionNo($application->organization_id, $application->academic_year),
                'admission_date'  => now()->toDateString(),
                'status'          => 'active',
            ]);

            // Update enrollment number if not set
            $student = $application->student;
            if (!$student->enrollment_no) {
                $year  = now()->format('Y');
                $count = Student::where('organization_id', $application->organization_id)->count();
                $student->update(['enrollment_no' => "SDPG-{$year}-" . str_pad($count, 5, '0', STR_PAD_LEFT)]);
            }
        });

        return response()->json(['message' => 'Application approved and admission created.']);
    }

    /**
     * Office: Reject application
     */
    public function reject(Request $request, StudentApplication $application): JsonResponse
    {
        $request->validate(['rejection_reason' => 'required|string|max:500']);

        $application->update([
            'status'           => 'rejected',
            'rejection_reason' => $request->rejection_reason,
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
        ]);

        return response()->json(['message' => 'Application rejected.']);
    }

    public function statistics(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $year  = $request->get('academic_year', now()->year . '-' . (now()->year + 1 - 2000));

        return response()->json([
            'by_status'  => StudentApplication::where('organization_id', $orgId)
                ->where('academic_year', $year)
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')->pluck('count', 'status'),
            'by_program' => StudentApplication::where('organization_id', $orgId)
                ->where('academic_year', $year)
                ->with('program:id,short_name')
                ->selectRaw('program_id, count(*) as count')
                ->groupBy('program_id')->get(),
            'by_type'    => StudentApplication::where('organization_id', $orgId)
                ->where('academic_year', $year)
                ->selectRaw('application_type, count(*) as count')
                ->groupBy('application_type')->pluck('count', 'application_type'),
        ]);
    }

    // ─── Validation helpers ───────────────────────────────────────────────

    private function validatePart1(Request $request): array
    {
        return $request->validate([
            'first_name'    => 'required|string',
            'middle_name'   => 'nullable|string',
            'last_name'     => 'required|string',
            'gender'        => 'required|in:male,female,other',
            'date_of_birth' => 'required|date',
            'category'      => 'required|string',
            'religion'      => 'nullable|string',
        ]);
    }

    private function validatePart2(Request $request): array
    {
        return $request->validate([
            'permanent_address'  => 'required|string',
            'permanent_city'     => 'required|string',
            'permanent_district' => 'required|string',
            'permanent_state'    => 'required|string',
            'permanent_pin'      => 'required|string|size:6',
            'mobile'             => 'required|string|size:10',
            'email'              => 'nullable|email',
        ]);
    }

    private function validatePart6(Request $request): array
    {
        return $request->validate([
            'selected_subjects'          => 'required|array|min:1',
            'selected_subjects.*'        => 'integer|exists:subjects,id',
            'selected_optional_subjects' => 'nullable|array',
        ]);
    }

    private function validatePart7(Request $request): array
    {
        // Documents handled via separate upload endpoint
        return $request->validate(['documents_confirmed' => 'required|boolean']);
    }

    private function validatePart8(Request $request): array
    {
        return $request->validate([
            'declaration_accepted' => 'required|boolean|accepted',
        ]);
    }
}
