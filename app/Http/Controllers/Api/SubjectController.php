<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Subject::with('program:id,short_name,level')
            ->whereHas('program', fn($q) => $q->where('organization_id', $request->user()->organization_id));

        if ($request->filled('program_id'))  $query->where('program_id', $request->program_id);
        if ($request->filled('semester_no')) $query->where('semester_no', $request->semester_no);
        if ($request->filled('type'))        $query->where('type', $request->type);

        return response()->json($query->orderBy('semester_no')->orderBy('type')->orderBy('name')->get());
    }

    // Used in application form Part 6
    public function byProgram(Request $request, Program $program): JsonResponse
    {
        $query = Subject::where('program_id', $program->id)->where('is_active', true);

        if ($request->filled('semester_no')) $query->where('semester_no', $request->semester_no);

        return response()->json($query->orderBy('type')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id'     => 'required|exists:programs,id',
            'name'           => 'required|string|max:200',
            'code'           => 'required|string|max:30|unique:subjects,code',
            'semester_no'    => 'required|integer|min:1|max:12',
            'type'           => 'required|in:compulsory,optional,elective,practical,project',
            'paper_type'     => 'required|in:regular,back_paper',
            'max_marks'      => 'required|integer|min:0',
            'min_marks'      => 'required|integer|min:0',
            'internal_marks' => 'nullable|integer|min:0',
            'credits'        => 'required|integer|min:0',
        ]);

        $validated['is_active'] = true;
        $subject = Subject::create($validated);
        return response()->json($subject->load('program:id,short_name'), 201);
    }

    public function update(Request $request, Subject $subject): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'sometimes|string|max:200',
            'semester_no'    => 'sometimes|integer|min:1|max:12',
            'type'           => 'sometimes|in:compulsory,optional,elective,practical,project',
            'max_marks'      => 'sometimes|integer|min:0',
            'min_marks'      => 'sometimes|integer|min:0',
            'internal_marks' => 'nullable|integer|min:0',
            'credits'        => 'sometimes|integer|min:0',
            'is_active'      => 'sometimes|boolean',
        ]);

        $subject->update($validated);
        return response()->json($subject);
    }

    public function destroy(Subject $subject): JsonResponse
    {
        $subject->delete();
        return response()->json(['message' => 'Subject deleted.']);
    }
}
