<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links enclosure_masters rows to enclosure_types instead of relying purely
 * on the free-text `document_name` column for identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enclosure_masters', function (Blueprint $table) {
            $table->foreignId('enclosure_type_id')->nullable()->after('document_name')
                ->constrained('enclosure_types')->nullOnDelete();
        });

        $types = DB::table('enclosure_types')->get(['id', 'name']);
        foreach ($types as $type) {
            DB::table('enclosure_masters')
                ->whereRaw('LOWER(TRIM(document_name)) = ?', [strtolower(trim($type->name))])
                ->update(['enclosure_type_id' => $type->id]);
        }

        Schema::table('enclosure_masters', function (Blueprint $table) {
            $table->dropUnique('enclosure_masters_unique');
        });

        Schema::table('enclosure_masters', function (Blueprint $table) {
            $table->unique(
                ['program_id', 'semester_no', 'admission_mode', 'enclosure_type_id'],
                'enclosure_masters_type_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('enclosure_masters', function (Blueprint $table) {
            $table->dropUnique('enclosure_masters_type_unique');
        });
        Schema::table('enclosure_masters', function (Blueprint $table) {
            $table->unique(
                ['program_id', 'semester_no', 'admission_mode', 'document_name'],
                'enclosure_masters_unique'
            );
        });
        Schema::table('enclosure_masters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enclosure_type_id');
        });
    }
};
