<?php

namespace App\Support;

/**
 * Server-side backstop for the "type in caps" behavior on application and
 * registration forms. The frontend already uppercases as the user types
 * (see uppercaseOnInputCapture in the Next.js app), but that's a UX nicety
 * only — anyone hitting the API directly, or a future form that forgets to
 * wire the frontend helper, must still end up with the same normalized data
 * in the database. This is that guarantee.
 *
 * Only genuinely free-text fields should go through this. Never run email,
 * password/hash, tokens, or file/URL/base64 values through it — those are
 * excluded by key name below regardless of caller intent.
 */
class TextNormalizer
{
    /**
     * Substrings matched against (lowercased) array keys. Any key containing
     * one of these is left completely untouched.
     */
    private const EXCLUDED_KEY_PATTERNS = [
        'email',
        'password',
        'token',
        'url',
        'path',
        'signature',
        'photo',
        'image',
        'file',
        'base64',
        'hash',
        'secret',
    ];

    /**
     * Recursively uppercase every string value in $data, skipping any key
     * that matches an excluded pattern. Non-string values (ints, bools,
     * null, UploadedFile instances, etc.) pass through unchanged. Numeric
     * strings and non-Latin scripts (e.g. Hindi fields) are safe to include
     * since uppercasing them is a no-op — there's no need to special-case
     * them out.
     */
    public static function upper(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::upper($value);
                continue;
            }

            if (!is_string($value) || self::isExcludedKey((string) $key)) {
                $out[$key] = $value;
                continue;
            }

            $out[$key] = mb_strtoupper($value, 'UTF-8');
        }

        return $out;
    }

    /** Single-value version for call sites that already whitelist their own field list. */
    public static function upperValue(mixed $value): mixed
    {
        return is_string($value) ? mb_strtoupper($value, 'UTF-8') : $value;
    }

    private static function isExcludedKey(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::EXCLUDED_KEY_PATTERNS as $pattern) {
            if (str_contains($key, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
