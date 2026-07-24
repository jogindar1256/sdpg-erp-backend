<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class BankBranch extends Model
{
    protected $fillable = [
        'bank_name', 'ifsc_code', 'micr_code', 'branch_name',
        'address', 'city', 'district', 'state', 'phone',
    ];

    /**
     * All branches for an IFSC. Can be 1 row (SBI etc.) or thousands (RRBs).
     */
    public static function findAllByIfsc(string $ifsc, int $limit = 50): Collection
    {
        return self::where('ifsc_code', strtoupper(trim($ifsc)))
            ->orderBy('branch_name')
            ->limit($limit)
            ->get();
    }

    /**
     * True when this IFSC maps to more than one branch.
     */
    public static function ifscIsShared(string $ifsc): bool
    {
        return self::where('ifsc_code', strtoupper(trim($ifsc)))->count() > 1;
    }

    public static function findByIfsc(string $ifsc): ?self
    {
        return self::where('ifsc_code', strtoupper(trim($ifsc)))
            ->orderBy('branch_name')
            ->first();
    }

    /**
     * Unique bank names for dropdown.
     */
    public static function bankNames(): array
    {
        return self::select('bank_name')
            ->distinct()
            ->orderBy('bank_name')
            ->pluck('bank_name')
            ->toArray();
    }
}