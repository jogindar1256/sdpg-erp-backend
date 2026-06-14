<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {

// application_schedules
Schema::create('application_schedules', function (Blueprint $table) {
    $table->id();
    $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
    $table->string('session_year');
    $table->string('semester_name');
    $table->string('semester_no');
    $table->enum('exam_mode', ['Regular','Back Paper','Upgrade']);
    $table->date('start_admission');
    $table->date('close_admission');
    $table->boolean('late_fee_applicable')->default(false);
    $table->decimal('late_fee', 10, 2)->nullable();
    $table->timestamps();
});

// admission_conditions
Schema::create('admission_conditions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
    $table->string('session_year');
    $table->string('semester_no');
    $table->string('qualifying_class');
    $table->enum('condition_type', ['Open Admission','Through Counselling','Cut Off Merit List','Out Of Merit List']);
    $table->integer('allotted_seat')->default(0);
    $table->decimal('required_percent_gen', 5, 2)->default(0);
    $table->decimal('required_percent_obc', 5, 2)->default(0);
    $table->decimal('required_percent_sc', 5, 2)->default(0);
    $table->decimal('required_percent_st', 5, 2)->default(0);
    $table->decimal('required_percent_ews', 5, 2)->default(0);
    $table->boolean('is_blocked')->default(false);
    $table->timestamps();
    $table->unique(['program_id','session_year','semester_no']);
});

// enclosure_masters
Schema::create('enclosure_masters', function (Blueprint $table) {
    $table->id();
    $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
    $table->string('semester_no');
    $table->string('admission_mode');
    $table->string('document_name');
    $table->boolean('is_required')->default(true);
    $table->timestamps();
});

// back_paper_schedules
Schema::create('back_paper_schedules', function (Blueprint $table) {
    $table->id();
    $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
    $table->string('semester');
    $table->string('session_year');
    $table->dateTime('start_from');
    $table->dateTime('end_on');
    $table->boolean('late_fee_applicable')->default(false);
    $table->decimal('late_fee', 10, 2)->nullable();
    $table->timestamps();
});

// registration_fees
Schema::create('registration_fees', function (Blueprint $table) {
    $table->id();
    $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
    $table->string('session_year');
    $table->string('semester_no');
    $table->string('registration_mode');
    $table->json('amounts'); // {gen_male, obc_male, sc_male, st_male, gen_female, ...}
    $table->timestamps();
    $table->unique(['program_id','session_year','semester_no','registration_mode']);
});

// semester_masters
Schema::create('semester_masters', function (Blueprint $table) {
    $table->id();
    $table->string('name');        // e.g. ODD Semester
    $table->string('semester_nos'); // e.g. 1,3,5,7
    $table->enum('status', ['Active','Inactive'])->default('Active');
    $table->timestamps();
});

// allotted_subjects
Schema::create('allotted_subjects', function (Blueprint $table) {
    $table->id();
    $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
    $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
    $table->enum('permission_type', ['Finance','Self Finance']);
    $table->boolean('for_regular')->default(true);
    $table->boolean('for_private')->default(false);
    $table->timestamps();
    $table->unique(['program_id','subject_id']);
});

// subject_papers
Schema::create('subject_papers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
    $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
    $table->string('session_year');
    $table->string('semester_no');
    $table->string('paper_type');
    $table->string('paper_name');
    $table->integer('group_no');
    $table->integer('max_marks');
    $table->integer('min_marks');
    $table->timestamps();
});

// subject_seats
Schema::create('subject_seats', function (Blueprint $table) {
    $table->id();
    $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
    $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
    $table->integer('allotted_seat');
    $table->string('order_ref')->nullable();
    $table->integer('varg_bridhi')->nullable();
    $table->integer('total_seat');
    $table->enum('permission_type', ['Finance','Self Finance','Temporary']);
    $table->string('period_session')->nullable();
    $table->enum('status', ['Active','Inactive'])->default('Active');
    $table->timestamps();
    $table->unique(['program_id','subject_id']);
});

// subject_selections
Schema::create('subject_selections', function (Blueprint $table) {
    $table->id();
    $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
    $table->string('semester_no');
    $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
    $table->integer('group_no');
    $table->boolean('is_compulsory')->default(false);
    $table->integer('max_marks');
    $table->integer('min_marks');
    $table->integer('sort_order')->default(0);
    $table->timestamps();
});

// vocational_papers
Schema::create('vocational_papers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
    $table->string('session_year');
    $table->string('semester_no');
    $table->integer('group_no');
    $table->string('group_name');
    $table->string('paper_code');
    $table->string('paper_name');
    $table->integer('max_marks');
    $table->integer('min_marks');
    $table->timestamps();
});

// holiday_calendars
Schema::create('holiday_calendars', function (Blueprint $table) {
    $table->id();
    $table->string('session_year');
    $table->string('name');
    $table->enum('type', ['Gazetted','Local','College Level','University Level']);
    $table->date('leave_from');
    $table->integer('leave_days');
    $table->date('leave_till');
    $table->enum('leave_for', ['All','Teaching Staff','Office Staff Only','Only Student']);
    $table->enum('sms_alert', ['Before','Same Day','Immediate']);
    $table->integer('sms_days_before')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});

// print_permissions
Schema::create('print_permissions', function (Blueprint $table) {
    $table->id();
    $table->string('document_type')->unique(); // e.g. Fee Receipt, Admit Card, Mark Sheet
    $table->boolean('is_allowed')->default(false);
    $table->timestamps();
});

// state_security_deposits
Schema::create('state_security_deposits', function (Blueprint $table) {
    $table->id();
    $table->string('state_name');
    $table->boolean('deposit_required')->default(false);
    $table->decimal('amount', 10, 2)->nullable();
    $table->timestamps();
});

// counselling_reports
Schema::create('counselling_reports', function (Blueprint $table) {
    $table->id();
    $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
    $table->string('session_year');
    $table->string('entrance_roll_no');
    $table->string('name');
    $table->string('father_name');
    $table->string('mother_name');
    $table->string('spouse_name')->nullable();
    $table->enum('gender', ['Male','Female','Trans']);
    $table->enum('social_category', ['General','OBC','SC','ST','EWS']);
    $table->enum('admission_category', ['Regular','Private']);
    $table->integer('state_rank');
    $table->integer('category_rank')->nullable();
    $table->decimal('cut_off_mark', 6, 2)->nullable();
    $table->string('allotment_no')->nullable();
    $table->date('entry_date');
    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_settings_tables');
    }
};
