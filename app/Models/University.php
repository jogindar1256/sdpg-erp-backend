<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class University extends Model
{
    protected $fillable = [
        'name', 'state', 'district', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Autocomplete search by name, optionally narrowed to a state.
     * Used by the public /university/search endpoint that backs the
     * "Name of University" fields on application/registration forms.
     */
    public static function search(string $q, ?string $state = null, int $limit = 20): Collection
    {
        $query = self::query()->where('is_active', true);

        if (trim($q) !== '') {
            $like = '%' . trim($q) . '%';
            $query->whereRaw('name ILIKE ?', [$like]);
        }

        if ($state) {
            $query->whereRaw('state ILIKE ?', [$state]);
        }

        return $query->orderBy('name')->limit($limit)->get();
    }

    /** Distinct list of states that have at least one university (for filters). */
    public static function states(): array
    {
        return self::where('is_active', true)
            ->whereNotNull('state')
            ->where('state', '!=', '')
            ->select('state')
            ->distinct()
            ->orderBy('state')
            ->pluck('state')
            ->toArray();
    }
}
