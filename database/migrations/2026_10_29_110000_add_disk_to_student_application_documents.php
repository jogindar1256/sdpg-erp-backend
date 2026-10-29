<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * student_application_documents.disk — which storage disk holds the file
 * ('supabase' | 'public').
 *
 * ApplicationController::parseApp() used to ask Supabase Storage
 * (Storage::exists = one HTTP HEAD request) for EVERY document on EVERY
 * application load, only to decide which disk to build the URL from. New
 * uploads now record their disk; rows left NULL here (older files) are
 * resolved once on first view and the answer is written back.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('student_application_documents', 'disk')) {
            Schema::table('student_application_documents', fn (Blueprint $t) => $t->string('disk', 20)->nullable()->after('path'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('student_application_documents', 'disk')) {
            Schema::table('student_application_documents', fn (Blueprint $t) => $t->dropColumn('disk'));
        }
    }
};
