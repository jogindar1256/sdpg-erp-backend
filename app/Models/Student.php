<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'user_id', 'enrollment_no', 'university_roll_no',
        'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth',
        'category', 'religion', 'nationality', 'aadhar_no', 'abc_id',
        'mobile', 'alternate_mobile', 'email', 'whatsapp_no',
        'permanent_address', 'permanent_city', 'permanent_district', 'permanent_state', 'permanent_pin',
        'same_as_permanent', 'correspondence_address', 'correspondence_city',
        'correspondence_district', 'correspondence_state', 'correspondence_pin',
        'last_exam_passed', 'last_exam_board', 'last_exam_roll_no', 'last_exam_year',
        'last_exam_percentage', 'last_exam_division',
        'tc_no', 'tc_date', 'tc_issued_by', 'migration_no', 'migration_date',
        'bank_name', 'bank_branch', 'bank_ifsc', 'bank_account_no',
        'photo_path', 'signature_path', 'biometric_id',
        'status', 'is_blocked', 'block_reason',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'tc_date' => 'date',
        'migration_date' => 'date',
        'same_as_permanent' => 'boolean',
        'is_blocked' => 'boolean',
    ];

    protected $appends = ['full_name'];

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(StudentApplication::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function currentAdmission(): HasOne
    {
        return $this->hasOne(Admission::class)->where('status', 'active')->latest();
    }

    public function feeReceipts(): HasMany
    {
        return $this->hasMany(FeeReceipt::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }

    public function semesterRegistrations(): HasMany
    {
        return $this->hasMany(SemesterRegistration::class);
    }

    public function examApplications(): HasMany
    {
        return $this->hasMany(ExamApplication::class);
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(Amendment::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }
}
