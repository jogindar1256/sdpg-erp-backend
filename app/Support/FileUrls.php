<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Public URLs for stored files without asking the storage server where
 * each file lives on every request.
 *
 * Files are on the 'supabase' disk (all current uploads) or, for older
 * ones, the local 'public' disk. Finding out which used to be a
 * Storage::disk('supabase')->exists() call — one HTTP HEAD request to
 * Supabase Storage per file, per page load. A stored path never moves
 * (upload paths are random), so the answer is looked up once and kept:
 *  - student_application_documents rows: in their own `disk` column;
 *  - any other path (students.photo_path / signature_path): in the cache,
 *    read for a whole batch of paths in one Cache::many() call.
 */
class FileUrls
{
    private const CACHE_PREFIX = 'file-disk:';

    /**
     * Which disk each path is on. $paths: key => path. Returns key => disk,
     * or null for an empty path or when storage couldn't be reached (the
     * caller falls back to 'public', as before; nothing is cached then).
     */
    public static function disks(array $paths): array
    {
        $paths = array_filter($paths, fn ($p) => is_string($p) && $p !== '' && !self::isAbsolute($p));
        if (!$paths) {
            return [];
        }

        $cacheKeys = [];
        foreach (array_unique($paths) as $p) {
            $cacheKeys[$p] = self::CACHE_PREFIX . sha1($p);
        }
        $cached = Cache::many(array_values($cacheKeys));

        $byPath = [];
        $fresh = [];
        foreach ($cacheKeys as $p => $ck) {
            if (is_string($cached[$ck] ?? null)) {
                $byPath[$p] = $cached[$ck];
                continue;
            }
            try {
                $byPath[$p] = Storage::disk('supabase')->exists($p) ? 'supabase' : 'public';
                $fresh[$ck] = $byPath[$p];
            } catch (\Throwable $e) {
                $byPath[$p] = null; // storage unreachable — don't save a guess
            }
        }
        if ($fresh) {
            Cache::putMany($fresh, now()->addYears(10));
        }

        return array_map(fn ($p) => $byPath[$p] ?? null, $paths);
    }

    /** URL of one path on a known disk (null disk = 'public'); absolute URLs pass through. */
    public static function url(?string $path, ?string $disk): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (self::isAbsolute($path)) {
            return $path;
        }
        return Storage::disk($disk ?? 'public')->url($path);
    }

    /** URLs for paths whose disk isn't recorded anywhere. key => path → key => url. */
    public static function urls(array $paths): array
    {
        $disks = self::disks($paths);
        $out = [];
        foreach ($paths as $k => $p) {
            $out[$k] = self::url($p, $disks[$k] ?? null);
        }
        return $out;
    }

    /**
     * Sets ->url on student_application_documents rows. Rows without a
     * recorded disk (uploaded before the column existed) are resolved and
     * the disk saved on them, so that happens once per document ever.
     */
    public static function forDocuments(Collection $docs): Collection
    {
        $unknown = $docs->filter(fn ($d) => empty($d->disk))->pluck('path', 'id')->all();
        $resolved = self::disks($unknown);

        $save = [];
        foreach ($docs as $doc) {
            if (empty($doc->disk) && isset($resolved[$doc->id])) {
                $doc->disk = $resolved[$doc->id];
                $save[$doc->disk][] = $doc->id;
            }
            $doc->url = self::url($doc->path, $doc->disk ?? null);
        }
        foreach ($save as $disk => $ids) {
            DB::table('student_application_documents')->whereIn('id', $ids)->update(['disk' => $disk]);
        }
        return $docs;
    }

    private static function isAbsolute(string $path): bool
    {
        return (bool) preg_match('#^https?://#i', $path);
    }
}
