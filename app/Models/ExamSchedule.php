<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamSchedule extends Model
{
    protected $fillable = [
        'session_year', 'program_id', 'semester_no', 'exam_mode', 'exam_date',
        'inning', 'exam_start', 'exam_end', 'paper_code', 'subject_id',
    ];

    protected $casts = [
        'exam_date' => 'date',
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
