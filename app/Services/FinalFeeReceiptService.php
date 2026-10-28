<?php

namespace App\Services;

use App\Http\Controllers\Api\ApplicationController;
use App\Models\FeeReceipt;
use App\Support\Part6Rules;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * "Final Fee Receipt (Provisional)" — student copy + office copy on one A4,
 * laid out after the college's own sample (Final Fee Receipt.pdf).
 *
 * Reads only: nothing is written into students / admissions. For a
 * first-time applicant the Student ID printed here is the one reserved on
 * student_applications (ApplicationController::ensureStudentUid()).
 */
class FinalFeeReceiptService
{
    /** Storage path + download name for a receipt. */
    public static function fileName(FeeReceipt $r): string
    {
        return 'final-fee-receipt-' . $r->receipt_no . '.pdf';
    }

    /** Render, store and stamp the receipt. Returns the stored path. */
    public function generate(FeeReceipt $receipt, ?int $byUserId = null): string
    {
        $sa = $this->applicationFor($receipt);
        if ($sa) {
            app(ApplicationController::class)->ensureStudentUid($sa); // number only
        }

        $pdf = Pdf::loadView('pdf.final-fee-receipt', $this->data($receipt, $byUserId))->setPaper('a4');
        $path = "final-receipts/{$receipt->organization_id}/{$receipt->academic_year}/" . self::fileName($receipt);
        Storage::put($path, $pdf->output());

        $receipt->update([
            'final_receipt_path' => $path,
            'final_receipt_generated_at' => now(),
            'final_receipt_generated_by' => $byUserId,
        ]);

        return $path;
    }

    private function applicationFor(FeeReceipt $receipt): ?object
    {
        $appId = $receipt->admission_id
            ? DB::table('admissions')->where('id', $receipt->admission_id)->value('application_id')
            : null;

        return $appId ? DB::table('student_applications')->where('id', $appId)->first() : null;
    }

