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
        if (in_array($admissionType, ['regular', 'upgrade', 'lateral'], true)) {
            return $isSelfFinance ? 'self_finance' : 'regular';
        }
        return 'other';
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
