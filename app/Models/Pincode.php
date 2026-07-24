<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class Pincode extends Model
{
    protected $fillable = [
        'pincode', 'post_office_name', 'district', 'state_name',
    ];

    /**
     * All post offices under a PIN code. A single 6-digit PIN can serve
     * several post offices (B.O./S.O.), which is why this returns a
     * collection rather than a single row.
     */
    public static function findByPincode(string $pincode): Collection
    {
        return self::where('pincode', trim($pincode))
            ->orderBy('post_office_name')
            ->get();
    }

    /**
     * Best-guess district/state for a PIN code, used to auto-fill an
     * address form as soon as the user finishes typing 6 digits. When a
     * PIN spans more than one district (rare, but it happens at borders),
     * the most frequently occurring district/state combination wins and
     * `is_ambiguous` is set so the frontend can still show the post office
     * picker instead of silently guessing.
     */
    public static function resolve(string $pincode): ?array
    {
        $rows = self::findByPincode($pincode);

        if ($rows->isEmpty()) {
            return null;
        }

        $grouped = $rows->groupBy(fn ($r) => $r->district . '|' . $r->state_name)
            ->sortByDesc(fn ($g) => $g->count());

        /** @var Collection $top */
        $top = $grouped->first();
        $first = $top->first();

        return [
            'pincode'        => trim($pincode),
            'district'       => $first->district,
            'state'          => $first->state_name,
            'is_ambiguous'   => $grouped->count() > 1,
            'post_offices'   => $rows->pluck('post_office_name')->values()->all(),
            'total_matches'  => $rows->count(),
        ];
    }

    /** Distinct states — used to seed a State dropdown from real data. */
    public static function states(): array
    {
        return self::whereNotNull('state_name')
            ->where('state_name', '!=', '')
            ->select('state_name')
            ->distinct()
            ->orderBy('state_name')
            ->pluck('state_name')
            ->toArray();
    }

    /** Distinct districts for a given state — used for a dependent District dropdown. */
    public static function districtsForState(string $state): array
    {
        return self::whereRaw('state_name ILIKE ?', [$state])
            ->whereNotNull('district')
            ->where('district', '!=', '')
            ->select('district')
            ->distinct()
            ->orderBy('district')
            ->pluck('district')
            ->toArray();
    }
}
