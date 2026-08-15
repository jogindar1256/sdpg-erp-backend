<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeTransferVoucherItem extends Model
{
    protected $fillable = [
        'voucher_id', 'fee_type', 'bank_account', 'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(FeeTransferVoucher::class, 'voucher_id');
    }
}
