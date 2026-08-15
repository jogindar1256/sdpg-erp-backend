<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationFee extends Model
{
    protected $fillable = [
        'program_id', 'session_year', 'semester_no', 'registration_mode', 'amounts',
    ];

    protected $casts = [
        'amounts' => 'array',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
