<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamFormPaper extends Model
{
    protected $fillable = [
        'exam_form_id', 'subject_id', 'paper_code', 'exam_type',
    ];

    public function examForm(): BelongsTo
    {
        return $this->belongsTo(ExamForm::class, 'exam_form_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
