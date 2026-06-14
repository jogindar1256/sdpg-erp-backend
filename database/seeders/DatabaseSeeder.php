<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Organization;
use App\Models\User;
use App\Models\Program;
use App\Models\Subject;
use App\Models\FeeHead;
use App\Models\FeeStructure;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Roles & Permissions ─────────────────────────────────────────
        $permissions = [
            'manage-students', 'manage-admissions', 'manage-fees',
            'generate-receipts', 'verify-admissions', 'approve-amendments',
            'manage-exams', 'generate-certificates', 'manage-settings',
            'view-reports', 'manage-users', 'block-students',
            'verify-fee-receipts', 'cancel-admissions',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $roles = [
            'super_admin'      => $permissions,  // all
            'college_admin'    => ['manage-students', 'manage-admissions', 'manage-fees', 'generate-receipts', 'verify-admissions', 'approve-amendments', 'manage-exams', 'generate-certificates', 'manage-settings', 'view-reports', 'manage-users', 'block-students', 'verify-fee-receipts', 'cancel-admissions'],
            'staff'            => ['manage-students', 'manage-admissions', 'generate-receipts', 'view-reports'],
            'accounts_staff'   => ['manage-fees', 'generate-receipts', 'verify-fee-receipts', 'view-reports'],
            'exam_staff'       => ['manage-exams', 'generate-certificates', 'view-reports'],
            'university_admin' => ['view-reports'],
            'student'          => [],
        ];

        foreach ($roles as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($perms);
        }

        // ── 2. Organization ────────────────────────────────────────────────
        $org = Organization::firstOrCreate(
            ['code' => 'SDPG'],
            [
                'name'             => 'Swami Dewanand PG College',
                'short_name'       => 'SDPG College',
                'type'             => 'college',
                'affiliation_no'   => 'SDPG-001',
                'address'          => 'Main Campus, College Road',
                'city'             => 'Meerut',
                'district'         => 'Meerut',
                'state'            => 'Uttar Pradesh',
                'pin_code'         => '250001',
                'university_name'  => 'DDU University',
                'university_code'  => 'DDU',
                'is_active'        => true,
            ]
        );

        // ── 3. Users ───────────────────────────────────────────────────────
        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@sdpgcollege.ac.in'],
            [
                'organization_id' => $org->id,
                'name'            => 'Super Administrator',
                'mobile'          => '9999999999',
                'password'        => Hash::make('Admin@1234'),
                'portal'          => 'super_admin',
                'is_active'       => true,
            ]
        );
        $superAdmin->assignRole('super_admin');

        $collegeAdmin = User::firstOrCreate(
            ['email' => 'principal@sdpgcollege.ac.in'],
            [
                'organization_id' => $org->id,
                'name'            => 'College Administrator',
                'mobile'          => '9988776655',
                'password'        => Hash::make('College@1234'),
                'portal'          => 'college',
                'designation'     => 'Principal',
                'is_active'       => true,
            ]
        );
        $collegeAdmin->assignRole('college_admin');

        $accountsStaff = User::firstOrCreate(
            ['email' => 'accounts@sdpgcollege.ac.in'],
            [
                'organization_id' => $org->id,
                'name'            => 'Accounts Staff',
                'mobile'          => '9876543210',
                'password'        => Hash::make('Accounts@1234'),
                'portal'          => 'college',
                'designation'     => 'Accounts Officer',
                'is_active'       => true,
            ]
        );
        $accountsStaff->assignRole('accounts_staff');

        // ── 4. Programs ────────────────────────────────────────────────────
        $programs = [
            ['name' => 'Bachelor of Arts',      'short_name' => 'BA',    'code' => 'BA',    'level' => 'UG',   'duration_years' => 3, 'total_semesters' => 6],
            ['name' => 'Bachelor of Science',   'short_name' => 'BSc',   'code' => 'BSCI',  'level' => 'UG',   'duration_years' => 3, 'total_semesters' => 6],
            ['name' => 'Bachelor of Commerce',  'short_name' => 'BCom',  'code' => 'BCOM',  'level' => 'UG',   'duration_years' => 3, 'total_semesters' => 6],
            ['name' => 'Master of Arts',        'short_name' => 'MA',    'code' => 'MA',    'level' => 'PG',   'duration_years' => 2, 'total_semesters' => 4],
            ['name' => 'Master of Commerce',    'short_name' => 'MCom',  'code' => 'MCOM',  'level' => 'PG',   'duration_years' => 2, 'total_semesters' => 4],
            ['name' => 'Master of Science',     'short_name' => 'MSc',   'code' => 'MSCI',  'level' => 'PG',   'duration_years' => 2, 'total_semesters' => 4],
            ['name' => 'Bachelor of Education', 'short_name' => 'B.Ed',  'code' => 'BED',   'level' => 'BEd',  'duration_years' => 2, 'total_semesters' => 4],
        ];

        foreach ($programs as $prog) {
            Program::firstOrCreate(
                ['code' => $prog['code'], 'organization_id' => $org->id],
                array_merge($prog, ['organization_id' => $org->id, 'semester_type' => 'semester', 'is_active' => true])
            );
        }

        // ── 5. Fee Heads ───────────────────────────────────────────────────
        $feeHeads = [
            ['name' => 'Tuition Fee',         'code' => 'TF',   'category' => 'tuition',       'is_mandatory' => true],
            ['name' => 'Examination Fee',      'code' => 'EF',   'category' => 'exam',          'is_mandatory' => true],
            ['name' => 'Library Fee',          'code' => 'LF',   'category' => 'library',       'is_mandatory' => true],
            ['name' => 'Sports Fee',           'code' => 'SF',   'category' => 'miscellaneous', 'is_mandatory' => true],
            ['name' => 'Development Fee',      'code' => 'DF',   'category' => 'miscellaneous', 'is_mandatory' => true],
            ['name' => 'Registration Fee',     'code' => 'RF',   'category' => 'miscellaneous', 'is_mandatory' => true],
            ['name' => 'University Fee',       'code' => 'UF',   'category' => 'miscellaneous', 'is_mandatory' => true],
            ['name' => 'Caution Money',        'code' => 'CM',   'category' => 'miscellaneous', 'is_refundable' => true, 'is_mandatory' => false],
        ];

        foreach ($feeHeads as $fh) {
            FeeHead::firstOrCreate(
                ['code' => $fh['code'], 'organization_id' => $org->id],
                array_merge($fh, ['organization_id' => $org->id, 'is_active' => true, 'is_refundable' => $fh['is_refundable'] ?? false])
            );
        }

        // ── 6. SMS Templates ──────────────────────────────────────────────
        $templates = [
            ['name' => 'Admission Approved',  'event_trigger' => 'admission_approved',  'template' => 'Dear {student_name}, your admission for {program} Sem-{semester} has been approved. Enrollment No: {enrollment_no}. SDPG College'],
            ['name' => 'Fee Receipt Generated','event_trigger' => 'fee_paid',           'template' => 'Dear {student_name}, fee receipt {receipt_no} of Rs. {amount} generated for {academic_year}. SDPG College'],
            ['name' => 'Application Submitted','event_trigger' => 'application_submitted','template' => 'Dear {student_name}, your application {app_no} submitted successfully. Track status on portal. SDPG College'],
            ['name' => 'Exam Form Submitted',  'event_trigger' => 'exam_form_submitted', 'template' => 'Dear {student_name}, exam form for {exam_name} submitted. Form No: {form_no}. SDPG College'],
        ];

        foreach ($templates as $t) {
            \App\Models\SmsTemplate::firstOrCreate(
                ['event_trigger' => $t['event_trigger'], 'organization_id' => $org->id],
                array_merge($t, ['organization_id' => $org->id, 'is_active' => true])
            );
        }

        $this->command->info('✅ Database seeded successfully!');
        $this->command->info('   Super Admin: admin@sdpgcollege.ac.in / Admin@1234');
        $this->command->info('   College Admin: principal@sdpgcollege.ac.in / College@1234');
        $this->command->info('   Accounts Staff: accounts@sdpgcollege.ac.in / Accounts@1234');
    }
}
