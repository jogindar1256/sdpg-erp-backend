<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Single read-side entry point for the Institute Profile
 * (college/organization/profile). One college per install, so "current" is
 * the first active organization. Everything that prints or sends the
 * college's name / address / logo (PDFs, e-mails, SMS, the public brand
 * endpoint) goes through here instead of hard-coding it.
 */
class OrgBrand
{
    /** @var object|null|false */
    private static $cached = false;

    public static function current(): ?object
    {
        if (self::$cached === false) {
            self::$cached = DB::table('organizations')->where('is_active', true)->whereNull('deleted_at')->orderBy('id')->first();
        }
        return self::$cached;
    }

    /** Forget the per-request cache (after the profile is saved). */
    public static function flush(): void
    {
        self::$cached = false;
    }

    public static function name($org = null, string $fallback = 'College'): string
    {
        $org ??= self::current();
        return trim((string) ($org->name ?? '')) ?: $fallback;
    }

    /** Short name for subject lines / SMS; falls back to the full name. */
    public static function shortName($org = null): string
    {
        $org ??= self::current();
        return trim((string) ($org->short_name ?? '')) ?: self::name($org);
    }

    /** "Address, District (State) PIN" */
    public static function fullAddress($org = null): string
    {
        $org ??= self::current();
        if (!$org) return '';
        $line = implode(', ', array_filter([trim((string) ($org->address ?? '')), trim((string) ($org->district ?? ''))]));
        $state = trim((string) ($org->state ?? ''));
        $pin = trim((string) ($org->pin_code ?? ''));
        return trim($line . ($state ? " ($state)" : '') . ($pin ? " $pin" : ''));
    }

    public static function logoUrl($org = null): ?string
    {
        $org ??= self::current();
        $path = $org->logo_path ?? null;
        if (!$path) return null;
        return rtrim(config('app.url'), '/') . Storage::url($path);
    }

    /** data: URI of the logo, for DomPDF (no remote fetch, works offline). */
    public static function logoDataUri($org = null): ?string
    {
        $org ??= self::current();
        $path = $org->logo_path ?? null;
        if (!$path || !Storage::disk('public')->exists($path)) return null;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
        return "data:$mime;base64," . base64_encode(Storage::disk('public')->get($path));
    }

    /** Public frontend base URL (login links in mails). */
    public static function portalUrl(): string
    {
        $url = (string) config('app.frontend_url', env('FRONTEND_URL', ''));
        if ($url === '') {
            // Not configured — fall back to the website saved in the Institute Profile.
            $site = trim((string) (self::current()->website ?? ''));
            $url = $site !== '' && !preg_match('#^https?://#i', $site) ? "https://$site" : $site;
        }
        return rtrim($url, '/');
    }

    /** College code assigned by the affiliating university (centre code). Null if not set. */
    public static function collegeCode($org = null): ?string
    {
        $org ??= self::current();
        $c = trim((string) ($org->university_college_code ?? ''));
        return $c === '' ? null : $c;
    }

    /** Lower-case alphanumerics only, for tolerant name comparison. */
    public static function norm(?string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $s));
    }

    /**
     * Does this institute (as typed / picked in an education record) mean
     * "this college"? Matches the college code first, then the college's
     * full name, then the first word of its short name (e.g. "SDPG").
     */
    public static function isOwnInstitute(?string $name, ?string $code = null, $org = null): bool
    {
        $org ??= self::current();
        if (!$org) return false;
        if (self::norm($code) !== '' && self::norm($code) === self::norm($org->code ?? '')) return true;

        $n = self::norm($name);
        if ($n === '') return false;
        $full = self::norm($org->name ?? '');
        if ($full !== '' && (str_contains($n, $full) || (strlen($n) >= 8 && str_contains($full, $n)))) return true;

        $generic = ['college', 'institute', 'university', 'school', 'mahavidyalaya', 'campus'];
        $word = strtolower(preg_split('/\s+/', trim((string) ($org->short_name ?? '')))[0] ?? '');
        $word = preg_replace('/[^a-z0-9]/', '', $word);
        if (strlen($word) >= 3 && !in_array($word, $generic, true)
            && preg_match('/(^|[^a-z0-9])' . preg_quote($word, '/') . '([^a-z0-9]|$)/i', (string) $name)) {
            return true;
        }
        return false;
    }

    /** Is this board / university the college's own affiliating university? */
    public static function isOwnUniversity(?string $board, $org = null, ?string $aliasRegex = null): bool
    {
        $org ??= self::current();
        $b = self::norm($board);
        if (!$org || strlen($b) < 3) return false;
        foreach ([$org->university_name ?? '', $org->university_code ?? ''] as $v) {
            $n = self::norm($v);
            if (strlen($n) >= 3 && (str_contains($b, $n) || (strlen($b) >= 8 && str_contains($n, $b)))) return true;
        }
        // Spelling variants of a well-known university (alias regex) apply only
        // when this college's own university is that one.
        return $aliasRegex !== null
            && preg_match($aliasRegex, ($org->university_name ?? '') . ' ' . ($org->university_code ?? ''))
            && preg_match($aliasRegex, (string) $board);
    }

    /** Safe-to-publish subset (login / registration screens, no auth). */
    public static function publicArray($org = null): array
    {
        $org ??= self::current();
        return [
            'name'             => self::name($org, 'College ERP'),
            'short_name'       => self::shortName($org),
            'code'             => $org->code ?? null,
            'address'          => $org->address ?? null,
            'city'             => $org->city ?? null,
            'district'         => $org->district ?? null,
            'state'            => $org->state ?? null,
            'pin_code'         => $org->pin_code ?? null,
            'full_address'     => self::fullAddress($org),
            'phone'            => $org->phone ?? null,
            'mobile'           => $org->mobile ?? null,
            'email'            => $org->email ?? null,
            'website'          => $org->website ?? null,
            'principal_name'   => $org->principal_name ?? null,
            'principal_mobile' => $org->principal_mobile ?? null,
            'university_name'  => $org->university_name ?? null,
            'university_code'  => $org->university_code ?? null,
            'university_college_code' => self::collegeCode($org),
            'tagline'          => $org->tagline ?? null,
            'affiliation_no'   => $org->affiliation_no ?? null,
            'established_year' => $org->established_year ?? null,
            'naac_grade'       => $org->naac_grade ?? null,
            'logo_url'         => self::logoUrl($org),
        ];
    }
}
