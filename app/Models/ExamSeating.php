<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamSeating extends Model
{
    protected $fillable = [
        'session_year', 'exam_date', 'student_id', 'paper_code',
        'room_id', 'row_no', 'col_no',
    ];

    protected $casts = [
        'exam_date' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ExamRoom::class, 'room_id');
    }
}
