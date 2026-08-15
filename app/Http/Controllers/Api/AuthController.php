<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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

        // Match the email case/whitespace-insensitively — mobile keyboards
        // and browser autofill can normalize input differently than manual
        // typing (e.g. autocapitalize on the first letter), and email is
        // conventionally case-insensitive anyway. Password is intentionally
        // NOT trimmed/altered here — Hash::check must compare the exact
        // bytes the account's password was hashed from.
        $normalizedEmail = trim(strtolower($request->email));

        // portal enum is college|student only ('university' and 'super_admin'
        // were retired by migration — 'university' because this is a college
        // system not a university system, 'super_admin' because it's a ROLE
        // now, not a portal value).
        //
        // NOTE: Postgres' default unique index on `email` is case-sensitive,
        // so "User@x.com" and "user@x.com" can both exist as "unique" rows.
        // Matching case-insensitively (above) means if such a duplicate ever
        // exists, which row comes back is otherwise undefined without an
        // explicit order — that would look exactly like "sometimes invalid
        // even when typed correctly", because the query could hand back a
        // sibling account with a different password hash. orderBy('id')
        // makes the pick deterministic; the count check below tells us via
        // the log if a duplicate is the actual cause, without guessing.
        $matches = User::whereRaw('LOWER(TRIM(email)) = ?', [$normalizedEmail])
                    ->where('portal', 'college')
                    ->orderBy('id')
                    ->get();

        if ($matches->count() > 1) {
            \Illuminate\Support\Facades\Log::warning('Login: multiple user rows matched the same email case-insensitively', [
                'email_normalized' => $normalizedEmail,
                'user_ids' => $matches->pluck('id')->all(),
            ]);
        }

        $user = $matches->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            \Illuminate\Support\Facades\Log::info('Login failed', [
                'email_normalized' => $normalizedEmail,
                'user_found' => (bool) $user,
                'ip' => $request->ip(),
            ]);
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials. Please check your email and password.'],
            ]);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Your account has been deactivated. Contact the administrator.',
            ], 403);
        }

        $user->update([
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
     *
     * Uses raw DB queries (not Eloquent relationships) because the Student model's
     * user() relationship may not be defined. The canonical link is:
     *   students.user_id  →  users.id
     *
     * If no user_id is set (legacy registration), falls back to students.password.
     */
    public function studentLogin(Request $request): JsonResponse
    {
        $request->validate([
            'mobile'   => 'required|digits:10',
            'password' => 'required|string|min:1',
        ]);

        // ── 1. Find the student row ───────────────────────────────────────────
        $student = DB::table('students')
            ->where('mobile', $request->mobile)
            ->whereNull('deleted_at')
            ->first();

        if (!$student) {
            throw ValidationException::withMessages([
                'mobile' => ['No student found with this mobile number.'],
            ]);
        }

        // ── 2. Resolve the users row ─────────────────────────────────────────
        //      Priority: students.user_id → users table
        $user = null;

        if (!empty($student->user_id)) {
            $user = User::find($student->user_id);
        }

        // Fallback: user registered with same mobile in users table, portal=student
        if (!$user) {
            $user = User::where('mobile', $request->mobile)
                        ->where('portal', 'student')
                        ->first();
        }

        // ── 3. Verify password ────────────────────────────────────────────────
        if ($user) {
            // Standard path — password lives in users table
            if (!Hash::check($request->password, $user->password)) {
                throw ValidationException::withMessages([
                    'mobile' => ['Invalid password. Please check and try again.'],
                ]);
            }

            // Keep portal correct
            if ($user->portal !== 'student') {
                $user->update(['portal' => 'student']);
            }
        } else {
            // Legacy path — password on students table directly
            $studentPw = $student->password ?? null;

            if (!$studentPw) {
                throw ValidationException::withMessages([
                    'mobile' => ['Account setup incomplete. Contact the college office.'],
                ]);
            }

            $passwordValid = Hash::check($request->password, $studentPw)
                // plain-text fallback for really old data (insecure but prevents lockout)
                || $request->password === $studentPw;

            if (!$passwordValid) {
                throw ValidationException::withMessages([
                    'mobile' => ['Invalid password. Please check and try again.'],
                ]);
            }

            // Promote: create a proper users row so Sanctum can issue tokens
            $user = User::create([
                'name'      => $student->full_name ?? $student->name ?? 'Student',
                'email'     => $student->email ?? "s{$student->mobile}@sdpg.local",
                'mobile'    => $student->mobile,
                'password'  => Hash::make($request->password), // ensure it's hashed
                'portal'    => 'student',
                'is_active' => true,
            ]);

            // Link back so next login skips this path
            DB::table('students')
                ->where('id', $student->id)
                ->update(['user_id' => $user->id]);
        }

        // ── 4. Block / active checks ─────────────────────────────────────────
        if ($student->is_blocked ?? false) {
            $reason = $student->block_reason ?? 'Contact the college office.';
            return response()->json(['message' => "Account blocked. Reason: {$reason}"], 403);
        }

        if (!($user->is_active ?? true)) {
            return response()->json(['message' => 'Account deactivated. Contact the administrator.'], 403);
        }

        // ── 5. Update last login ─────────────────────────────────────────────
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        // ── 6. Issue Sanctum token ────────────────────────────────────────────
        $token = $user->createToken('student-token', ['student:access'])->plainTextToken;

        return response()->json([
            'user'    => $this->formatUser($user),
            'student' => [
                'id'            => $student->id,
                'enrollment_no' => $student->enrollment_no ?? null,
                'name'          => $student->full_name ?? $student->name ?? $user->name,
                'mobile'        => $student->mobile,
                'photo_path'    => $student->photo_path ?? null,
                'status'        => $student->status ?? 'active',
            ],
            'token'      => $token,
            'token_type' => 'Bearer',
        ]);
    }

    // ── Password Reset — Employee ─────────────────────────────────────────────

    /**
     * POST /auth/forgot-password
     * Sends a password reset link to the employee's email.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::whereRaw('LOWER(TRIM(email)) = ?', [trim(strtolower($request->email))])
                    ->where('portal', 'college')
                    ->first();

        if (!$user) {
            // Don't reveal whether the email exists
            return response()->json(['message' => 'If this email is registered, a reset link has been sent.']);
        }

        // Generate a reset token
        $token = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        // In production, queue a mail: Mail::to($user)->send(new PasswordResetMail($token))
        // For now, return the token in dev — REMOVE this in production
        $resetUrl = config('app.frontend_url', 'http://localhost:3000')
                  . "/reset-password?token={$token}&email=" . urlencode($user->email);

        // TODO: send email — Mail::to($user->email)->send(new ResetPasswordMail($resetUrl));

        return response()->json([
            'message'    => 'Password reset link sent to your email.',
            // Remove `reset_url` in production — dev convenience only
            'reset_url'  => $resetUrl,
        ]);
    }

    /**
     * POST /auth/reset-password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email'                 => 'required|email',
            'token'                 => 'required|string',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$record || !Hash::check($request->token, $record->token)) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        // Token expires after 60 minutes
        if (now()->diffInMinutes($record->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json(['message' => 'Reset token has expired. Please request a new one.'], 422);
        }

        $user = User::where('email', $request->email)
                    ->where('portal', 'college')
                    ->firstOrFail();

        $user->update(['password' => Hash::make($request->password)]);
        $user->tokens()->delete(); // revoke all sessions

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Password reset successfully. You can now login.']);
    }

    // ── Password Reset — Student ──────────────────────────────────────────────

    /**
     * POST /auth/student/forgot-password
     * Generates a temporary password for the student and returns it.
     * (In production: send via SMS)
     */
    public function studentForgotPassword(Request $request): JsonResponse
    {
        $request->validate(['mobile' => 'required|digits:10']);

        $student = DB::table('students')
            ->where('mobile', $request->mobile)
            ->whereNull('deleted_at')
            ->first();

        if (!$student) {
            return response()->json(['message' => 'If this mobile is registered, a temporary password will be sent.']);
        }

        $tempPassword = strtoupper(Str::random(3)) . rand(100, 999);

        // Update password on the users row (preferred) or students row (fallback)
        if (!empty($student->user_id)) {
            User::where('id', $student->user_id)
                ->update(['password' => Hash::make($tempPassword)]);
        } else {
            DB::table('students')
                ->where('id', $student->id)
                ->update(['password' => Hash::make($tempPassword)]);
        }

        // TODO: SMS::send($student->mobile, "Your SDPG temp password: {$tempPassword}");

        return response()->json([
            'message'       => 'A temporary password has been generated.',
            // Remove in production — show only via SMS
            'temp_password' => $tempPassword,
        ]);
    }

    /**
     * POST /auth/student/reset-password
     * Student sets a new password after logging in with the temp password.
     */
    public function studentResetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'mobile'                => 'required|digits:10',
            'current_password'      => 'required|string',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        $student = DB::table('students')
            ->where('mobile', $request->mobile)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $user = !empty($student->user_id) ? User::find($student->user_id) : null;

        if (!$user || !Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->update(['password' => Hash::make($request->password)]);
        $user->tokens()->delete();

        return response()->json(['message' => 'Password changed. Please login with your new password.']);
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
