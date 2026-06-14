<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Admission extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'student_id', 'program_id', 'application_id',
        'academic_year', 'semester_no', 'admission_type',
        'admission_no', 'admission_date',
        'is_verified', 'verified_by', 'verified_at',
        'status', 'cancel_reason', 'cancel_date', 'cancelled_by',
    ];

    protected $casts = [
        'admission_date' => 'date',
        'cancel_date' => 'date',
        'verified_at' => 'datetime',
        'is_verified' => 'boolean',
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

    public function application(): BelongsTo
    {
        return $this->belongsTo(StudentApplication::class, 'application_id');
    }

    public function feeReceipts(): HasMany
    {
        return $this->hasMany(FeeReceipt::class);
    }

    public function semesterRegistrations(): HasMany
    {
        return $this->hasMany(SemesterRegistration::class);
    }

    public static function generateAdmissionNo(int $orgId, string $academicYear): string
    {
        $year = str_replace('-', '', $academicYear);
        $count = self::where('organization_id', $orgId)
                     ->where('academic_year', $academicYear)
                     ->count() + 1;
        return "ADM-{$orgId}-{$year}-" . str_pad($count, 5, '0', STR_PAD_LEFT);
    }
}
