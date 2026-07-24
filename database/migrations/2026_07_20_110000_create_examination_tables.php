<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ExaminationController.php was written against 11 tables that were never
 * migrated (exam_forms, exam_schedules, exam_rooms, exam_innings,
 * exam_form_papers, exam_seating, exam_conduct_p1, exam_ufm, exam_absent,
 * exam_packets, exam_centres) — every method in that controller threw
 * immediately. The controller's own column usage already encodes a
 * coherent design (exam form -> accept -> schedule -> seating -> conduct
 * (P1/P3-UFM/P4-Absent/P9-packet) -> result -> marksheet), so this migration
 * creates exactly the tables/columns that code expects, rather than
 * inventing a different shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_centres', function (Blueprint $t) {
            $t->id();
            $t->string('center_code', 20)->unique();
            $t->string('center_name');
            $t->string('college_code', 20)->nullable();
            $t->string('college_name')->nullable();
            $t->timestamps();
        });

        Schema::create('exam_innings', function (Blueprint $t) {
            $t->id();
            $t->string('center_code', 20)->nullable();
            $t->string('inning_name', 50);
            $t->string('time_start', 20);
            $t->string('time_end', 20);
            $t->timestamps();
        });

        Schema::create('exam_rooms', function (Blueprint $t) {
            $t->id();
            $t->string('room_no', 20);
            $t->string('building_name', 100);
            $t->integer('rows');
            $t->integer('columns');
            $t->integer('capacity');
            $t->integer('extra_seat')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('exam_forms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $t->string('session_year', 12);
            $t->string('semester_no', 10);
            $t->enum('exam_type', ['Regular', 'Back Paper']);
            $t->string('center_code', 20)->nullable();
            $t->enum('status', ['Pending', 'Accepted', 'Rejected'])->default('Pending');
            $t->string('form_id', 50)->nullable();
            $t->string('result', 30)->nullable(); // Pass|Promote|Not Awarded|Fail|Absent
            $t->boolean('marksheet_available')->default(false);
            $t->timestamps();

            $t->index(['session_year', 'semester_no', 'exam_type']);
        });

        Schema::create('exam_form_papers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exam_form_id')->constrained('exam_forms')->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->string('paper_code', 20);
            $t->enum('exam_type', ['Regular', 'Back Paper'])->default('Regular');
            $t->timestamps();

            $t->index(['exam_form_id', 'paper_code']);
        });

        Schema::create('exam_schedules', function (Blueprint $t) {
            $t->id();
            $t->string('session_year', 12);
            $t->foreignId('program_id')->constrained()->cascadeOnDelete();
            $t->string('semester_no', 10);
            $t->enum('exam_mode', ['Regular', 'Back Paper']);
            $t->date('exam_date');
            $t->string('inning', 50);
            $t->string('exam_start', 20);
            $t->string('exam_end', 20);
            $t->string('paper_code', 20);
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->timestamps();

            $t->index(['session_year', 'program_id', 'semester_no']);
            $t->index(['exam_date', 'inning']);
        });

        Schema::create('exam_seating', function (Blueprint $t) {
            $t->id();
            $t->string('session_year', 12);
            $t->date('exam_date');
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->string('paper_code', 20);
            $t->foreignId('room_id')->constrained('exam_rooms')->cascadeOnDelete();
            $t->integer('row_no');
            $t->integer('col_no');
            $t->timestamps();

            $t->unique(['session_year', 'exam_date', 'student_id', 'paper_code'], 'exam_seating_unique');
        });

        Schema::create('exam_conduct_p1', function (Blueprint $t) {
            $t->id();
            $t->string('session_year', 12);
            $t->date('exam_date');
            $t->foreignId('inning_id')->constrained('exam_innings')->cascadeOnDelete();
            $t->string('center_code', 20);
            $t->timestamps();
        });

        Schema::create('exam_ufm', function (Blueprint $t) {
            $t->id();
            $t->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $t->string('roll_no', 30);
            $t->string('paper_code', 20);
            $t->string('session_year', 12)->nullable();
            $t->date('exam_date');
            $t->foreignId('inning_id')->constrained('exam_innings')->cascadeOnDelete();
            $t->foreignId('room_id')->constrained('exam_rooms')->cascadeOnDelete();
            $t->string('issued_copy_no', 50);
            $t->string('invigilator1');
            $t->string('ufm_by');
            $t->string('authority_name');
            $t->string('ufm_copy_no2', 50);
            $t->timestamps();
        });

        Schema::create('exam_absent', function (Blueprint $t) {
            $t->id();
            $t->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $t->string('roll_no', 30);
            $t->string('paper_code', 20);
            $t->string('session_year', 12)->nullable();
            $t->date('exam_date');
            $t->foreignId('inning_id')->constrained('exam_innings')->cascadeOnDelete();
            $t->foreignId('room_id')->constrained('exam_rooms')->cascadeOnDelete();
            $t->string('issued_copy_no', 50);
            $t->string('invigilator1');
            $t->timestamps();
        });

        Schema::create('exam_packets', function (Blueprint $t) {
            $t->id();
            $t->string('session_year', 12);
            $t->date('exam_date');
            $t->foreignId('inning_id')->constrained('exam_innings')->cascadeOnDelete();
            $t->string('center_code', 20);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_packets');
        Schema::dropIfExists('exam_absent');
        Schema::dropIfExists('exam_ufm');
        Schema::dropIfExists('exam_conduct_p1');
        Schema::dropIfExists('exam_seating');
        Schema::dropIfExists('exam_schedules');
        Schema::dropIfExists('exam_form_papers');
        Schema::dropIfExists('exam_forms');
        Schema::dropIfExists('exam_rooms');
        Schema::dropIfExists('exam_innings');
        Schema::dropIfExists('exam_centres');
    }
};