    public function data(FeeReceipt $receipt, ?int $byUserId = null): array
    {
        $sa = $this->applicationFor($receipt);

        $base = [
            'sa' => null, 'student' => null, 'program' => null, 'org' => null, 'admission' => null,
            'registration' => null, 'parts' => [], 'subjectsById' => collect(),
        ];
        if ($sa) {
            $base = array_merge($base, app(ApplicationController::class)->buildApplicationFormPdfData($sa));
            $sa = DB::table('student_applications')->where('id', $sa->id)->first(); // fresh student_uid
        } else {
            $admission = $receipt->admission_id ? DB::table('admissions')->where('id', $receipt->admission_id)->first() : null;
            $base['admission'] = $admission;
            $base['student'] = $receipt->student_id ? \App\Models\Student::flat(DB::table('students')->where('id', $receipt->student_id)->first()) : null;
            $base['program'] = $admission ? DB::table('programs')->where('id', $admission->program_id)->first() : null;
            $base['org'] = DB::table('organizations')->where('id', $receipt->organization_id)->first();
        }

        $parts = $base['parts'];
        $p1 = $parts[1] ?? [];
        $p4 = $parts[4] ?? [];
        $p6 = $parts[6] ?? [];
        $student = $base['student'];
        $reg = $base['registration'];
        $program = $base['program'];
        $admission = $base['admission'];

        // Selected subjects (UG: Subject-1/2/3, the third being the minor).
        $slotNames = collect(Part6Rules::subjectSlots($p6))
            ->map(fn($id) => $base['subjectsById']->get($id)->name ?? null)->filter()->values();
        $formType = Part6Rules::formType($program);
        $subjectsText = $slotNames->implode(', ');
        $minor = null;
        if (!$formType && $slotNames->count() >= 3) {
            $minor = $slotNames->last();
        }
        if ($formType) {
            $subjectsText = $this->paperSubjectNames($p6) ?: $subjectsText;
        }

        $tc = $p4['tc_condition'] ?? null;
        $tcStatus = $tc === 'have_original_tc' ? 'Attached' : 'Not Attached';

        // Fee grid: every active fee head, numbered, amount from this receipt.
        $paid = [];
        foreach ((array) $receipt->fee_breakdown as $b) {
            $paid[(int) ($b['fee_head_id'] ?? 0)] = ($paid[(int) ($b['fee_head_id'] ?? 0)] ?? 0) + (float) ($b['amount'] ?? 0);
        }
        $heads = DB::table('fee_heads')->where('is_active', true)->orderBy('id')->get(['id', 'name']);
        $known = $heads->pluck('id')->all();
        $rows = [];
        foreach ($heads as $h) {
            $rows[] = ['name' => $h->name, 'amount' => $paid[$h->id] ?? 0];
        }
        foreach ($paid as $id => $amt) { // a head since removed / renamed still has to show
            if (!in_array($id, $known, true)) {
                $name = collect((array) $receipt->fee_breakdown)->firstWhere('fee_head_id', $id)['fee_head_name'] ?? 'Other';
                $rows[] = ['name' => $name, 'amount' => $amt];
            }
        }

        $total = (float) $receipt->net_amount;
        $printer = $byUserId ? DB::table('users')->where('id', $byUserId)->first() : null;
        $semester = $receipt->semester_no ?: ($sa->semester_no ?? null) ?: 1;

        return [
            'receipt' => $receipt,
            'org' => $base['org'],
            'sa' => $sa,
            'registration' => $reg,
            'student' => $student,
            'admission' => $admission,
            'name' => $p1['name_english'] ?? $reg->name ?? ($student ? trim(implode(' ', array_filter([$student->first_name ?? null, $student->middle_name ?? null, $student->last_name ?? null]))) : null),
            'father' => $p1['father_name_english'] ?? $reg->father_name ?? null,
            'mother' => $p1['mother_name_english'] ?? $reg->mother_name ?? null,
            'dob' => $p1['date_of_birth'] ?? $reg->dob ?? $student->date_of_birth ?? null,
            'gender' => $p1['gender'] ?? $reg->gender ?? $student->gender ?? null,
            'category' => $p1['social_category'] ?? $reg->category ?? $student->category ?? null,
            'state' => $p1['domestic_state'] ?? $reg->domestic_state ?? null,
            'mobile' => $reg->mobile ?? $student->mobile ?? null,
            'abc' => $p1['abc_id'] ?? $reg->abc_id ?? $student->abc_id ?? null,
            'ddurn' => $p1['ddurn_id'] ?? $reg->ddurn_no ?? $student->ddurn ?? null,
            'studentUid' => $sa->student_uid ?? $student->student_uid ?? null,
            'courseName' => $program->short_name ?? $program->full_name ?? null,
            'classAc' => $admission->account_no ?? null,
            'semester' => $semester,
            'subjects' => $subjectsText,
            'minor' => $minor,
            'tcStatus' => $tcStatus,
            'admissionMode' => ($sa->application_type ?? 'regular') === 'regular' ? 'Regular' : ucwords(str_replace('_', ' ', (string) ($sa->application_type ?? 'regular'))),
            'feeRows' => $rows,
            'total' => $total,
            'inWords' => self::rupeesInWords($total),
            'printedBy' => $printer->employee_id ?? $printer->id ?? null,
        ];
    }

    private function paperSubjectNames(array $p6): string
    {
        $ids = collect([$p6['compulsory_paper_ids'] ?? [], $p6['optional_paper_ids'] ?? [], $p6['teaching_paper_ids'] ?? []])
            ->flatten()->filter()->map(fn($v) => (int) $v)->unique()->all();
        if (!$ids) {
            return '';
        }
        return DB::table('subject_papers as sp')->leftJoin('subjects as s', 's.id', 'sp.subject_id')
            ->whereIn('sp.id', $ids)->pluck('s.name')->filter()->unique()->implode(', ');
    }

    /** "Two Thousand Five Hundred Ninety-Five Only" — Indian grouping. */
    public static function rupeesInWords(float $amount): string
    {
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $two = function (int $n) use ($ones, $tens) {
            return $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : ''));
        };
        $three = function (int $n) use ($two, $ones) {
            $s = '';
            if ($n >= 100) {
                $s = $ones[intdiv($n, 100)] . ' Hundred';
                $n %= 100;
                if ($n) $s .= ' ';
            }
            return $s . ($n ? $two($n) : '');
        };
        $words = function (int $n) use ($three) {
            if ($n === 0) return 'Zero';
            $parts = [];
            foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand'] as $div => $label) {
                if ($n >= $div) {
                    $parts[] = $three(intdiv($n, $div)) . ' ' . $label;
                    $n %= $div;
                }
            }
            if ($n) $parts[] = $three($n);
            return implode(' ', $parts);
        };
        $out = $words($rupees);
        if ($paise) {
            $out .= ' and ' . $words($paise) . ' Paise';
        }
        return $out . ' Only';
    }
}
