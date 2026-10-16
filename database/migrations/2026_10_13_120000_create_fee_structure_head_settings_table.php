<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-particular fee settings for one fee-structure configuration
 * (course + session + semester + exam mode + fee head):
 *   sdpgc_exempt   — not charged to an SDPGC pass-out (P.G. / B.Ed. only)
 *   ddu_exempt     — not charged to a pass-out of another DDU-affiliated college
 *   pay_in_college — collected at the college counter, left out of the online amount
 *
 * These replace the configuration-level "SDPGC Student / DDU Affiliated"
 * toggles. Existing fee_structures rows saved under those toggles are
 * normalised to false/false here so the grid keeps showing them; a
 * configuration saved under BOTH variants is left untouched and reported,
 * because merging two different fee sets is a decision, not a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fee_structure_head_settings')) {
            Schema::create('fee_structure_head_settings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
                $t->string('academic_year');
                $t->integer('semester_no');
                $t->string('admission_type');
                $t->foreignId('fee_head_id')->constrained('fee_heads')->cascadeOnDelete();
                $t->boolean('sdpgc_exempt')->default(false);
                $t->boolean('ddu_exempt')->default(false);
                $t->boolean('pay_in_college')->default(false);
                $t->timestamps();
                $t->unique(
                    ['program_id', 'academic_year', 'semester_no', 'admission_type', 'fee_head_id'],
                    'fee_structure_head_settings_unique'
                );
            });
        }

        // Collapse the old configuration-level flags onto false/false.
        $groups = DB::table('fee_structures')
            ->select('program_id', 'academic_year', 'semester_no', 'admission_type', 'category', DB::raw('COUNT(*) as n'))
            ->groupBy('program_id', 'academic_year', 'semester_no', 'admission_type', 'category')
            ->get();
        foreach ($groups as $g) {
            $where = [
                'program_id' => $g->program_id,
                'academic_year' => $g->academic_year,
                'semester_no' => $g->semester_no,
                'admission_type' => $g->admission_type,
                'category' => $g->category,
            ];
            if ((int) $g->n === 1) {
                DB::table('fee_structures')->where($where)->update(['sdpgc_student' => false, 'ddu_affiliated' => false]);
            } else {
                echo "  ! fee_structures: {$g->n} SDPGC/DDU variants for program {$g->program_id}, {$g->academic_year}, sem {$g->semester_no}, {$g->admission_type}, {$g->category} — left as is, resolve manually.\n";
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_structure_head_settings');
    }
};
