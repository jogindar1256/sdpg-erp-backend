<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamForm extends Model
{
    protected $fillable = [
        'admission_id', 'session_year', 'semester_no', 'exam_type',
        'center_code', 'status', 'form_id', 'result', 'marksheet_available',
    ];

    protected $casts = [
        'marksheet_available' => 'boolean',
    ];

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function papers(): HasMany
    {
        return $this->hasMany(ExamFormPaper::class, 'exam_form_id');
    }
}
