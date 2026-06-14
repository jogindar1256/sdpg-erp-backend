<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Identity
            $table->string('enrollment_no')->unique()->nullable(); // assigned after admission
            $table->string('university_roll_no')->nullable();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->enum('gender', ['male', 'female', 'other']);
            $table->date('date_of_birth');
            $table->string('category')->default('general');   // general, obc, sc, st
            $table->string('religion')->nullable();
            $table->string('nationality')->default('Indian');
            $table->string('aadhar_no', 20)->nullable();
            $table->string('abc_id')->nullable();             // Academic Bank of Credits

            // Contact
            $table->string('mobile', 15);
            $table->string('alternate_mobile', 15)->nullable();
            $table->string('email')->nullable();
            $table->string('whatsapp_no', 15)->nullable();

            // Address
            $table->text('permanent_address');
            $table->string('permanent_city');
            $table->string('permanent_district');
            $table->string('permanent_state');
            $table->string('permanent_pin', 10);
            $table->boolean('same_as_permanent')->default(true);
            $table->text('correspondence_address')->nullable();
            $table->string('correspondence_city')->nullable();
            $table->string('correspondence_district')->nullable();
            $table->string('correspondence_state')->nullable();
            $table->string('correspondence_pin', 10)->nullable();

            // Academic (previous)
            $table->string('last_exam_passed')->nullable();    // 12th, Graduation
            $table->string('last_exam_board')->nullable();
            $table->string('last_exam_roll_no')->nullable();
            $table->integer('last_exam_year')->nullable();
            $table->decimal('last_exam_percentage', 5, 2)->nullable();
            $table->string('last_exam_division')->nullable();  // 1st, 2nd, 3rd

            // TC & Migration
            $table->string('tc_no')->nullable();
            $table->date('tc_date')->nullable();
            $table->string('tc_issued_by')->nullable();
            $table->string('migration_no')->nullable();
            $table->date('migration_date')->nullable();

            // Bank details
            $table->string('bank_name')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('bank_ifsc', 15)->nullable();
            $table->string('bank_account_no')->nullable();

            // Photo & signature
            $table->string('photo_path')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('biometric_id')->nullable();

            // Status
            $table->enum('status', ['active', 'inactive', 'blocked', 'cancelled', 'passed_out'])->default('active');
            $table->boolean('is_blocked')->default(false);
            $table->string('block_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index('enrollment_no');
            $table->index('mobile');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
