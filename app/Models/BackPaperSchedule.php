<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackPaperSchedule extends Model
{
    protected $fillable = [
        'program_id', 'semester', 'session_year', 'start_from', 'end_on',
        'late_fee_applicable', 'late_fee',
    ];

    protected $casts = [
        'start_from' => 'datetime',
        'end_on' => 'datetime',
        'late_fee_applicable' => 'boolean',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
