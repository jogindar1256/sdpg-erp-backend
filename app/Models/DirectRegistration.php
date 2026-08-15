<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DirectRegistration extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'program_id', 'user_id', 'student_id',
        'registration_no', 'reg_type', 'session_year', 'reg_date',
        'ddurn_no', 'abc_id', 'family_id',
        'major_subject_1', 'major_subject_2', 'major_subject_3', 'minor_subject_1', 'subject_id',
        'name', 'name_hindi', 'id_proof_type', 'id_proof_no',
        'father_name', 'father_name_hindi', 'mother_name', 'mother_name_hindi',
        'dob', 'gender', 'domestic_state',
        'category', 'admission_category', 'religion', 'nationality',
        'caste_cert_no', 'eligibility_class', 'is_divyang', 'caste_cert_date',
        'passing_year', 'aadhar_no', 'caste_cert_state', 'eligibility_roll_no',
        'email', 'mobile',
        'ug_university', 'ug_institute', 'ug_session', 'ug_roll_no',
        'stream', 'entrance_session', 'entrance_roll_no', 'state_rank', 'category_rank', 'cut_off',
        'phone_verified', 'email_verified',
        'payment_status', 'razorpay_order_id', 'razorpay_payment_id', 'paid_at',
        'temp_password', 'temp_password_plain',
        'status', 'approved_by', 'approved_at', 'remarks',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
        'fee_amount', 'course_group', 'receipt_no', 'pdf_path',
    ];

    protected $casts = [
        'reg_date' => 'date',
        'caste_cert_date' => 'date',
        'phone_verified' => 'boolean',
        'email_verified' => 'boolean',
        'paid_at' => 'datetime',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'fee_amount' => 'decimal:2',
    ];

    protected $hidden = [
        'temp_password', 'temp_password_plain',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
