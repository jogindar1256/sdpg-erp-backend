<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1) Grid columns
        Schema::table('enclosure_masters', function (Blueprint $t) {
            if (!Schema::hasColumn('enclosure_masters', 'condition')) {
                $t->string('condition', 20)->nullable()->after('document_name');
            }
            if (!Schema::hasColumn('enclosure_masters', 'enclose')) {
                $t->boolean('enclose')->default(false)->after('condition');
            }
            if (!Schema::hasColumn('enclosure_masters', 'scan_copy')) {
                $t->boolean('scan_copy')->default(false)->after('enclose');
            }
            if (!Schema::hasColumn('enclosure_masters', 'photo_count')) {
                $t->string('photo_count', 5)->nullable()->after('scan_copy');
            }
        });

        // 2) De-duplicate: keep the lowest id per (program, semester, mode, document)
        $dupes = DB::table('enclosure_masters')
            ->select(
                DB::raw('MIN(id) as keep_id'),
                'program_id',
                'semester_no',
                'admission_mode',
                'document_name'
            )
            ->groupBy('program_id', 'semester_no', 'admission_mode', 'document_name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $d) {
            DB::table('enclosure_masters')
                ->where('program_id', $d->program_id)
                ->where('semester_no', $d->semester_no)
                ->where('admission_mode', $d->admission_mode)
                ->where('document_name', $d->document_name)
                ->where('id', '!=', $d->keep_id)
                ->delete();
        }

        // 3) Unique constraint (guarded so re-running won't error)
        $hasIndex = collect(DB::select(
            "SELECT 1 FROM pg_indexes WHERE indexname = 'enclosure_masters_unique'"
        ))->isNotEmpty();

        if (!$hasIndex) {
            Schema::table('enclosure_masters', function (Blueprint $t) {
                $t->unique(
                    ['program_id', 'semester_no', 'admission_mode', 'document_name'],
                    'enclosure_masters_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::table('enclosure_masters', function (Blueprint $t) {
            $t->dropUnique('enclosure_masters_unique');
        });

        Schema::table('enclosure_masters', function (Blueprint $t) {
            foreach (['photo_count', 'scan_copy', 'enclose', 'condition'] as $col) {
                if (Schema::hasColumn('enclosure_masters', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};