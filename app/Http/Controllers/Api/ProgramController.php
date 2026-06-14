<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'name'             => 'required|string|max:200',
            'short_name'       => 'required|string|max:20',
            'code'             => 'required|string|max:20|unique:programs,code',
            'level'            => 'required|in:UG,PG,BEd,Diploma,Certificate',
            'duration_years'   => 'required|integer|min:1|max:6',
            'total_semesters'  => 'required|integer|min:1|max:12',
            'semester_type'    => 'required|in:semester,annual',
            'description'      => 'nullable|string',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_active']       = true;

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
            'name'            => 'sometimes|string|max:200',
            'short_name'      => 'sometimes|string|max:20',
            'level'           => 'sometimes|in:UG,PG,BEd,Diploma,Certificate',
            'duration_years'  => 'sometimes|integer|min:1|max:6',
            'total_semesters' => 'sometimes|integer|min:1|max:12',
            'semester_type'   => 'sometimes|in:semester,annual',
            'description'     => 'nullable|string',
            'is_active'       => 'sometimes|boolean',
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
}
