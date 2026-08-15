<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubjectPaper extends Model
{
    protected $fillable = [
        'program_id', 'subject_id', 'paper_code', 'session_year', 'semester_no',
        'paper_type', 'paper_name', 'group_no', 'group_label', 'max_marks', 'min_marks',
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
