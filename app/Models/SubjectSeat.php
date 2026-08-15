<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubjectSeat extends Model
{
    protected $fillable = [
        'program_id', 'subject_id', 'allotted_seat', 'order_ref', 'varg_bridhi',
        'total_seat', 'permission_type', 'period_session', 'status',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
