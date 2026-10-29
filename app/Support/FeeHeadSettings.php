<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Per-particular fee rules (fee_structure_head_settings) and the applicant
 * facts they depend on.
 *
 *  - SDPGC pass-out: exempt from particulars marked "SDPGC Student".
 *  - Pass-out of another DDU-affiliated college: exempt from particulars
 *    marked "DDU / Affiliated College". An SDPGC pass-out is judged by the
 *    SDPGC column only, even though SDPGC is itself DDU-affiliated.
 *  - "Pay in College" particulars are collected at the counter and kept
 *    out of the online (Razorpay) amount.
 *
 * Pass-out status is auto-detected and only applies to P.G. / B.Ed.
 */
class FeeHeadSettings
{
    /** Institute names that mean "this college". */
    private const SDPGC_RE = '/\bS\.?\s*D\.?\s*P\.?\s*G\b|SWAMI\s+DE?WA?NAND/i';
    /** Board / university names that mean DDU Gorakhpur University. */
    private const DDU_RE = '/DEEN\s*DAYAL|\bD\.?\s*D\.?\s*U\b|GORAKHPUR\s+UNIVERSITY|UNIVERSITY\s+OF\s+GORAKHPUR/i';

    /** fee_head_id => settings row, for one configuration. A semester-specific row wins over semester 0. */
    public static function forConfig(int $programId, string $academicYear, array $semesterNos, string $admissionType): array
    {
        $map = [];
        DB::table('fee_structure_head_settings')
            ->where('program_id', $programId)
            ->where('academic_year', $academicYear)
            ->whereIn('semester_no', $semesterNos)
            ->where('admission_type', $admissionType)
            ->orderBy('semester_no')
            ->get()
            ->each(function ($r) use (&$map) {
                $map[(int) $r->fee_head_id] = $r;
            });
        return $map;
    }

    /** ['sdpgc' => bool, 'ddu' => bool] for an application. Always false/false outside P.G. / B.Ed. */
    public static function passOutStatus(object $sa): array
    {
        $out = ['sdpgc' => false, 'ddu' => false];
        // ApplicationController::parseApp() has usually loaded both rows already.
        $loaded = fn (string $prop, $id) => is_object($sa->$prop ?? null) && (string) ($sa->$prop->id ?? '') === (string) $id ? $sa->$prop : null;
        $program = $loaded('program', $sa->program_id)
            ?? DB::table('programs')->where('id', $sa->program_id)->first();
        if (!Part6Rules::formType($program)) {
            return $out;
        }

        // 1) The UG roll number given at registration is one of our own students.
        $reg = !empty($sa->direct_registration_id)
            ? ($loaded('registration', $sa->direct_registration_id)
                ?? DB::table('direct_registrations')->where('id', $sa->direct_registration_id)->first())
            : null;
        $roll = trim((string) ($reg->ug_roll_no ?? ''));
        if ($roll !== '' && DB::table('students')
            ->whereNull('deleted_at')
            ->where(fn($q) => $q->where('university_roll_no', $roll)->orWhere('enrollment_no', $roll))
            ->exists()) {
            $out['sdpgc'] = true;
        }

        // 2) Part 3 education records: institute / board-university names.
        $part3 = $sa->part_3 ?? null;
        $part3 = is_string($part3) ? (json_decode($part3, true) ?: []) : (array) $part3;
        foreach ((array) ($part3['education_records'] ?? []) as $rec) {
            $rec = (array) $rec;
            if (preg_match(self::SDPGC_RE, (string) ($rec['institute_name'] ?? ''))) {
                $out['sdpgc'] = true;
            }
            if (preg_match(self::DDU_RE, (string) ($rec['board_university'] ?? ''))) {
                $out['ddu'] = true;
            }
        }
        return $out;
    }

    /** Is this particular waived for an applicant with this pass-out status? */
    public static function isExempt(?object $setting, array $who): bool
    {
        if (!$setting) return false;
        if ($who['sdpgc']) return (bool) $setting->sdpgc_exempt;
        return $who['ddu'] && (bool) $setting->ddu_exempt;
    }
}
