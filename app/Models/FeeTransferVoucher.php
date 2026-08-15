<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeTransferVoucher extends Model
{
    protected $fillable = [
        'admission_id', 'session', 'grand_total', 'amount_in_words',
        'ref_no', 'transfer_amount', 'transfer_date', 'transfer_through',
        'instrument_no', 'instrument_date', 'created_by',
    ];

    protected $casts = [
        'grand_total' => 'decimal:2',
        'transfer_amount' => 'decimal:2',
        'transfer_date' => 'date',
        'instrument_date' => 'date',
    ];

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FeeTransferVoucherItem::class, 'voucher_id');
    }
}
