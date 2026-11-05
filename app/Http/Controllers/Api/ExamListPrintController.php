<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

/**
 * College-level exam lists as server-side PDFs, all under ONE letter-head
 * (pdf.exam._collegehead — built from the Institute Profile):
 *
 *   GET examination/print-list/{doc}?session_year&program_id&semester_no[&exam_type][&status][&gender]
 *   doc: accept-list | form-entry | marksheet | result | nominal-roll (landscape, 5-column layout)
 *
 * The rows come from the very same index methods the screens use (every page of
 * them), so a printed list can never disagree with what is on screen.
 * University forms (P-4/P-6/P-7/P-9 …) use the Hindi letter-head instead — see ExamPrintController.
 */
class ExamListPrintController extends Controller
{
    /** doc => [source method, title, columns [label => row key]] */
    private const DOCS = [
        'accept-list' => ['acceptFormIndex', 'Exam Form Accept List', ['App. No.' => 'application_no', 'A/C No.' => 'account_no', 'Form Id' => 'form_id', 'Name' => 'name', 'Father' => 'father_name', 'Gender' => 'gender', 'Roll No.' => 'roll_no']],
        'form-entry'  => ['formIdIndex', 'Exam Form ID List', ['App. No.' => 'application_no', 'A/C No.' => 'account_no', 'Form Id' => 'form_id', 'Name' => 'name', 'Father' => 'father_name', 'Roll No.' => 'roll_no']],
        'marksheet'   => ['marksheetIndex', 'Mark Sheet Distribution List', ['Roll No.' => 'roll_no', 'Name' => 'name', 'Father' => 'father_name', 'Signature' => null]],
        'nominal-roll' => ['nominalRollIndex', 'Nominal Roll-Session', []],
        'result'      => ['resultIndex', 'Updated Result List', ['Roll No.' => 'roll_no', 'Name' => 'name', 'Father' => 'father_name', 'Result' => 'result']],
    ];

    public function show(Request $req, string $doc)
    {
        if (!isset(self::DOCS[$doc])) return response()->json(['message' => 'Unknown list.'], 404);
        if (!$req->program_id) return response()->json(['message' => 'Select a class first.'], 422);
        [$method, $title, $cols] = self::DOCS[$doc];
        @set_time_limit(120);

        try {
            $ctrl = app(ExaminationController::class);
            $rows = [];
            $page = 1;
            do {
                Paginator::currentPageResolver(fn () => $page);
                $body = $ctrl->{$method}($req)->getData(true);
                foreach ($body['data'] ?? [] as $r) $rows[] = $r;
                $last = (int) ($body['last_page'] ?? 1);
            } while (++$page <= $last);

            $nominal = $doc === 'nominal-roll';
            if ($nominal) $rows = array_values(array_filter($rows, fn ($r) => !empty($r['in_nominal']))); // only forms already on the roll

            $course = DB::table('programs')->where('id', $req->program_id)->value('short_name');
            $session = $req->session_year ?? \App\Support\AcademicSession::current();
            $pdf = Pdf::loadView($nominal ? 'pdf.exam.nominal-roll' : 'pdf.exam.list', [
                'title' => $nominal ? "Nominal Roll-Session $session" : $title . '-' . $session,
                'cols' => $cols, 'rows' => $rows, 'course' => $course, 'semester' => $req->semester_no,
            ])->setPaper($nominal ? 'letter' : 'a4', $nominal ? 'landscape' : 'portrait');

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . str_replace(' ', '-', $title) . '.pdf"',
                'Cache-Control' => 'no-store',
            ]);
        } catch (\Throwable $e) {
            \Log::error("Exam list print [$doc] failed: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Could not generate the PDF.'], 500);
        }
    }
}
