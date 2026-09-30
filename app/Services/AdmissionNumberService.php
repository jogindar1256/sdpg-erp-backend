<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Generates the five Regular-Admission identifiers exactly per the college spec.
 *
 *   1. Student ID    (13)  YY + centre(3) + course(2) + category(2) + serial(4)   e.g. 1914501010001
 *   2. Fee Receipt   (9)   YY + mode(3) + serial(4)                                e.g. 251010001
 *   3. File No             startYY+endYY + "/" + serial(5)                         e.g. 2425/00001
 *   4. Class A/C No        per (program, session), odd semesters only, from 01     e.g. 01, 02 …
 *   5. Record No           global, never resets across sessions                    e.g. 1521
 *
 * YY = last 2 digits of the session's SECOND year (2024-2025 -> 25).
 * Serials are computed as MAX(existing within scope)+1, so they survive restarts
 * and are correct even if rows are created out of order.
 *
 * NOTE: nothing calls this yet — there is no Regular-Admission action in the app
 * (admissions has no store endpoint). Wire these into that action when it's built.
 */
class AdmissionNumberService
{
    /** Fallback course codes if programs.course_code is not filled. */
    private const COURSE_FALLBACK = ['BA' => '01', 'BSC' => '02', 'BED' => '03', 'MA' => '04', 'MSC' => '05'];

    /** Category codes per spec (Gen 01, OBC 02, SC 03, ST 04). */
    private const CATEGORY = ['general' => '01', 'gen' => '01', 'obc' => '02', 'sc' => '03', 'st' => '04'];

    /** Fee-receipt mode codes. */
    private const MODE = ['regular' => '101', 'self_finance' => '201', 'back_paper' => '301', 'other' => '401'];

    /** Fee Ref. ID category codes (Gen/OBC/SC/ST/EWS — the labels the Fee Structure UI already shows, not the numeric Student-ID codes above). */
    private const FEE_REF_CATEGORY = ['general' => 'GEN', 'gen' => 'GEN', 'obc' => 'OBC', 'sc' => 'SC', 'st' => 'ST', 'ews' => 'EWS'];

    /** Fee Ref. ID gender codes. Unused for fee_structures now (see feeRefId() header) — kept for student_applications, which still encodes one real student's gender. */
    private const FEE_REF_GENDER = ['male' => 'M', 'female' => 'F', 'other' => 'T', 'transgender' => 'T'];

    // ── 1. Student ID ──────────────────────────────────────────────────────
    public function studentId(string $session, object $program, ?string $category): string
    {
        $prefix = $this->endYY($session)
            . $this->centreCode()
            . $this->courseCode($program)
            . $this->categoryCode($category);

        return $prefix . $this->nextSerial('students', 'student_code', $prefix, 4);
    }

    // ── 2. Fee Receipt No ──────────────────────────────────────────────────
    public function feeReceiptNo(string $session, string $mode): string
    {
        $code   = self::MODE[$mode] ?? self::MODE['regular'];
        $prefix = $this->endYY($session) . $code;

        return $prefix . $this->nextSerial('fee_receipts', 'receipt_no', $prefix, 4);
    }

    /** Derive the fee-receipt mode from admission type + program self-finance flag. */
    public function feeMode(string $admissionType, bool $isSelfFinance): string
    {
        if ($admissionType === 'back_paper') return 'back_paper';
        // 'regular' and 'upgrade' share the same code; self-finance flips 101 -> 201.
        if (in_array($admissionType, ['regular', 'upgrade',], true)) {
            return $isSelfFinance ? 'self_finance' : 'regular';
        }
        return 'other';
    }

