<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicStaff extends Model
{
    use SoftDeletes;

    protected $table = 'academic_staff';

    /** Statuses that count as "working" (is_active). Anything else = left / retired. */
    public const ACTIVE_STATUSES = ['Continue', 'WP'];
    public const STATUSES = ['Continue', 'WP', 'Retired', 'Resigned', 'Transferred', 'Terminated', 'Deceased'];
    public const DOC_TYPES = ['pan_card', 'aadhar_card', 'approval_letter', 'joining_letter', 'bank_passbook'];

    protected $fillable = [
        'organization_id', 'employee_id', 'name', 'father_name', 'spouse_name', 'category', 'nationality',
        'domestic_state', 'blood_group', 'dob', 'joining_date', 'retirement_date', 'designation', 'department',
        'departmental_designation', 'aadhar_no', 'pan_no', 'pension_scheme', 'pran_no', 'mobile', 'alt_mobile',
        'email', 'bank_name', 'bank_branch', 'bank_account_no', 'bank_ifsc', 'status', 'status_reason',
        'status_ref_no', 'perm_village', 'perm_post', 'perm_police_station', 'perm_district', 'perm_state',
        'perm_pincode', 'local_address', 'photo_path', 'signature_path', 'remarks',
    ];

    protected $casts = ['dob' => 'date:Y-m-d', 'joining_date' => 'date:Y-m-d', 'retirement_date' => 'date:Y-m-d'];

    public function documents(): HasMany
    {
        return $this->hasMany(AcademicStaffDocument::class);
    }

    public function getIsActiveAttribute(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
