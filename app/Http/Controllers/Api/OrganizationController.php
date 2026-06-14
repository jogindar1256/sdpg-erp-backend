<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OrganizationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $org = Organization::findOrFail($request->user()->organization_id);
        return response()->json($org);
    }

    public function update(Request $request): JsonResponse
    {
        $org = Organization::findOrFail($request->user()->organization_id);

        $validated = $request->validate([
            'name'            => 'sometimes|string|max:200',
            'short_name'      => 'nullable|string|max:50',
            'affiliation_no'  => 'nullable|string',
            'address'         => 'sometimes|string',
            'city'            => 'sometimes|string',
            'district'        => 'sometimes|string',
            'state'           => 'sometimes|string',
            'pin_code'        => 'sometimes|string|size:6',
            'phone'           => 'nullable|string',
            'mobile'          => 'nullable|string',
            'email'           => 'nullable|email',
            'website'         => 'nullable|url',
            'principal_name'  => 'nullable|string',
            'university_name' => 'nullable|string',
            'university_code' => 'nullable|string',
        ]);

        $org->update($validated);
        return response()->json($org);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate(['logo' => 'required|image|mimes:jpg,jpeg,png|max:1024']);

        $org = Organization::findOrFail($request->user()->organization_id);

        if ($org->logo_path) Storage::disk('public')->delete($org->logo_path);

        $path = $request->file('logo')->store("organizations/{$org->id}", 'public');
        $org->update(['logo_path' => $path]);

        return response()->json(['logo_url' => Storage::url($path)]);
    }

    // University portal — list all colleges
    public function list(): JsonResponse
    {
        return response()->json(Organization::where('type', 'college')->where('is_active', true)->get());
    }
}
