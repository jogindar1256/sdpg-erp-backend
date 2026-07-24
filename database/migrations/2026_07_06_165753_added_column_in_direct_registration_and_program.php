<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direct_registrations', function (Blueprint $t) {
            if (!Schema::hasColumn('direct_registrations', 'student_id')) {
                $t->unsignedBigInteger('student_id')->nullable()->index();
            }
            if (!Schema::hasColumn('direct_registrations', 'fee_amount')) {
                $t->decimal('fee_amount', 10, 2)->default(0);
            }
            if (!Schema::hasColumn('direct_registrations', 'course_group')) {
                $t->string('course_group', 50)->nullable();
            }
            if (!Schema::hasColumn('direct_registrations', 'receipt_no')) {
                $t->string('receipt_no', 40)->nullable()->index();
            }
            if (!Schema::hasColumn('direct_registrations', 'pdf_path')) {
                $t->string('pdf_path')->nullable();
            }
        });

        if (!Schema::hasColumn('programs', 'full_name')) {
            Schema::table('programs', function (Blueprint $t) {
                $t->string('full_name')->nullable();
            });

            // Backfill so existing `p.full_name as program_name` selects aren't null.
            DB::table('programs')->whereNull('full_name')->update([
                'full_name' => DB::raw('name'),
            ]);
        }
    }

    /**
     * WARNING: on the existing production DB these columns already hold data
     * (they predate this migration). Rolling back will drop that data. Only
     * run down() on a fresh/dev database.
     */
    public function down(): void
    {
        Schema::table('direct_registrations', function (Blueprint $t) {
            foreach (['student_id', 'fee_amount', 'course_group', 'receipt_no', 'pdf_path'] as $col) {
                if (Schema::hasColumn('direct_registrations', $col)) {
                    $t->dropColumn($col);
                }
            }
        });

        if (Schema::hasColumn('programs', 'full_name')) {
            Schema::table('programs', function (Blueprint $t) {
                $t->dropColumn('full_name');
            });
        }
    }
};
