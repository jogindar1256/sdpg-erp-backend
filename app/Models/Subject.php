<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'program_id', 'name', 'code', 'semester_no', 'type',
        'paper_type', 'max_marks', 'min_marks', 'internal_marks',
        'credits', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function examApplications(): HasMany
    {
        return $this->hasMany(ExamApplication::class);
    }
}