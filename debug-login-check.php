<?php
/**
 * Standalone login diagnostic — boots the real Laravel app (so it uses the
 * exact same Hash facade, hashing config, and DB connection as the live
 * site) and checks a password against a specific account directly, with
 * NO HTTP layer, NO validation, NO middleware in the way.
 *
 * This exists to answer one question precisely: for a login that's failing
 * with 422, is the stored password hash genuinely not matching what's typed
 * (a data problem), or is something else short-circuiting the request
 * before Hash::check ever runs (a request/validation problem)? Those look
 * identical from the browser but have completely different fixes.
 *
 * Usage (run from the sdpg-erp-backend directory):
 *   Student account (looked up by mobile):
 *     php debug-login-check.php student 9876543210 "ThePasswordTheyTyped"
 *
 *   College/staff account (looked up by email):
 *     php debug-login-check.php college someone@example.com "ThePasswordTheyTyped"
 *
 * Delete this file when you're done — it can print a partial hash prefix
 * and confirms whether an account exists, which is more than a public
 * script should ever expose.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

$type       = $argv[1] ?? null;
$identifier = $argv[2] ?? null;
$password   = $argv[3] ?? null;

if (!$type || !$identifier || $password === null) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php debug-login-check.php student <mobile> <password>\n");
    fwrite(STDERR, "  php debug-login-check.php college <email> <password>\n");
    exit(1);
}

echo "==================================================\n";
echo "login check started\n";
echo "  type       : {$type}\n";
echo "  identifier : {$identifier}\n";
echo "  hashing driver (config) : " . config('hashing.driver') . "\n";
echo "==================================================\n";

if ($type === 'student') {
    // Mirror AuthController::studentLogin()'s exact lookup order.
    $student = DB::table('students')->where('mobile', $identifier)->whereNull('deleted_at')->first();
    if (!$student) {
        echo "RESULT: no row in `students` with mobile = {$identifier}.\n";
        echo "        (AuthController::studentLogin would respond: 'No student found with this mobile number.')\n";
        exit(0);
    }
    echo "found students row: id={$student->id}, user_id=" . ($student->user_id ?? 'NULL') . "\n";

    $user = null;
    if (!empty($student->user_id)) {
        $user = User::find($student->user_id);
    }
    if (!$user) {
        $user = User::where('mobile', $identifier)->where('portal', 'student')->first();
    }

    if ($user) {
        echo "found users row: id={$user->id}, mobile={$user->mobile}, portal={$user->portal}, is_active=" . ($user->is_active ? 'true' : 'false') . "\n";
        checkHash($password, $user->password);
    } else {
        echo "no users row found via user_id or mobile lookup — this account is on the LEGACY path\n";
        echo "(password lives on students.password directly, not users.password).\n";
        $studentPw = $student->password ?? null;
        if (!$studentPw) {
            echo "RESULT: students.password is also empty — account setup incomplete.\n";
        } else {
            checkHash($password, $studentPw, allowPlainFallback: true, plainStored: $studentPw);
        }
    }
} elseif ($type === 'college') {
    $normalized = trim(strtolower($identifier));
    $matches = User::whereRaw('LOWER(TRIM(email)) = ?', [$normalized])->where('portal', 'college')->orderBy('id')->get();

    echo "matched " . $matches->count() . " row(s) in `users` for portal=college, email (case-insensitive) = {$normalized}\n";
    if ($matches->count() > 1) {
        echo "  !! MULTIPLE MATCHES — ids: " . $matches->pluck('id')->implode(', ') . "\n";
        echo "     AuthController::login() picks the lowest id via orderBy('id'); if that's\n";
        echo "     not the account you think you're testing, that mismatch alone would\n";
        echo "     explain an 'occasional' 422 — same email, different account, different password.\n";
    }
    $user = $matches->first();
    if (!$user) {
        echo "RESULT: no users row with portal=college matches that email at all.\n";
        exit(0);
    }
    echo "checking against user id={$user->id}, is_active=" . ($user->is_active ? 'true' : 'false') . "\n";
    checkHash($password, $user->password);
} else {
    fwrite(STDERR, "Unknown type '{$type}' — must be 'student' or 'college'.\n");
    exit(1);
}

function checkHash(string $plain, ?string $stored, bool $allowPlainFallback = false, ?string $plainStored = null): void
{
    echo "--------------------------------------------------\n";
    if (!$stored) {
        echo "RESULT: stored password value is NULL/empty. Hash::check cannot succeed against nothing.\n";
        return;
    }

    echo "stored value length : " . strlen($stored) . " chars\n";
    echo "stored value prefix : " . substr($stored, 0, 7) . "...\n"; // enough to identify $2y$ (bcrypt) vs $argon2id$ etc, not enough to be useful to an attacker
    echo "Hash::isHashed(stored) : " . (Hash::isHashed($stored) ? 'true' : 'false') . "\n";

    if (!Hash::isHashed($stored)) {
        echo "!! The stored value does not look like a hash at all (no recognized prefix).\n";
        echo "   If this is true, something wrote a PLAIN password (or something malformed)\n";
        echo "   directly into this column, bypassing the User model's 'hashed' cast\n";
        echo "   entirely — e.g. a raw DB::table()->update() instead of \$user->update().\n";
    }

    $checkResult = false;
    try {
        $checkResult = Hash::check($plain, $stored);
    } catch (\Throwable $e) {
        echo "Hash::check() THREW: " . get_class($e) . ' — ' . $e->getMessage() . "\n";
        echo "(this itself can be the cause of a 422/500 — e.g. Hash::verifyConfiguration()\n";
        echo " rejecting a hash produced under a different hashing.php config than is live now)\n";
    }

    echo "Hash::check(typed password, stored hash) => " . ($checkResult ? 'MATCH' : 'NO MATCH') . "\n";

    if (!$checkResult && $allowPlainFallback && $plainStored !== null) {
        $plainMatch = $plain === $plainStored;
        echo "legacy plain-text fallback comparison => " . ($plainMatch ? 'MATCH' : 'NO MATCH') . "\n";
    }

    echo "==================================================\n";
    if ($checkResult) {
        echo "RESULT: this password IS correct for this account at the data layer.\n";
        echo "        If the real login still 422s with this exact password, the bug is\n";
        echo "        NOT a data/hash mismatch — it's something in the HTTP request path\n";
        echo "        (validation rule, middleware, how the frontend serializes the request,\n";
        echo "        a proxy/load balancer altering the body, etc). Get the raw Network-tab\n";
        echo "        response body for that exact request next — this script has ruled out\n";
        echo "        the password/hash itself as the cause.\n";
    } else {
        echo "RESULT: this password does NOT match what's stored for this account.\n";
        echo "        Either the account's real password is genuinely different from what\n";
        echo "        was typed/tested here, or whatever wrote this hash did something wrong.\n";
        echo "        A password reset for this specific account will resolve it either way.\n";
    }
}
