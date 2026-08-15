<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VocationalPaper extends Model
{
    protected $fillable = [
        'program_id', 'session_year', 'semester_no', 'group_no', 'group_name',
        'max_select', 'min_select', 'paper_code', 'paper_name', 'max_marks', 'min_marks',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
