<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Subject;
use App\Models\SubjectPaper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProgramController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $programs = Program::where('organization_id', $request->user()->organization_id)
            ->withCount(['subjects', 'admissions'])
            ->orderBy('level')->orderBy('name')
            ->get();

        return response()->json($programs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'short_name' => 'required|string|max:20',
            'code' => 'required|string|max:20|unique:programs,code',
            'level' => 'required|in:UG,PG,BEd,Diploma,Certificate',
            'duration_years' => 'required|integer|min:1|max:6',
            'total_semesters' => 'required|integer|min:1|max:12',
            'semester_type' => 'required|in:semester,annual',
            'description' => 'nullable|string',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_active'] = true;

        $program = Program::create($validated);
        return response()->json($program, 201);
    }

    public function show(Program $program): JsonResponse
    {
        return response()->json($program->load('subjects'));
    }

    public function update(Request $request, Program $program): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:200',
            'short_name' => 'sometimes|string|max:20',
            'level' => 'sometimes|in:UG,PG,BEd,Diploma,Certificate',
            'duration_years' => 'sometimes|integer|min:1|max:6',
            'total_semesters' => 'sometimes|integer|min:1|max:12',
            'semester_type' => 'sometimes|in:semester,annual',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $program->update($validated);
        return response()->json($program);
    }

    public function destroy(Program $program): JsonResponse
    {
        if ($program->admissions()->count() > 0) {
            return response()->json(['message' => 'Cannot delete program with existing admissions.'], 422);
        }
        $program->delete();
        return response()->json(['message' => 'Program deleted.']);
    }

public function subjects(Request $request, $programId)
{
    $semesterNo = $request->query('semester');

    $subjects = Subject::where('program_id', $programId)
        ->where('is_active', true)
        ->when($semesterNo, fn($q) => $q->where('semester_no', (int) $semesterNo))
        ->select('id', 'name', 'code', 'type', 'paper_type', 'max_marks', 'min_marks', 'internal_marks', 'credits', 'semester_no')
        ->orderBy('type')
        ->orderBy('name')
        ->get();

    return response()->json([
        'core'     => $subjects->whereIn('type', ['compulsory', 'practical'])->values(),
        'optional' => $subjects->whereIn('type', ['optional', 'elective', 'project'])->values(),
        'all'      => $subjects,
    ]);
}

// Real paper code/name for a subject — settings/course/subject-papers,
// NOT the subject's own code/name column (a subject can have more than
// one paper under the DDU system). Grouped by subject_id so the frontend
// can show every paper under whichever subject was picked, or "Not
// configured" when nothing exists for that subject/semester yet — never a
// fabricated code.
public function subjectPapers(Request $request, $programId)
{
    $semesterNo = $request->query('semester');
    $sessionYear = $request->query('session_year');

    $papers = SubjectPaper::where('program_id', $programId)
        ->when($semesterNo, fn($q) => $q->where('semester_no', (string) $semesterNo))
        ->when($sessionYear, fn($q) => $q->where('session_year', $sessionYear))
        ->orderBy('subject_id')
        ->orderBy('paper_code')
        ->get(['id', 'subject_id', 'paper_code', 'paper_name', 'paper_type', 'session_year', 'semester_no', 'group_label', 'max_marks', 'min_marks']);

    $bySubject = $papers->groupBy('subject_id')->map(fn ($rows) => $rows->values());

    return response()->json(['by_subject' => $bySubject]);
}
}
