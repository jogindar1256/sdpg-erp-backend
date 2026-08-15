<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionUpdate extends Model
{
    protected $fillable = [
        'admission_id', 'fee_update_for', 'amount', 'paid_date', 'utr_no',
        'bank_ref_no', 'payment_ref_no', 'gateway_status', 'ref_no',
        'update_date', 'status', 'created_by', 'approved_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_date' => 'date',
        'update_date' => 'date',
    ];

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }
}
