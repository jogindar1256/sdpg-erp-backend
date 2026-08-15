<?php

namespace App\Support;

/**
 * Server-side port of sdpg_frontend/src/lib/registrationSecurity.tsx.
 *
 * The frontend already runs these checks (human-name heuristic, Aadhaar
 * Verhoeff checksum, mobile/ABC-ID/Family-ID junk-pattern rejection) before
 * letting a UG/PG/BEd applicant request an OTP or submit. But client-side
 * checks are cosmetic against anyone who calls the API directly — this class
 * is the authoritative copy, applied in StudentRegistrationController so a
 * bypassed frontend can't actually get junk data into direct_registrations
 * or burn OTP/SMS budget.
 *
 * Keep the two files in sync if either the name blocklist or the ID formats
 * change.
 */
class RegistrationInputGuard
{
    // Standalone junk/placeholder/brand words people type instead of their
    // real name — mirrors the frontend's NAME_BLOCKLIST exactly.
    private const NAME_BLOCKLIST = [
        'test', 'demo', 'sample', 'dummy', 'admin', 'administrator', 'guest',
        'student', 'applicant', 'user', 'name', 'fullname', 'firstname', 'lastname',
        'na', 'n a', 'none', 'nil', 'unknown', 'xxx', 'xxxx', 'abc', 'abcd', 'xyz',
        'asdf', 'asdfgh', 'qwerty', 'qwertyuiop', 'zxcvbn', 'qazwsx',
        'john doe', 'jane doe', 'placeholder', 'your name', 'my name', 'enter name',
        'wordpress', 'facebook', 'google', 'youtube', 'instagram', 'twitter', 'whatsapp',
        'microsoft', 'apple', 'amazon', 'netflix', 'chatgpt', 'openai',
        'company', 'business', 'enterprise', 'pvt ltd', 'private limited', 'llc', 'inc',
    ];

    /** Returns an error message if $name doesn't look like a real human name, else null. */
    public static function nameError(?string $name): ?string
    {
        $trimmed = trim((string) $name);
        if ($trimmed === '') return null; // "required" is enforced separately

        $lower = strtolower(preg_replace('/\s+/', ' ', $trimmed));
        $noSpace = preg_replace('/\s+/', '', $lower);

        if (preg_match('/\d/', $trimmed)) {
            return 'Name cannot contain numbers — please enter your real name.';
        }
        if (!preg_match("/^[A-Za-z][A-Za-z .'-]*$/", $trimmed)) {
            return "Name can only contain letters, spaces, and . ' - — please enter your real name.";
        }
        if (preg_match('/^(.)\1+$/', $noSpace)) {
            return 'That doesn’t look like a real name — please enter your correct name.';
        }
        if (in_array($lower, self::NAME_BLOCKLIST, true) || in_array($noSpace, self::NAME_BLOCKLIST, true)) {
            return 'Please enter your real name, not a placeholder, brand, or business name.';
        }
        if (strlen(preg_replace('/[^A-Za-z]/', '', $trimmed)) < 3) {
            return 'Name looks too short — please enter your full real name.';
        }
        return null;
    }

    // ── Verhoeff checksum (Aadhaar's 12th digit) ────────────────────────────
    // Same public algorithm UIDAI uses. Reference: https://en.wikipedia.org/wiki/Verhoeff_algorithm

