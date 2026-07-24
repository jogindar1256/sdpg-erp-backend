<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'short_name', 'code', 'level',
        'duration_years', 'total_semesters', 'semester_type', 'description', 'is_active',
        // Real columns added by later migrations that were never added here —
        // meant these fields silently dropped out of every mass-assignment
        // (Program::create()/update()) even though the columns existed.
        'full_name', 'course_code', 'is_self_finance', 'approval_type', 'exam_mode',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    public function feeStructures(): HasMany
    {
        return $this->hasMany(FeeStructure::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(StudentApplication::class);
    }

    // Get subjects for a specific semester
    public function semesterSubjects(int $semesterNo): HasMany
    {
        return $this->subjects()->where('semester_no', $semesterNo);
    }
}
