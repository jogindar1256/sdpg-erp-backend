<?php

namespace App\Support;

/**
 * Pre-rendered Devanagari labels for the exam forms (P-4/P-6/P-7/P-9).
 * dompdf cannot shape Devanagari, so the static Hindi text is drawn to PNG by
 * tools/make_hindi_assets.py (proper OpenType shaping) and embedded as images.
 */
class HindiAsset
{
    private static ?array $manifest = null;
    private static array $uri = [];

    private static function manifest(): array
    {
        return self::$manifest ??= (json_decode((string) @file_get_contents(resource_path('pdf-assets/hindi/manifest.json')), true) ?: []);
    }

    /** <img> for a label; $scale multiplies the natural CSS-px size. */
    public static function img(string $key, float $scale = 1.0, string $style = ''): string
    {
        $m = self::manifest()[$key] ?? null;
        if (!$m) return '';
        self::$uri[$key] ??= 'data:image/png;base64,' . base64_encode((string) file_get_contents(resource_path("pdf-assets/hindi/$key.png")));
        return sprintf('<img src="%s" width="%s" height="%s" style="%s" alt="">', self::$uri[$key], round($m[0] * $scale, 1), round($m[1] * $scale, 1), $style);
    }

    /** The university emblem (cropped from the university's own form). */
    public static function logo(int $height = 56): string
    {
        $p = resource_path('pdf-assets/ddu-logo.png');
        if (!is_file($p)) return '';
        self::$uri['__logo'] ??= 'data:image/png;base64,' . base64_encode((string) file_get_contents($p));
        return sprintf('<img src="%s" height="%d" alt="">', self::$uri['__logo'], $height);
    }
}
