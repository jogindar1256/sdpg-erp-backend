<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable;

    // Roles: super_admin | college_admin | staff | student | university_admin
    // Permissions: manage-students, manage-admissions, manage-fees, generate-receipts,
    //              verify-admissions, approve-amendments, manage-exams, generate-certificates,
    //              manage-settings, view-reports

    protected $fillable = [
        'organization_id', 'name', 'email', 'mobile', 'password',
        'portal', 'employee_id', 'department', 'designation',
        'is_active', 'last_login_at', 'last_login_ip',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'password' => 'hashed',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    // Check if user belongs to a specific portal
    public function isStudentPortal(): bool
    {
        return $this->portal === 'student';
    }

    public function isCollegePortal(): bool
    {
        return $this->portal === 'college';
    }

    public function isUniversityPortal(): bool
    {
        return $this->portal === 'university';
    }
}
