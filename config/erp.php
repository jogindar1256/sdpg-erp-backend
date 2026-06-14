<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SDPG ERP Global Configuration
    |--------------------------------------------------------------------------
    */

    'app_name'    => 'SDPG College ERP',
    'version'     => '1.0.0',
    'academic_year_start_month' => 7, // July

    /*
    | Current academic year (auto-calculated, can be overridden)
    */
    'current_academic_year' => (function () {
        $month = (int) date('n');
        $year  = (int) date('Y');
        if ($month >= 7) {
            return $year . '-' . ($year + 1);
        }
        return ($year - 1) . '-' . $year;
    })(),

    /*
    | Student enrollment number format
    | {ORG_CODE}-{YEAR}-{SEQUENCE}
    */
    'enrollment_prefix'  => 'SDPG',
    'enrollment_padding' => 5,

    /*
    | Portals
    */
    'portals' => ['college', 'student', 'university', 'super_admin'],

    /*
    | Roles
    */
    'roles' => [
        'super_admin'    => 'Super Administrator',
        'college_admin'  => 'College Administrator',
        'staff'          => 'Staff',
        'accounts_staff' => 'Accounts Staff',
        'exam_staff'     => 'Examination Staff',
        'university_admin' => 'University Administrator',
        'student'        => 'Student',
    ],

    /*
    | Fee receipt number format
    | {PREFIX}-{ORG_ID}-{YEAR}-{SEQUENCE}
    */
    'receipt_prefixes' => [
        'regular_admission' => 'FR',
        'back_paper'        => 'BP',
        'semester_upgrade'  => 'SU',
        'miscellaneous'     => 'MS',
    ],

    /*
    | Document types allowed for upload
    */
    'document_types' => [
        'photo'              => ['jpg', 'jpeg', 'png'],
        'signature'          => ['jpg', 'jpeg', 'png'],
        'aadhar'             => ['jpg', 'jpeg', 'png', 'pdf'],
        'marksheet_10'       => ['jpg', 'jpeg', 'png', 'pdf'],
        'marksheet_12'       => ['jpg', 'jpeg', 'png', 'pdf'],
        'marksheet_grad'     => ['jpg', 'jpeg', 'png', 'pdf'],
        'tc'                 => ['jpg', 'jpeg', 'png', 'pdf'],
        'migration'          => ['jpg', 'jpeg', 'png', 'pdf'],
        'caste_certificate'  => ['jpg', 'jpeg', 'png', 'pdf'],
        'income_certificate' => ['jpg', 'jpeg', 'png', 'pdf'],
        'bank_passbook'      => ['jpg', 'jpeg', 'png', 'pdf'],
    ],

    /*
    | File size limits (in KB)
    */
    'max_file_sizes' => [
        'photo'     => 500,
        'signature' => 200,
        'document'  => 2048,
    ],

    /*
    | SMS configuration
    */
    'sms' => [
        'provider'  => env('SMS_PROVIDER', 'twilio'),  // twilio | msg91 | textlocal
        'sender_id' => env('SMS_SENDER_ID', 'SDPGCL'),
    ],

    /*
    | PDF generation
    */
    'pdf' => [
        'paper'       => 'A4',
        'orientation' => 'portrait',
        'dpi'         => 150,
    ],

];
