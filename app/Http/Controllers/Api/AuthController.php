<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Employee login — all staff roles (Admin, Verifier, Super Admin, etc.)
     * belong to portal = 'college'. The role itself is returned via Spatie roles[].
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
            'portal'   => 'required|in:college',   // only college portal for employees
            'session'  => 'nullable|string',
        ]);

        // Accept college, super_admin, AND legacy 'university' portal values.
        // All employee accounts belong to 'college' conceptually — 'university' was
        // the old value before the rename. We auto-migrate them below.
        $user = User::where('email', $request->email)
                    ->whereIn('portal', ['college', 'super_admin', 'university'])
                    ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials. Please check your email and password.'],
            ]);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Your account has been deactivated. Contact the administrator.',
            ], 403);
        }

        // Auto-migrate legacy portal values to 'college' on successful login
        if (in_array($user->portal, ['university', 'super_admin'])) {
            $user->portal = 'college';
        }

        $user->update([
            'portal'        => $user->portal,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $token = $user->createToken('erp-token', $this->getAbilitiesForUser($user))->plainTextToken;

        return response()->json([
            'user'       => $this->formatUser($user),
            'token'      => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Student login — mobile number + password.
     * Students are separate from employees and always use portal = 'student'.
     */
    public function studentLogin(Request $request): JsonResponse
    {
        $request->validate([
            'mobile'   => 'required|string|size:10',
            'password' => 'required|string',
        ]);

        $student = Student::where('mobile', $request->mobile)->first();

        if (!$student || !$student->user) {
            throw ValidationException::withMessages([
                'mobile' => ['Student not found. Check your mobile number.'],
            ]);
        }

        $user = $student->user;

        if (!Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'mobile' => ['Invalid password.'],
            ]);
        }

        if ($student->is_blocked) {
            return response()->json([
                'message' => "Your account is blocked. Reason: {$student->block_reason}",
            ], 403);
        }

        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $token = $user->createToken('student-token', ['student:access'])->plainTextToken;

        return response()->json([
            'user'       => $this->formatUser($user),
            'student'    => $student->only([
                'id', 'enrollment_no', 'full_name', 'mobile', 'photo_path', 'status',
            ]),
            'token'      => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Logout — revoke current Sanctum token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * Return the authenticated user's profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('organization');
        return response()->json($this->formatUser($user));
    }

    /**
     * Change password — revokes all other tokens for security.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        // Revoke all other active tokens
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password changed successfully.']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    private function formatUser(User $user): array
    {
        return [
            'id'              => $user->id,
            'name'            => $user->name,
            'email'           => $user->email,
            'mobile'          => $user->mobile,
            'portal'          => $user->portal,
            'roles'           => $user->getRoleNames(),
            'permissions'     => $user->getAllPermissions()->pluck('name'),
            'organization_id' => $user->organization_id,
            'organization'    => $user->organization?->only(['id', 'name', 'code', 'logo_path']),
        ];
    }

    private function getAbilitiesForUser(User $user): array
    {
        return $user->getAllPermissions()->pluck('name')->toArray();
    }
}
