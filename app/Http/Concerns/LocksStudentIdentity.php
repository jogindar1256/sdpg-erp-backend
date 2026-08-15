<?php

namespace App\Http\Concerns;

use Illuminate\Support\Facades\Log;

trait LocksStudentIdentity
{
    /**
     * Fields a student may never set/overwrite through self-service endpoints.
     * Covers `students` identity columns + the registration identity snapshot.
     */
    protected array $lockedIdentityFields = [
        // names — students-table / direct_registrations naming
        'name', 'name_hindi', 'first_name', 'middle_name', 'last_name',
        'father_name', 'father_name_hindi', 'mother_name', 'mother_name_hindi',
        // names — application-form (Part1Personal) naming for the same fields
        'name_english', 'father_name_english', 'mother_name_english',
        // demographics
        'dob', 'date_of_birth', 'gender', 'category', 'social_category', 'admission_category',
        'religion', 'nationality',
        // government / unique ids — both the DB column names and the
        // application-form field names (aadhar is split into 3 boxes there)
        'aadhar_no', 'aadhar_1', 'aadhar_2', 'aadhar_3',
        'abc_id', 'ddurn_no', 'ddurn', 'ddurn_id', 'family_id',
        'id_proof_type', 'id_proof_no', 'id_proof_number',
        // caste / eligibility (registration snapshot) — caste_cert_no,
        // eligibility_class, eligibility_roll_no, and is_divyang are
        // deliberately NOT locked (student-corrigible on the application
        // form); caste_cert_date/caste_cert_state/passing_year stay locked.
        'caste_cert_date', 'caste_cert_state', 'passing_year',
        // domicile
        'domestic_state', 'permanent_state',
        // verified contact — changed only through the OTP-gated
        // contact/mobile|email endpoints below, never through a raw
        // part-save. Still locked here as the backstop for updatePart().
        'mobile', 'email',
        // registration / payment identifiers — system-owned
        'registration_no', 'reg_no', 'reg_type', 'payment_status',
        'razorpay_order_id', 'razorpay_payment_id', 'fee_amount', 'receipt_no',
    ];

    /**
     * Remove any locked identity keys from a student-submitted payload.
     * Logs a tamper signal if the client tried to send them (the UI shows these
     * read-only, so their presence means a direct/forged API call).
     */
    protected function stripLockedIdentity(array $data, ?int $userId = null): array
    {
        $attempted = array_keys(array_intersect_key($data, array_flip($this->lockedIdentityFields)));

        if (!empty($attempted)) {
            Log::warning('Blocked locked-identity write attempt by student', [
                'user_id' => $userId,
                'fields'  => $attempted,
            ]);
        }

        return array_diff_key($data, array_flip($this->lockedIdentityFields));
    }
}
