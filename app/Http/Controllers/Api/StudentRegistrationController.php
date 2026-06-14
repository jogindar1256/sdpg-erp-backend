<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class StudentRegistrationController extends Controller
{
    /**
     * Self-registration for new students.
     * Creates both a Student record and a User account.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'required|string|max:100',
            'gender' => 'required|in:male,female,other',
            'date_of_birth' => 'required|date|before:today',
            'category' => 'required|in:general,obc,sc,st,ews',
            'mobile' => 'required|string|size:10|unique:students,mobile|unique:users,mobile',
            'email' => 'nullable|email|unique:students,email|unique:users,email',
            'aadhar_no' => 'nullable|string|size:12',
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],

            // Address
            'permanent_address' => 'required|string',
            'permanent_city' => 'required|string',
            'permanent_district' => 'required|string',
            'permanent_state' => 'required|string',
            'permanent_pin' => 'required|string|size:6',

            // Optional: which college to register under
            'organization_code' => 'nullable|string|exists:organizations,code',
        ]);

        // Find the organization (default to first active college)
        $org = isset($validated['organization_code'])
            ? Organization::where('code', $validated['organization_code'])->first()
            : Organization::where('is_active', true)->first();

        if (!$org) {
            return response()->json(['message' => 'No active college found. Contact the office.'], 422);
        }

        $student = DB::transaction(function () use ($validated, $org) {

            // Create User account
            $user = User::create([
                'organization_id' => $org->id,
                'name' => trim("{$validated['first_name']} {$validated['middle_name']} {$validated['last_name']}"),
                'mobile' => $validated['mobile'],
                'email' => $validated['email'] ?? null,
                'password' => Hash::make($validated['password']),
                'portal' => 'student',
                'is_active' => true,
            ]);
            $user->assignRole('student');

            // Create Student record
            $student = Student::create([
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'first_name' => $validated['first_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'],
                'gender' => $validated['gender'],
                'date_of_birth' => $validated['date_of_birth'],
                'category' => $validated['category'],
                'mobile' => $validated['mobile'],
                'email' => $validated['email'] ?? null,
                'aadhar_no' => $validated['aadhar_no'] ?? null,
                'permanent_address' => $validated['permanent_address'],
                'permanent_city' => $validated['permanent_city'],
                'permanent_district' => $validated['permanent_district'],
                'permanent_state' => $validated['permanent_state'],
                'permanent_pin' => $validated['permanent_pin'],
                'status' => 'active',
            ]);

            return $student;
        });

        // Auto-login after registration
        $user = $student->user;
        $token = $user->createToken('student-token', ['student:access'])->plainTextToken;

        return response()->json([
            'message' => 'Registration successful! You can now fill your application.',
            'student' => $student->only(['id', 'full_name', 'mobile', 'enrollment_no']),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'mobile' => $user->mobile,
                'email' => $user->email,
                'portal' => 'student',
                'roles' => ['student'],
                'permissions' => [],
                'organization_id' => $user->organization_id,
                'organization' => $org->only(['id', 'name', 'code']),
            ],
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    /**
     * Check if mobile is already registered — used for real-time validation.
     */
    public function checkMobile(Request $request): JsonResponse
    {
        $request->validate(['mobile' => 'required|string|size:10']);
        $exists = Student::where('mobile', $request->mobile)->exists();
        return response()->json(['available' => !$exists]);
    }
}