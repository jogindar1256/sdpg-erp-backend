<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentApplication extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'student_id', 'program_id', 'academic_year',
        'application_no', 'application_type', 'semester_no',
        'selected_subjects', 'selected_optional_subjects',
        'declaration_accepted', 'declaration_at',
        'status', 'rejection_reason', 'remarks',
        'reviewed_by', 'reviewed_at', 'approved_by', 'approved_at',
        'form_progress',
    ];

    protected $casts = [
        'selected_subjects' => 'array',
        'selected_optional_subjects' => 'array',
        'form_progress' => 'array',
        'declaration_accepted' => 'boolean',
        'declaration_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function admission(): HasOne
    {
        return $this->hasOne(Admission::class, 'application_id');
    }

    // Check if all form parts are complete
    public function isFormComplete(): bool
    {
        $required = ['part1', 'part2', 'part3', 'part4', 'part5', 'part6', 'part7', 'part8'];
        foreach ($required as $part) {
            if (empty($this->form_progress[$part])) return false;
        }
        return true;
    }

    // Generate unique application number
    public static function generateApplicationNo(string $academicYear, int $orgId): string
    {
        $year = str_replace('-', '', $academicYear);
        $count = self::where('organization_id', $orgId)
                     ->where('academic_year', $academicYear)
                     ->count() + 1;
        return "APP-{$orgId}-{$year}-" . str_pad($count, 6, '0', STR_PAD_LEFT);
    }
}