    private const D = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
        [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
        [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
        [4, 0, 1, 2, 3, 9, 5, 6, 7, 8],
        [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
        [6, 5, 9, 8, 7, 1, 0, 4, 3, 2],
        [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
        [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
        [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
    ];
    private const P = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
        [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
        [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
        [9, 4, 5, 3, 1, 2, 6, 8, 7, 0],
        [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
        [2, 7, 9, 3, 8, 0, 6, 4, 1, 5],
        [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
    ];

    private static function verhoeffIsValid(string $numStr): bool
    {
        $c = 0;
        $digits = array_reverse(array_map('intval', str_split($numStr)));
        foreach ($digits as $i => $digit) {
            $c = self::D[$c][self::P[$i % 8][$digit]];
        }
        return $c === 0;
    }

    private static function isSequential(string $digits): bool
    {
        $ascending = true;
        $descending = true;
        $len = strlen($digits);
        for ($i = 1; $i < $len; $i++) {
            $diff = (int) $digits[$i] - (int) $digits[$i - 1];
            if ($diff !== 1) $ascending = false;
            if ($diff !== -1) $descending = false;
        }
        return $ascending || $descending;
    }

    /** Returns an error message if $aadhaar fails format/junk/checksum checks, else null. */
    public static function aadhaarError(?string $aadhaar): ?string
    {
        $v = trim((string) $aadhaar);
        if ($v === '') return 'Aadhaar number is required.';
        if (!preg_match('/^\d{12}$/', $v)) return 'Aadhaar must be exactly 12 digits.';
        if (preg_match('/^[01]/', $v)) return 'Aadhaar numbers never start with 0 or 1 — please re-check.';
        if (preg_match('/^(\d)\1{11}$/', $v)) return 'That looks like a placeholder number — enter your real Aadhaar.';
        if (self::isSequential($v)) return 'That looks like a placeholder number — enter your real Aadhaar.';
        if (!self::verhoeffIsValid($v)) return 'This Aadhaar number is not valid — please double-check the digits.';
        return null;
    }

    /** Returns an error message if $mobile fails format/junk checks, else null. */
    public static function mobileError(?string $mobile): ?string
    {
        $v = trim((string) $mobile);
        if ($v === '') return 'Mobile number is required.';
        if (!preg_match('/^\d{10}$/', $v)) return 'Mobile number must be exactly 10 digits.';
        if (!preg_match('/^[6-9]/', $v)) return 'Indian mobile numbers start with 6, 7, 8, or 9.';
        if (preg_match('/^(\d)\1{9}$/', $v)) return 'That looks like a placeholder number — enter your real mobile number.';
        if (self::isSequential($v)) return 'That looks like a placeholder number — enter your real mobile number.';
        return null;
    }

    /** ABC ID (Academic Bank of Credits — abc.gov.in): 12-digit numeric. */
    public static function abcIdError(?string $id): ?string
    {
        $v = trim((string) $id);
        if ($v === '') return 'ABC ID is required.';
        if (!preg_match('/^\d{12}$/', $v)) return 'ABC ID must be exactly 12 digits (from abc.gov.in).';
        if (preg_match('/^(\d)\1{11}$/', $v)) return 'That looks like a placeholder ABC ID — enter your real one.';
        return null;
    }

    /** Family ID (UP "Ek Parivar Ek Pehchan" — familyid.up.gov.in): 12-digit numeric. */
    public static function familyIdError(?string $id): ?string
    {
        $v = trim((string) $id);
        if ($v === '') return 'Family ID is required.';
        if (!preg_match('/^\d{12}$/', $v)) return 'Family ID must be exactly 12 digits (from familyid.up.gov.in).';
        if (preg_match('/^(\d)\1{11}$/', $v)) return 'That looks like a placeholder Family ID — enter your real one.';
        return null;
    }

    /**
     * DDURN (Deen Dayal Upadhyaya Registration Number — dduguadmission.in).
     * No single published fixed digit-format, so this only rejects obvious
     * junk rather than enforcing an exact pattern.
     */
    public static function ddurnError(?string $id): ?string
    {
        $v = trim((string) $id);
        if ($v === '') return 'DDURN No. is required.';
        if (strlen($v) < 4) return 'DDURN No. looks too short — please re-check.';
        if (!preg_match('#^[A-Za-z0-9/-]+$#', $v)) return 'DDURN No. should only contain letters and numbers.';
        $alnum = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $v));
        if (preg_match('/^(.)\1+$/', $alnum)) return 'That looks like a placeholder DDURN — enter your real one.';
        if (in_array($alnum, ['test', 'demo', 'sample', 'dummy', '123456', '000000'], true)) {
            return 'That looks like a placeholder DDURN — enter your real one.';
        }
        return null;
    }
}
