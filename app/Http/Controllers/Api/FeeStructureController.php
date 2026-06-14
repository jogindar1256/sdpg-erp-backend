<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeeStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeStructureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FeeStructure::with(['program:id,short_name,level', 'feeHead:id,name,category'])
            ->where('organization_id', $request->user()->organization_id);

        if ($request->filled('program_id'))    $query->where('program_id', $request->program_id);
        if ($request->filled('academic_year')) $query->where('academic_year', $request->academic_year);
        if ($request->filled('semester_no'))   $query->where('semester_no', $request->semester_no);
        if ($request->filled('admission_type'))$query->where('admission_type', $request->admission_type);

        return response()->json(
            $query->orderBy('program_id')->orderBy('semester_no')->orderBy('admission_type')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id'       => 'required|exists:programs,id',
            'fee_head_id'      => 'required|exists:fee_heads,id',
            'semester_no'      => 'required|integer|min:0|max:12',
            'academic_year'    => 'required|string',
            'admission_type'   => 'required|in:regular,back_paper,upgrade,lateral',
            'amount'           => 'required|numeric|min:0',
            'late_fine_per_day'=> 'nullable|numeric|min:0',
            'due_date'         => 'nullable|date',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_active']       = true;

        $structure = FeeStructure::create($validated);
        return response()->json($structure->load(['program:id,short_name', 'feeHead:id,name']), 201);
    }

    public function update(Request $request, FeeStructure $feeStructure): JsonResponse
    {
        $validated = $request->validate([
            'amount'           => 'sometimes|numeric|min:0',
            'late_fine_per_day'=> 'nullable|numeric|min:0',
            'due_date'         => 'nullable|date',
            'is_active'        => 'sometimes|boolean',
        ]);

        $feeStructure->update($validated);
        return response()->json($feeStructure);
    }

    public function destroy(FeeStructure $feeStructure): JsonResponse
    {
        $feeStructure->delete();
        return response()->json(['message' => 'Fee structure deleted.']);
    }

    // Bulk create — copy previous year's structure to new year
    public function copyFromYear(Request $request): JsonResponse
    {
        $request->validate([
            'from_year' => 'required|string',
            'to_year'   => 'required|string|different:from_year',
        ]);

        $orgId   = $request->user()->organization_id;
        $records = FeeStructure::where('organization_id', $orgId)
                               ->where('academic_year', $request->from_year)
                               ->get();

        if ($records->isEmpty()) {
            return response()->json(['message' => 'No fee structures found for the source year.'], 422);
        }

        $created = 0;
        foreach ($records as $record) {
            FeeStructure::firstOrCreate(
                [
                    'organization_id' => $orgId,
                    'program_id'      => $record->program_id,
                    'fee_head_id'     => $record->fee_head_id,
                    'semester_no'     => $record->semester_no,
                    'academic_year'   => $request->to_year,
                    'admission_type'  => $record->admission_type,
                ],
                [
                    'amount'            => $record->amount,
                    'late_fine_per_day' => $record->late_fine_per_day,
                    'is_active'         => true,
                ]
            );
            $created++;
        }

        return response()->json(['message' => "{$created} fee structures copied to {$request->to_year}."]);
    }
}