    /**
     * Fee Ref. ID — course [+ category] [+ gender] + serial(3), e.g.
     * BA001, BAGEN001, or BAGENM001, depending which of $category/$gender
     * the caller has one real value for. Serial increments within
     * whichever prefix results (same MAX+1-within-prefix approach as the
     * other identifiers here).
     *
     * fee_structures calls pass $category (its real row identity — one row
     * per course+category, see the amount_json migration header) and leave
     * $gender null: a fee_structures row spans every gender within its
     * category at once, so encoding one gender into a row-level ref would
     * misrepresent it as covering only one. Gender lives inside
     * amount_json instead, resolved from the student's own record when
     * reading it.
     *
     * student_applications' own fee_ref_id is a separate case: that row
     * DOES belong to one specific student, so ApplicationController passes
     * both that student's real category AND gender and gets the full
     * BAGENM001-style code.
     */
    public function feeRefId(string $table, string $column, object $program, ?string $category = null, ?string $gender = null): string
    {
        $course = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($program->short_name ?? 'FS'))) ?: 'FS';
        $cat = $category ? (self::FEE_REF_CATEGORY[strtolower($category)] ?? strtoupper(substr($category, 0, 3))) : '';
        $gen = $gender ? (self::FEE_REF_GENDER[strtolower($gender)] ?? strtoupper(substr($gender, 0, 1))) : '';
        $prefix = $course . $cat . $gen;

        return $prefix . $this->nextSerial($table, $column, $prefix, 3);
    }

    /**
     * Ref no for a rejected_applications row — organization's own short
     * code (organizations.code, e.g. "SDPG") + a 6-digit serial that only
     * ever increments, e.g. "SDPG000001". One shared sequence across both
     * hold and reject decisions (not split by decision type or program).
     */
    public function rejectedApplicationRefNo(object $organization): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($organization->code ?? 'ORG'))) ?: 'ORG';

        return $prefix . $this->nextSerial('rejected_applications', 'ref_no', $prefix, 6);
    }

    // ── 3. File No ─────────────────────────────────────────────────────────
    public function fileNo(string $session): string
    {
        $prefix = $this->startYY($session) . $this->endYY($session) . '/';   // e.g. "2425/"

        $max = DB::table('admissions')
            ->where('file_no', 'like', $prefix . '%')
            ->max(DB::raw("CAST(split_part(file_no, '/', 2) AS INTEGER)"));

        return $prefix . str_pad(((int) $max) + 1, 5, '0', STR_PAD_LEFT);
    }

    // ── 4. Class A/C No (stored in admissions.account_no) ──────────────────
    // Continuous per class (program) per session; odd semesters only. Caller
    // must skip this for semester upgrades (even semesters).
    public function classAcNo(int $programId, string $session): string
    {
        $max = DB::table('admissions')
            ->where('program_id', $programId)
            ->where('academic_year', $session)
            ->whereRaw("account_no ~ '^[0-9]+$'")   // numeric only — avoids CAST errors on legacy values
            ->max(DB::raw('CAST(account_no AS INTEGER)'));

        return str_pad(((int) $max) + 1, 2, '0', STR_PAD_LEFT);
    }

    // ── 5. Record No (global, never resets) ────────────────────────────────
    public function recordNo(): int
    {
        return ((int) DB::table('admissions')->max('record_no')) + 1;
    }

    // ── helpers ────────────────────────────────────────────────────────────
    private function centreCode(): string
    {
        return (string) config('college.centre_code', '145');
    }

    private function courseCode(object $program): string
    {
        if (!empty($program->course_code)) {
            return str_pad((string) $program->course_code, 2, '0', STR_PAD_LEFT);
        }
        $key = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($program->short_name ?? '')));
        return self::COURSE_FALLBACK[$key] ?? '00';
    }

    private function categoryCode(?string $category): string
    {
        return self::CATEGORY[strtolower((string) $category)] ?? '01';   // default Gen
    }

    /** Last 2 digits of the session's ending year (2024-2025 -> "25"). */
    private function endYY(string $session): string
    {
        $parts = preg_split('/[-\/]/', $session);
        $year  = end($parts) ?: $session;
        return substr(preg_replace('/\D/', '', $year), -2);
    }

    /** Last 2 digits of the session's starting year (2024-2025 -> "24"). */
    private function startYY(string $session): string
    {
        $parts = preg_split('/[-\/]/', $session);
        return substr(preg_replace('/\D/', '', $parts[0] ?? $session), -2);
    }

    /**
     * Next zero-padded serial of length $len whose full value starts with
     * $prefix and has exactly (prefix + len) characters.
     */
    private function nextSerial(string $table, string $col, string $prefix, int $len): string
    {
        $total = strlen($prefix) + $len;

        $max = DB::table($table)
            ->where($col, 'like', $prefix . '%')
            ->whereRaw("length($col) = ?", [$total])
            ->max(DB::raw("CAST(substring($col FROM " . (strlen($prefix) + 1) . ") AS INTEGER)"));

        return str_pad(((int) $max) + 1, $len, '0', STR_PAD_LEFT);
    }
}
