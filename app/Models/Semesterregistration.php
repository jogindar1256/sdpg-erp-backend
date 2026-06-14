<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SemesterRegistration extends Model
{
    protected $fillable = [
        'organization_id', 'student_id', 'admission_id', 'program_id',
        'academic_year', 'semester_no', 'registration_type',
        'registration_no', 'registration_date',
        'registered_subjects', 'back_paper_subjects',
        'status', 'approved_by', 'approved_at', 'remarks',
    ];

    protected $casts = [
        'registration_date'    => 'date',
        'approved_at'          => 'datetime',
        'registered_subjects'  => 'array',
        'back_paper_subjects'  => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public static function generateRegistrationNo(int $orgId, string $academicYear, int $semNo): string
    {
        $year  = str_replace('-', '', $academicYear);
        $count = self::where('organization_id', $orgId)
                     ->where('academic_year', $academicYear)
                     ->where('semester_no', $semNo)
                     ->count() + 1;
        return "REG-{$orgId}-{$year}-S{$semNo}-" . str_pad($count, 5, '0', STR_PAD_LEFT);
    }
}