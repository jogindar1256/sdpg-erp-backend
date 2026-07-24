<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── Add part columns to student_applications ─────────────────────────
        Schema::table('student_applications', function (Blueprint $t) {
            // Part 1 — Course Selection (program, session, type already in main columns
            //          but part_1 stores any extra form data / confirmation)
            if (!Schema::hasColumn('student_applications', 'part_1')) {
                $t->jsonb('part_1')->nullable()->after('form_progress');
            }
            // Part 2 — Personal Details
            if (!Schema::hasColumn('student_applications', 'part_2')) {
                $t->jsonb('part_2')->nullable()->after('part_1');
            }
            // Part 3 — Address & Communication
            if (!Schema::hasColumn('student_applications', 'part_3')) {
                $t->jsonb('part_3')->nullable()->after('part_2');
            }
            // Part 4 — Educational Details (array of qualification records)
            if (!Schema::hasColumn('student_applications', 'part_4')) {
                $t->jsonb('part_4')->nullable()->after('part_3');
            }
            // Part 5 — TC & Migration Details
            if (!Schema::hasColumn('student_applications', 'part_5')) {
                $t->jsonb('part_5')->nullable()->after('part_4');
            }
            // Part 6 — Bank Details
            if (!Schema::hasColumn('student_applications', 'part_6')) {
                $t->jsonb('part_6')->nullable()->after('part_5');
            }
            // Part 7 — Subject & Paper Selection
            if (!Schema::hasColumn('student_applications', 'part_7')) {
                $t->jsonb('part_7')->nullable()->after('part_6');
            }
            // Part 8 — Upload Photo, Signature & Documents (stores file paths/metadata)
            if (!Schema::hasColumn('student_applications', 'part_8')) {
                $t->jsonb('part_8')->nullable()->after('part_7');
            }
        });

        // ── student_application_documents ────────────────────────────────────
        // Stores uploaded document files linked to a student_application.
        // Used by uploadStudentDocument() via POST /student/applications/{id}/documents
        if (!Schema::hasTable('student_application_documents')) {
            Schema::create('student_application_documents', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('application_id');
                $t->string('document_type', 60);   // photo | signature | hs_marksheet | tc | migration | aadhar | abc …
                $t->string('path');                 // Storage path
                $t->string('filename')->nullable(); // Original file name
                $t->string('status', 20)->default('uploaded'); // uploaded | verified | rejected
                $t->timestamps();

                $t->foreign('application_id')
                    ->references('id')
                    ->on('student_applications')
                    ->onDelete('cascade');

                // One document per type per application (upsert key)
                $t->unique(['application_id', 'document_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_application_documents');

        Schema::table('student_applications', function (Blueprint $t) {
            $cols = ['part_1', 'part_2', 'part_3', 'part_4', 'part_5', 'part_6', 'part_7', 'part_8'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('student_applications', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};

