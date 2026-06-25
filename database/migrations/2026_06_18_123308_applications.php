<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('program_id')->nullable();
            $t->string('form_type');          // Fresh | BackPaper | Upgrade
            $t->string('application_no')->unique()->nullable();
            $t->string('reg_no')->nullable();
            $t->string('session_year', 10);
            $t->string('semester_no', 5)->nullable();
            $t->string('back_semester', 5)->nullable();  // for back paper
            $t->string('exam_mode', 20)->nullable();     // Regular | Private
            $t->string('status', 20)->default('Pending'); // Pending|Approved|Rejected|Hold
            // Upgrade / Back paper extras
            $t->decimal('cgpa', 4, 2)->nullable();
            $t->string('result', 20)->nullable();        // Pass|Fail|Promoted
            $t->string('tc_status', 30)->nullable();
            $t->string('migration_status', 30)->nullable();
            $t->text('remarks')->nullable();
            $t->timestamps();

            $t->foreign('student_id')->references('id')->on('students');
            $t->foreign('program_id')->references('id')->on('programs');
            $t->index(['session_year', 'form_type', 'status']);
        });

        // ── application_education ───────────────────────────────────────────
        Schema::create('application_education', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('application_id');
            $t->string('course_name');          // High School | Intermediate | UG 1st Yr …
            $t->string('board_university')->nullable();
            $t->string('institute')->nullable();
            $t->string('year')->nullable();
            $t->string('roll_no')->nullable();
            $t->string('cert_marksheet_no')->nullable();
            $t->string('exam_system')->nullable(); // Annual | Semester
            $t->string('result')->nullable();
            $t->string('number_system')->nullable(); // Marks | Grade
            $t->string('obtained_marks')->nullable();
            $t->string('full_marks')->nullable();
            $t->string('subject_group')->nullable();
            $t->timestamps();
            $t->foreign('application_id')->references('id')->on('applications')->onDelete('cascade');
        });

        // ── application_tc ──────────────────────────────────────────────────
        Schema::create('application_tc', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('application_id');
            $t->string('tc_condition');         // Regular|HaveTC|DontHaveTC
            $t->string('organization_name')->nullable();
            $t->string('organization_address')->nullable();
            $t->string('contact_no')->nullable();
            $t->string('tc_serial_no')->nullable();
            $t->string('tc_ledger_no')->nullable();
            $t->date('issue_date')->nullable();
            $t->string('behavior')->nullable();
            $t->boolean('statement_signed')->default(false);
            $t->timestamps();
            $t->foreign('application_id')->references('id')->on('applications')->onDelete('cascade');
        });

        // ── application_migration ───────────────────────────────────────────
        Schema::create('application_migration', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('application_id');
            $t->string('migration_condition');  // NotApplicable|HaveMigration|DontHave
            $t->string('university_name')->nullable();
            $t->text('university_address')->nullable();
            $t->string('last_institute')->nullable();
            $t->text('institute_address')->nullable();
            $t->string('leave_year')->nullable();
            $t->string('reason')->nullable();
            $t->boolean('statement_signed')->default(false);
            $t->timestamps();
            $t->foreign('application_id')->references('id')->on('applications')->onDelete('cascade');
        });

        // ── application_bank ────────────────────────────────────────────────
        Schema::create('application_bank', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('application_id');
            $t->boolean('has_account')->default(false);
            $t->string('account_type')->nullable();   // Savings | Current
            $t->string('account_name')->nullable();
            $t->string('father_name')->nullable();
            $t->string('bank_name')->nullable();
            $t->string('branch')->nullable();
            $t->string('account_no')->nullable();
            $t->string('ifsc_code')->nullable();
            $t->text('branch_address')->nullable();
            $t->string('state')->nullable();
            $t->string('district')->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->timestamps();
            $t->foreign('application_id')->references('id')->on('applications')->onDelete('cascade');
        });

        // ── application_subjects ────────────────────────────────────────────
        Schema::create('application_subjects', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('application_id');
            $t->unsignedBigInteger('admission_id')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->unsignedBigInteger('paper_id')->nullable();
            $t->string('subject_type')->nullable(); // Major1|Major2|Minor1|AEC|SEC|Drop|ITRP|BackPaper
            $t->timestamps();
            $t->foreign('application_id')->references('id')->on('applications')->onDelete('cascade');
        });

        // ── application_documents ───────────────────────────────────────────
        Schema::create('application_documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('application_id');
            $t->string('enclosure_no')->nullable();
            $t->string('document_name');
            $t->string('file_path');
            $t->string('status', 20)->default('Uploaded'); // Uploaded | Pending
            $t->timestamps();
            $t->foreign('application_id')->references('id')->on('applications')->onDelete('cascade');
        });

        // ── application_holds ───────────────────────────────────────────────
        Schema::create('application_holds', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('application_id');
            $t->string('hold_type');
            $t->text('reason');
            $t->text('objections')->nullable();
            $t->string('submitted_by');
            $t->timestamp('submitted_date')->nullable();
            $t->timestamps();
            $t->foreign('application_id')->references('id')->on('applications')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_holds');
        Schema::dropIfExists('application_documents');
        Schema::dropIfExists('application_subjects');
        Schema::dropIfExists('application_bank');
        Schema::dropIfExists('application_migration');
        Schema::dropIfExists('application_tc');
        Schema::dropIfExists('application_education');
        Schema::dropIfExists('applications');
    }
};
