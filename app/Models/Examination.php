<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Examination extends Model
{
    protected $fillable = [
        'organization_id', 'program_id', 'academic_year', 'semester_no',
        'exam_name', 'exam_type', 'start_date', 'end_date',
        'form_start_date', 'form_end_date', 'late_form_date',
        'exam_fee', 'late_fee', 'status',
    ];

    protected $casts = [
        'start_date'      => 'date',
        'end_date'        => 'date',
        'form_start_date' => 'date',
        'form_end_date'   => 'date',
        'late_form_date'  => 'date',
        'exam_fee'        => 'decimal:2',
        'late_fee'        => 'decimal:2',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ExamApplication::class);
    }

    public function isFormOpen(): bool
    {
        $today = now()->toDateString();
        return $this->form_start_date <= $today
            && ($this->late_form_date ?? $this->form_end_date) >= $today;
    }

    public function isLate(): bool
    {
        return $this->form_end_date < now()->toDateString()
            && $this->late_form_date >= now()->toDateString();
    }
}