<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Support\OrgBrand;
use Illuminate\Validation\Rule;

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

        OrgBrand::flush();

        return response()->json(['logo_url' => OrgBrand::logoUrl($org)]);
    }

    /** GET organization/profile — the Institute Profile page. */
    public function profile(Request $request): JsonResponse
    {
        $org = Organization::findOrFail($request->user()->organization_id);
        return response()->json(['profile' => array_merge($org->toArray(), ['logo_url' => OrgBrand::logoUrl($org)])]);
    }

    /** PUT|POST organization/profile — multipart-safe (optional `logo` file). */
    public function updateProfile(Request $request): JsonResponse
    {
        $org = Organization::findOrFail($request->user()->organization_id);

        $v = $request->validate([
            'name'             => 'required|string|max:200',
            'short_name'       => 'nullable|string|max:50',
            'code'             => ['required', 'string', 'max:20', Rule::unique('organizations', 'code')->ignore($org->id)],
            'affiliation_no'   => 'nullable|string|max:100',
            'address'          => 'required|string|max:255',
            'city'             => 'required|string|max:100',
            'district'         => 'required|string|max:100',
            'state'            => 'required|string|max:100',
            'pin_code'         => ['required', 'regex:/^\d{6}$/'],
            'phone'            => 'nullable|string|max:30',
            'mobile'           => 'nullable|string|max:20',
            'email'            => 'nullable|email|max:150',
            'website'          => 'nullable|string|max:200',
            'principal_name'   => 'nullable|string|max:150',
            'principal_mobile' => 'nullable|string|max:20',
            'university_name'  => 'nullable|string|max:200',
            'university_code'  => 'nullable|string|max:50',
            'university_college_code' => 'nullable|string|max:20',
            'tagline'          => 'nullable|string|max:255',
            'established_year' => ['nullable', 'regex:/^\d{4}$/'],
            'naac_grade'       => 'nullable|in:A++,A+,A,B++,B+,B,C,D',
            'logo'             => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ], [
            'pin_code.regex' => 'PIN code must be 6 digits.',
            'established_year.regex' => 'Established year must be 4 digits.',
        ]);

        unset($v['logo']);
        if ($request->hasFile('logo')) {
            if ($org->logo_path) Storage::disk('public')->delete($org->logo_path);
            $v['logo_path'] = $request->file('logo')->store("organizations/{$org->id}", 'public');
        }

        $org->update($v);
        OrgBrand::flush();

        return response()->json([
            'message' => 'Institute profile saved.',
            'profile' => array_merge($org->fresh()->toArray(), ['logo_url' => OrgBrand::logoUrl($org->fresh())]),
        ]);
    }

    /** GET public/organization — no auth; brand for login / registration screens. */
    public function publicProfile(): JsonResponse
    {
        return response()->json(OrgBrand::publicArray())->header('Cache-Control', 'public, max-age=300');
    }

    // University portal — list all colleges
    public function list(): JsonResponse
    {
        return response()->json(Organization::where('type', 'college')->where('is_active', true)->get());
    }
}
