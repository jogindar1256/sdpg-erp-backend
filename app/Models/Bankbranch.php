<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankBranch extends Model
{
    protected $fillable = [
        'bank_name', 'ifsc_code', 'micr_code', 'branch_name',
        'address', 'city', 'district', 'state', 'phone',
    ];

    /**
     * Lookup by IFSC code — used in student bank detail forms.
     */
    public static function findByIfsc(string $ifsc): ?self
    {
        return self::where('ifsc_code', strtoupper($ifsc))->first();
    }

    /**
     * Get all unique bank names for dropdown.
     */
    public static function bankNames(): array
    {
        return self::distinct()->orderBy('bank_name')->pluck('bank_name')->toArray();
    }
}