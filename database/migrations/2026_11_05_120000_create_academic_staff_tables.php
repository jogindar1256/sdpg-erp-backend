<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Academic Staff master (college/organization/staff). Teaching and
 * non-teaching staff; Employee ID is typed (hHRMS ID for existing staff).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_staff', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('employee_id', 30);
            $t->string('name', 150);
            $t->string('father_name', 150)->nullable();
            $t->string('spouse_name', 150)->nullable();
            $t->string('category', 20)->nullable();            // General / OBC / SC / ST / EWS
            $t->string('nationality', 30)->default('Indian');
            $t->string('domestic_state', 60)->nullable();
            $t->string('blood_group', 5)->nullable();
            $t->date('dob')->nullable();
            $t->date('joining_date')->nullable();
            $t->date('retirement_date')->nullable();
            $t->string('designation', 100);
            $t->string('department', 100);
            $t->string('departmental_designation', 60)->nullable();
            $t->string('aadhar_no', 12)->nullable();
            $t->string('pan_no', 10)->nullable();
            $t->string('pension_scheme', 10)->nullable();       // NPS / OPS
            $t->string('pran_no', 20)->nullable();
            $t->string('mobile', 15)->nullable();
            $t->string('alt_mobile', 15)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('bank_name', 100)->nullable();
            $t->string('bank_branch', 100)->nullable();
            $t->string('bank_account_no', 30)->nullable();
            $t->string('bank_ifsc', 11)->nullable();
            $t->string('status', 30)->default('Continue');      // Continue / WP / Retired / ...
            $t->string('status_reason', 255)->nullable();
            $t->string('status_ref_no', 60)->nullable();
            $t->string('perm_village', 200)->nullable();        // Mohalla / Village
            $t->string('perm_post', 100)->nullable();
            $t->string('perm_police_station', 100)->nullable();
            $t->string('perm_district', 100)->nullable();
            $t->string('perm_state', 60)->nullable();
            $t->string('perm_pincode', 6)->nullable();
            $t->text('local_address')->nullable();
            $t->string('photo_path')->nullable();
            $t->string('signature_path')->nullable();
            $t->text('remarks')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['organization_id', 'name']);
            $t->index(['organization_id', 'department']);
        });

        // One live row per Employee ID per college (soft-deleted rows don't block re-use).
        DB::statement('CREATE UNIQUE INDEX academic_staff_org_employee_unique ON academic_staff (organization_id, employee_id) WHERE deleted_at IS NULL');

        Schema::create('academic_staff_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('academic_staff_id')->constrained('academic_staff')->cascadeOnDelete();
            $t->string('doc_type', 30);                         // pan_card, aadhar_card, approval_letter, joining_letter, bank_passbook
            $t->string('original_name');
            $t->string('path');
            $t->string('mime', 100)->nullable();
            $t->unsignedInteger('size')->default(0);
            $t->timestamps();

            $t->unique(['academic_staff_id', 'doc_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_staff_documents');
        Schema::dropIfExists('academic_staff');
    }
};
