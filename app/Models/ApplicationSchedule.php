<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationSchedule extends Model
{
    protected $fillable = [
        'program_id', 'session_year', 'semester_name', 'semester_no',
        'exam_mode', 'start_admission', 'close_admission',
        'late_fee_applicable', 'late_fee',
    ];

    protected $casts = [
        'start_admission' => 'date',
        'close_admission' => 'date',
        'late_fee_applicable' => 'boolean',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
