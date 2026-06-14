<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeeHead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeHeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            FeeHead::where('organization_id', $request->user()->organization_id)
                ->orderBy('category')->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:100',
            'code'         => 'required|string|max:30|unique:fee_heads,code',
            'category'     => 'required|in:tuition,exam,library,hostel,transport,miscellaneous',
            'is_refundable'=> 'boolean',
            'is_mandatory' => 'boolean',
            'description'  => 'nullable|string',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_active']       = true;

        return response()->json(FeeHead::create($validated), 201);
    }

    public function update(Request $request, FeeHead $feeHead): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'sometimes|string|max:100',
            'category'     => 'sometimes|in:tuition,exam,library,hostel,transport,miscellaneous',
            'is_refundable'=> 'sometimes|boolean',
            'is_mandatory' => 'sometimes|boolean',
            'description'  => 'nullable|string',
            'is_active'    => 'sometimes|boolean',
        ]);

        $feeHead->update($validated);
        return response()->json($feeHead);
    }

    public function destroy(FeeHead $feeHead): JsonResponse
    {
        if ($feeHead->feeStructures()->count() > 0) {
            return response()->json(['message' => 'Cannot delete — fee head is used in fee structures.'], 422);
        }
        $feeHead->delete();
        return response()->json(['message' => 'Fee head deleted.']);
    }
}
