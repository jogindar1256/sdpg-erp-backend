<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeStructure extends Model
{
    protected $fillable = [
        'organization_id', 'program_id', 'fee_head_id', 'semester_no',
        'academic_year', 'admission_type', 'amount', 'late_fine_per_day',
        'due_date', 'is_active',
    ];

    protected $casts = [
        'due_date'  => 'date',
        'is_active' => 'boolean',
        'amount'    => 'decimal:2',
        'late_fine_per_day' => 'decimal:2',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function feeHead(): BelongsTo
    {
        return $this->belongsTo(FeeHead::class);
    }
}