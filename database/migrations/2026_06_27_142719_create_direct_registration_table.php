<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_registrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('program_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index(); // set after account creation
            $table->unsignedBigInteger('student_id')->nullable()->index();

            // Registration Identity
            $table->string('registration_no', 20)->unique();
            $table->string('reg_type', 5)->comment('UG|PG|BED');
            $table->string('session_year', 12)->index();
            $table->date('reg_date')->nullable();

            // IDs / References
            $table->string('ddurn_no', 50)->nullable();
            $table->string('abc_id', 50)->nullable();
            $table->string('family_id', 50)->nullable();

            // Subjects (UG)
            $table->unsignedBigInteger('major_subject_1')->nullable();
            $table->unsignedBigInteger('major_subject_2')->nullable();
            $table->unsignedBigInteger('major_subject_3')->nullable();
            $table->unsignedBigInteger('minor_subject_1')->nullable();

            // Subject (PG — single subject)
            $table->unsignedBigInteger('subject_id')->nullable();

            // Personal Details
            $table->string('name', 200);
            $table->string('name_hindi', 200)->nullable();
            $table->string('id_proof_type', 50)->nullable();
            $table->string('id_proof_no', 50)->nullable();
            $table->string('father_name', 200);
            $table->string('father_name_hindi', 200)->nullable();
            $table->string('mother_name', 200);
            $table->string('mother_name_hindi', 200)->nullable();
            $table->string('dob', 20)->nullable();
            $table->string('gender', 15)->nullable();
            $table->string('domestic_state', 50)->nullable();

            // Category / Religion
            $table->string('category', 20)->nullable();
            $table->string('admission_category', 30)->nullable();
            $table->string('religion', 30)->nullable();
            $table->string('nationality', 30)->nullable()->default('Indian');

            // Caste / Eligibility
            $table->string('caste_cert_no', 50)->nullable();
            $table->string('eligibility_class', 100)->nullable();
            $table->string('is_divyang', 5)->nullable()->default('No');
            $table->date('caste_cert_date')->nullable();
            $table->string('passing_year', 6)->nullable();
            $table->string('aadhar_no', 12)->nullable();
            $table->string('caste_cert_state', 50)->nullable();
            $table->string('eligibility_roll_no', 50)->nullable();

            // Contact
            $table->string('email', 100);
            $table->string('mobile', 15);

            // PG — Previous Education
            $table->string('ug_university', 150)->nullable();
            $table->string('ug_institute', 150)->nullable();
            $table->string('ug_session', 12)->nullable();
            $table->string('ug_roll_no', 50)->nullable();

            // BEd-specific
            $table->string('stream', 50)->nullable();
            $table->string('entrance_session', 12)->nullable();
            $table->string('entrance_roll_no', 50)->nullable();
            $table->string('state_rank', 20)->nullable();
            $table->string('category_rank', 20)->nullable();
            $table->string('cut_off', 20)->nullable();

            // Verification
            $table->boolean('phone_verified')->default(false);
            $table->boolean('email_verified')->default(false);

            // Payment
            $table->string('payment_status', 15)->default('pending')->comment('pending|paid|failed');
            $table->string('razorpay_order_id', 100)->nullable();
            $table->string('razorpay_payment_id', 100)->nullable();
            $table->timestamp('paid_at')->nullable();

            // Credentials (temp — cleared after sending email)
            $table->string('temp_password')->nullable()->comment('Hashed password');
            $table->string('temp_password_plain', 20)->nullable()->comment('Cleared after email sent');

            // Admin fields
            $table->string('status', 20)->default('pending')->comment('pending|registered|approved|rejected|incomplete');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('remarks')->nullable();

            // Cancellation — cancelling lets the same mobile/aadhar/abc_id
            // register again for the same program/type (otherwise it's
            // treated as a duplicate).
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();

            // Post-registration linkage / receipt tracking
            $table->decimal('fee_amount', 10, 2)->default(0);
            $table->string('course_group', 50)->nullable();
            $table->string('receipt_no', 40)->nullable()->index();
            $table->string('pdf_path')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['mobile', 'session_year', 'reg_type']);
            $table->index(['payment_status', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_registrations');
    }
};
