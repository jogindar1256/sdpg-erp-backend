<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Stores uploaded document files linked to a student_application.
        // Used by uploadStudentDocument() via POST /student/applications/{id}/documents
        // (part_1..part_8 on student_applications itself now live directly
        // in create_student_applications_table.php — this file used to also
        // re-add those columns here, folded in as part of the migration
        // cleanup since they were already added by an earlier migration.)
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

    public function down(): void
    {
        Schema::dropIfExists('student_application_documents');
    }
};

