<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Infibrix DLT-registered SMS templates.
 *
 * All DLT template ids are filled in below. To load them:
 *     php artisan db:seed --class=Database\\Seeders\\SmsTemplateSeeder
 *
 * Rows are written to `sms_templates`; `dlt_template_id` is sent to Infibrix as
 * `templateid`. Idempotent — keyed on (organization_id, event_trigger).
 *
 * The message text MUST stay byte-identical to what is registered on DLT
 * (including every {#var#} and existing typos); do not edit the wording.
 */
class SmsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $orgId = DB::table('organizations')->where('is_active', true)->value('id')
            ?? DB::table('organizations')->value('id');

        if (!$orgId) {
            $this->command?->error('No organization found — seed organizations first.');
            return;
        }

        $senderId = env('SMS_SENDER_ID');   // 6-char DLT header, same for all rows

        // event_trigger => [name, dlt_template_id, template text]
        $templates = [
            'otp' => ['Otp SMS', '1207165588316490818',
                'Dear Student, Your OTP is {#var#}. Please do not share this OTP. Regards, Swami Devanand Post Graduate College'],

            'registration_success' => ['College Registration', '1207165622755934399',
                'Dear {#var#}, You have successfully Registered for Class {#var#}. Your Registration No. is {#var#}. Please go for fill application form. Regards, Swami Devanand Post Graduate College'],

            'application_not_filled' => ['Application Form Not Filled', '1207165907596315759',
                'You have registered with registration no. {#var#} On date {#var#} But you not fill and final submit your Application for. Your admission is pending till date. Swami Devanand Post Graduate College'],

            'semester_registration_accepted' => ['Semester Registration Accepted', '1207167722805297034',
                'Accept Registration Form* Your semester registration application Form No {#var#} For class {#var#} Semester {#var#} has been temporarily accepted and it\'s under process. Swami Devendra Post Graduation College Deoria.'],

            'application_approved' => ['Application Form Accepted', '1207165768446161690',
                'Your Application No {#var#} has been approved successfully and Fee payment link has been sent in your ID {#var#} , Please pay Fee. Swami Devanand Post Graduate College'],

            'application_rejected' => ['Application Form Rejected', '1207165768458090315',
                'Your Application No {#var#} has been rejected due to {#var#} . Swami Devanand Post Graduate College'],

            'admission_fee_pending' => ['Admission Fee Not Paid', '1207165907641012952',
                'You have Final Submit Your admission application Form No {#var#} has been Approved for pay education fee by the college Admission Committee but you not pay fee till date. Your admission still pending. Swami Devanand Post Graduate College'],

            'admission_receipt' => ['Final Admission Receipt', '1207165944553371859',
                'Dear {#var#} Your Sudent ID is {#var#} Your Final Admission fee receipt No {#var#} Your Final admission fee receipt has been prepared, please receive it from the college on any working day.Swami Devanand Post Graduation College'],

            'admission_cancelled' => ['Admission Cancelled', '1207165937174382259',
                'As per your request or administrative order, your class {#var#} admission has been cancelled.Swami Devanand Post Graduate College'],

            'password_reset' => ['Password Forget', '1207165768471917615',
                'Dear {#var#} , Your Login ID {#var#} and Password is {#var#}. Swami Devanand Post Graduate College'],

            'profile_updated' => ['Profile Edited', '1207167775009853992',
                'Dear {#var#} your request for editing your profile has been completed. Swami Devendra Post Graduate College Math lar'],

            'subject_changed' => ['Change Of Subject', '1207165937145191544',
                'Your request for change of subject has been accepted, now the subject {#var#} has been provided instead of your previously selected subject {#var#}, Swami Devanand Post Graduate College.'],

            'semester_registration_open' => ['Registration For Semester', '1207167775037837540',
                'Dear {#var#} In session {#var#} Registration for Semester {#var#} and {#var#} without late Fee last date is {#var#} and with late fee last date is {#var#}. Swami Devendra Post Graduate College Math lar'],

            'exam_form_last_date' => ['Exam Form Fill Last Date', '1207165937094668492',
                'Dear Student, SESSION {#var#} of class {#var#} semester of {#var#} (Regular, Back, Improvement, Ex.Student) Examination form fill last date is {#var#} . Swami Devanand Post Graduate College'],

            'exam_form_extended' => ['Exam Form Extended', '1207165937130185745',
                'Dear Student, Pre-Scheduled examination form fill date of Session {#var#} Class {#var#} Semester {#var#} (Regular, Backward, Improvement, Ex-Student) has been extended. Now the last date to fill the exam form is {#var#}. Swami Devanand Post Graduate College'],

            'exam_form_accepted' => ['Exam Form Accepted', '1207165937072737409',
                'Your examination form number {#var#} provisionaly accepted by the college on the date {#var#}, granting permission to appear in the examination is under the University. Swami Devanand Post Graduate College'],

            'exam_start' => ['Exam Will Start', '1207167100040979775',
                'Dear Student, Your {#var#} and {#var#} Exam will be start from {#var#}. Admit Card available on College. SDPG Mathlar.'],

            'exam_start_2' => ['Exam Will Start 2', '1207167100050679130',
                'Dear Student, Your {#var#} Exam will be start from {#var#} , Admit Card available on College. SDPG Mathlar.'],

            'practical_exam' => ['Practical Oral Examination', '1207165937114874226',
                'The practical/oral examination of the class {#var#} semester {#var#} (subject {#var#} ) of the session {#var#} on the date {#var#} Will start at {#var#} o\'clock. You must be present in the laboratory with experimental material and their Exam admit card. Swami Devanand Post Graduate College'],

            'practical_subject' => ['Practical Subject Held', '1207167775017472215',
                'Practical of the subject {#var#} will be held on date {#var#} and {#var#}. Please reach in college till {#var#} with your Exam original Admit Card and practical documents. Swami Devendra Post Graduate College Math lar'],

            'admit_card_available' => ['Admit Card Available', '1207167783636946450',
                'Dear {#var#} your admit card session {#var#} and Semester {#var#} are available in college. Swami Devendra Post Graduate Math Lar, Deoria'],

            'marksheet_available' => ['Marksheet Available', '1207167775004048537',
                'Dear {#var#} your marksheet is available in college. Please receive it any working day. Swami Devendra Post Graduate College Math lar'],

            'tc_issued' => ['Certificate / TC Issued', '1207167774973507204',
                'Dear {#var#} your Transfer Certificate (TC) No.{#var#} has been Issued. Please receive it from the college office on any working day. Swami Devendra Post Graduate College Math Lar, Deoria'],

            'attendance_short' => ['Attendance Too Short', '1207167775044462306',
                'Dear {#var#} your attendance in session {#var#} and Semester {#var#} is too short please mentioned it. Swami Devendra Post Graduate College Math lar'],

            'assignment_submission' => ['Assignment Submission', '1207167775023380480',
                'Dear {#var#} please submit your assignment/project in your subjective department from date{#var#} till {#var#}. Swami Devendra Post Graduate College Math lar'],

            'college_notice' => ['College Notice', '1207166455373096399',
                'Dear Student, Your {#var#} will be start from {#var#} to {#var#}, for more information please contact your subject teacher. Swami Devanand P. G. College, Math Lar'],

            'college_closed' => ['College Closed', '1207167774996272277',
                'College will be closed from {#var#} to {#var#} due to vacation of {#var#}. Swami Devendra Post Graduate College Math lar'],

            'event_start' => ['Function Will Start', '1207167775029985978',
                '{#var#} function will be start from date {#var#} to {#var#} Time {#var#}. Swami Devendra Post Graduate College Math lar'],
        ];

        foreach ($templates as $event => [$name, $dlt, $text]) {
            DB::table('sms_templates')->updateOrInsert(
                ['organization_id' => $orgId, 'event_trigger' => $event],
                [
                    'name'            => $name,
                    'template'        => $text,
                    'dlt_template_id' => $dlt,
                    'sender_id'       => $senderId,
                    'is_active'       => true,
                    'updated_at'      => now(),
                    'created_at'      => now(),
                ]
            );
        }

        $this->command?->info(count($templates) . ' SMS templates seeded.');
    }
}
