<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class SecurityController extends Controller
{
    // ── Users ────────────────────────────────────────────────────────────────

    /**
     * GET /security/users
     * List all college-portal users with optional search.
     */
    public function users(Request $request)
    {
        $query = User::where('portal', 'college')
            ->orderBy('name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate(20)->through(function (User $u) {
            return [
                'id'            => $u->id,
                'name'          => $u->name,
                'email'         => $u->email,
                'mobile'        => $u->mobile ?? null,
                'employee_id'   => $u->employee_id ?? null,
                'role'          => $u->getRoleNames()->first(),
                'is_active'     => $u->is_active ?? true,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ];
        });

        return response()->json($users);
    }

    /**
     * POST /security/users
     * Create a new college ERP user.
     */
    public function createUser(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|unique:users,email',
            'mobile'      => 'nullable|digits:10',
            'employee_id' => 'nullable|string|max:50',
            'password'    => 'required|string|min:8',
            'role'        => 'required|string',
        ]);

        $user = User::create([
            'name'        => $data['name'],
            'email'       => $data['email'],
            'mobile'      => $data['mobile'] ?? null,
            'employee_id' => $data['employee_id'] ?? null,
            'password'    => Hash::make($data['password']),
            'portal'      => 'college',
            'is_active'   => true,
        ]);

        // Assign role via Spatie
        if ($data['role']) {
            $user->syncRoles([$data['role']]);
        }

        return response()->json([
            'message' => 'User created successfully.',
            'user'    => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ], 201);
    }

    /**
     * PATCH /security/users/{id}/deactivate
     */
    public function deactivateUser(int $id)
    {
        $user = User::where('portal', 'college')->findOrFail($id);
        $user->update(['is_active' => false]);

        // Revoke all personal access tokens so they can't stay logged in
        $user->tokens()->delete();

        return response()->json(['message' => 'User deactivated.']);
    }

    /**
     * POST /security/users/{id}/reset-password
     * Generate a temporary password and return it.
     */
    public function resetPassword(int $id)
    {
        $user = User::where('portal', 'college')->findOrFail($id);
        $temp = Str::random(10);
        $user->update(['password' => Hash::make($temp)]);
        $user->tokens()->delete(); // force re-login

        return response()->json(['message' => 'Password reset.', 'temp_password' => $temp]);
    }

    // ── Reset Password (any portal) ─────────────────────────────────────────

    /**
     * GET /security/reset-password/users
     * Search users across EVERY portal (college staff, students, etc.) — the
     * plain /security/users list above is deliberately scoped to portal=college
     * only, so this is a separate, portal-agnostic lookup just for the
     * "reset password of any user" tool.
     */
    public function searchAllUsers(Request $request)
    {
        $query = User::query()->orderBy('name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('mobile', 'like', "%{$search}%")
                  ->orWhere('employee_id', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate(20)->through(function (User $u) {
            return [
                'id'        => $u->id,
                'name'      => $u->name,
                'email'     => $u->email,
                'mobile'    => $u->mobile,
                'portal'    => $u->portal,
                'role'      => $u->getRoleNames()->first(),
                'is_active' => $u->is_active ?? true,
            ];
        });

        return response()->json($users);
    }

    /**
     * POST /security/reset-password
     * Admin-driven password reset for ANY user, any portal (college staff or
     * student) — two modes:
     *   - mode=email:    admin sets the new password themselves, it's emailed
     *                     to the account's email on file, and never echoed
     *                     back in the response.
     *   - mode=generate: server generates a random password, saves it, and
     *                     returns it in the response so the admin can copy it
     *                     and hand it over directly (no email sent).
     */
    public function resetAnyPassword(Request $request)
    {
        $data = $request->validate([
            'user_id'      => 'required|integer|exists:users,id',
            'mode'         => 'required|in:email,generate',
            'new_password' => 'required_if:mode,email|nullable|string|min:8',
        ]);

        $user = User::findOrFail($data['user_id']);

        if ($data['mode'] === 'email') {
            if (!$user->email) {
                return response()->json([
                    'message' => 'This account has no email on file — use "Generate & Copy" instead.',
                ], 422);
            }

            $plain = $data['new_password'];
            // Assigning the plain value is enough — User::$casts hashes it
            // automatically (see 'password' => 'hashed'), same convention
            // used by StudentRegistrationController::provisionAccount().
            $user->update(['password' => $plain]);
            $user->tokens()->delete(); // force re-login everywhere

            $portalLabel = $user->portal === 'student' ? 'Student' : 'College';
            $loginPath   = $user->portal === 'student' ? '/student/login' : '/login';
            $loginId     = $user->portal === 'student' ? ($user->mobile ?? $user->email) : $user->email;

            $body = "Dear {$user->name},\n\n"
                . "Your password for the SDPG College {$portalLabel} Portal has been reset by an administrator.\n\n"
                . "==============================\n"
                . "NEW LOGIN CREDENTIALS\n"
                . "==============================\n"
                . "Portal   : {$loginPath}\n"
                . "Login ID : {$loginId}\n"
                . "Password : {$plain}\n\n"
                . "Please log in and change this password as soon as possible.\n\n"
                . "Regards,\nSDPG College ERP\n"
                . "(Do not share your password with anyone. If you did not request this, contact the office immediately.)";

            try {
                Mail::raw($body, fn ($m) => $m->to($user->email)->subject('Your SDPG College ERP password has been reset'));
            } catch (\Throwable $e) {
                Log::error("Password reset email failed for user {$user->id}: " . $e->getMessage());
                return response()->json([
                    'message' => 'Password was reset, but the email failed to send. Please share it with the user manually or try "Generate & Copy" instead.',
                ], 502);
            }

            return response()->json(['message' => "Password reset and emailed to {$user->email}."]);
        }

        // mode=generate
        $plain = strtoupper(Str::random(3)) . random_int(100, 999) . Str::lower(Str::random(2));
        $user->update(['password' => $plain]);
        $user->tokens()->delete();

        return response()->json([
            'message'       => 'Password reset. Copy it now — it will not be shown again.',
            'temp_password' => $plain,
        ]);
    }

    // ── Permissions ──────────────────────────────────────────────────────────

    /**
     * GET /security/permissions
     * Return all permissions grouped by category.
     */
    public function permissions()
    {
        $perms = Permission::orderBy('name')->get()->map(function (Permission $p) {
            // name format: group.action  e.g.  admissions.approve
            $parts = explode('.', $p->name, 2);
            return [
                'name'  => $p->name,
                'label' => ucwords(str_replace(['.', '_', '-'], ' ', $p->name)),
                'group' => ucfirst($parts[0] ?? 'General'),
            ];
        });

        return response()->json(['data' => $perms]);
    }

    /**
     * GET /security/users/{id}/permissions
     */
    public function getUserPermissions(int $id)
    {
        $user = User::where('portal', 'college')->findOrFail($id);
        return response()->json([
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    /**
     * PUT /security/users/{id}/permissions
     */
    public function updateUserPermissions(int $id, Request $request)
    {
        $user = User::where('portal', 'college')->findOrFail($id);
        $perms = $request->validate(['permissions' => 'array'])['permissions'] ?? [];
        $user->syncPermissions($perms);

        return response()->json(['message' => 'Permissions updated.']);
    }

    // ── Login Status ─────────────────────────────────────────────────────────

    /**
     * GET /security/login-status
     * Live session status — uses personal_access_tokens to detect active sessions.
     */
    public function loginStatus()
    {
        // A session is "online" if the token was last used within 15 minutes
        $cutoff = now()->subMinutes(15);

        $sessions = DB::table('personal_access_tokens as t')
            ->join('users as u', 'u.id', '=', 't.tokenable_id')
            ->where('t.tokenable_type', User::class)
            ->where('u.portal', 'college')
            ->select(
                'u.id',
                'u.name',
                'u.email',
                't.last_used_at',
                't.created_at as login_at',
                DB::raw('(t.last_used_at >= ?) as is_online'),
            )
            ->addBinding($cutoff, 'select')
            ->orderByDesc('t.last_used_at')
            ->get()
            ->map(fn ($r) => [
                'id'            => $r->id,
                'name'          => $r->name,
                'email'         => $r->email,
                'login_at'      => $r->login_at,
                'last_activity' => $r->last_used_at,
                'is_online'     => (bool) $r->is_online,
                'ip_address'    => null, // Sanctum doesn't store IP by default
                'role'          => User::find($r->id)?->getRoleNames()->first(),
            ]);

        $totalUsers  = User::where('portal', 'college')->count();
        $todayLogins = DB::table('personal_access_tokens')
            ->join('users', 'users.id', '=', 'personal_access_tokens.tokenable_id')
            ->where('users.portal', 'college')
            ->whereDate('personal_access_tokens.created_at', today())
            ->count();

        return response()->json([
            'sessions'     => $sessions,
            'total_users'  => $totalUsers,
            'today_logins' => $todayLogins,
        ]);
    }

    // ── Active Sessions ───────────────────────────────────────────────────────

    /**
     * GET /security/active-sessions
     */
    public function activeSessions()
    {
        $cutoff   = now()->subMinutes(15);
        $sessions = DB::table('personal_access_tokens as t')
            ->join('users as u', 'u.id', '=', 't.tokenable_id')
            ->where('t.tokenable_type', User::class)
            ->where('u.portal', 'college')
            ->where('t.last_used_at', '>=', $cutoff)
            ->select('t.id as token_id', 'u.id as user_id', 'u.name', 'u.email', 't.last_used_at', 't.created_at as login_at')
            ->orderByDesc('t.last_used_at')
            ->get();

        return response()->json(['data' => $sessions]);
    }

    /**
     * POST /security/sessions/{tokenId}/force-logout
     */
    public function forceLogout(int $tokenId)
    {
        $deleted = DB::table('personal_access_tokens')
            ->where('id', $tokenId)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        return response()->json(['message' => 'Session terminated.']);
    }

    // ── Session Summary ───────────────────────────────────────────────────────

    /**
     * GET /security/session-summary?date=2026-06-25
     */
    public function sessionSummary(Request $request)
    {
        $date = $request->query('date', today()->toDateString());

        $rows = DB::table('personal_access_tokens as t')
            ->join('users as u', 'u.id', '=', 't.tokenable_id')
            ->where('t.tokenable_type', User::class)
            ->where('u.portal', 'college')
            ->whereDate('t.created_at', $date)
            ->select(
                'u.id',
                'u.name',
                'u.email',
                't.created_at as login_at',
                't.last_used_at',
                DB::raw('TIMESTAMPDIFF(MINUTE, t.created_at, IFNULL(t.last_used_at, NOW())) as duration_minutes')
            )
            ->orderByDesc('t.created_at')
            ->get();

        return response()->json(['date' => $date, 'data' => $rows]);
    }

    // ── Menu Shortcodes ───────────────────────────────────────────────────────

    /**
     * GET /security/menu-shortcodes
     */
    public function menuShortcodes()
    {
        $codes = DB::table('menu_shortcodes')
            ->orderBy('shortcode')
            ->get();

        return response()->json(['data' => $codes]);
    }

    /**
     * PUT /security/menu-shortcodes
     * Bulk save shortcodes.
     */
    public function saveMenuShortcodes(Request $request)
    {
        $items = $request->validate(['items' => 'required|array'])['items'];

        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                DB::table('menu_shortcodes')->updateOrInsert(
                    ['menu_path' => $item['menu_path']],
                    ['shortcode' => strtoupper($item['shortcode']), 'updated_at' => now()]
                );
            }
        });

        return response()->json(['message' => 'Shortcodes saved.']);
    }
}
