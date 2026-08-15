<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissionCondition extends Model
{
    protected $fillable = [
        'program_id', 'session_year', 'semester_no', 'qualifying_class',
        'condition_type', 'allotted_seat',
        'required_percent_gen', 'required_percent_obc',
        'required_percent_sc', 'required_percent_st', 'required_percent_ews',
        'category_requirements',
        'is_blocked',
    ];

    protected $casts = [
        'is_blocked' => 'boolean',
        'category_requirements' => 'array',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
