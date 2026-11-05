<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ExamPrintData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Server-side PDFs for the exam-conduct forms (one place, so every Single and
 * All print button gets the same layout and the same numbers):
 *
 *   GET examination/print/{doc}?session_year&exam_date&inning_id[&paper_code][&room_id][&mode]
 *
 *   doc: seating-plan | room-plan | p7 | p7-practical | desk-slip | p4 | p6 | p9 | p1
 *   paper_code  → a single-paper print ("Print P4"); omitted → every paper of the date + inning ("Print All P4")
 *   room_id     → seating-plan / p7 for one room only
 *   mode        → p1 only: all | received | blank
 *
 * Hindi labels are pre-rendered images (App\Support\HindiAsset) because dompdf
 * cannot shape Devanagari.
 */
class ExamPrintController extends Controller
{
    /** doc => [view, paper size, orientation, file prefix] */
    private const DOCS = [
        'seating-plan' => ['pdf.exam.seating-plan', 'a4', 'portrait', 'Seating-Plan'],
        'room-plan'    => ['pdf.exam.room-plan', 'a4', 'landscape', 'Room-Plan'],
        'p7'           => ['pdf.exam.p7', 'a4', 'portrait', 'P7'],
        'p7-practical' => ['pdf.exam.p7-practical', 'a4', 'portrait', 'P7-Practical-Viva'],
        'desk-slip'    => ['pdf.exam.desk-slip', 'a4', 'portrait', 'Desk-Slip'],
        'p4'           => ['pdf.exam.p4', 'a4', 'portrait', 'P4'],
        'p6'           => ['pdf.exam.p6', 'a4', 'portrait', 'P6'],
        'p9'           => ['pdf.exam.p9', 'a4', 'portrait', 'P9'],
        'p1'           => ['pdf.exam.p1', 'a4', 'portrait', 'P1'],
    ];

    public function show(Request $req, string $doc)
    {
        if (!isset(self::DOCS[$doc])) return response()->json(['message' => 'Unknown print document.'], 404);

        $v = Validator::make($req->all(), [
            'session_year' => 'required|string|max:12',
            'exam_date'    => 'required|date',
            'inning_id'    => 'required|integer|exists:exam_innings,id',
            'paper_code'   => 'nullable|string|max:30',
            'room_id'      => 'nullable|integer',
            'mode'         => 'nullable|in:all,received,blank',
        ]);
        if ($v->fails()) return response()->json(['message' => $v->errors()->first(), 'errors' => $v->errors()], 422);

        [$view, $size, $orient, $prefix] = self::DOCS[$doc];
        $mode = $req->input('mode', 'all');
        $date = date('Y-m-d', strtotime((string) $req->exam_date));

        if ($doc === 'desk-slip') @set_time_limit(300); // one photo read per student

        try {
            $data = ExamPrintData::load($req->session_year, $date, (int) $req->inning_id, $req->paper_code ?: null, $req->room_id ? (int) $req->room_id : null, $doc === 'desk-slip');

            if ($doc === 'p1' && $mode === 'blank') {
                $data['papers'] = [(object) ['paper_code' => '', 'course' => '', 'semester' => '', 'subject' => '', 'rooms' => collect(), 'seats' => collect(), 'absent' => [], 'ufm' => [], 'absent_by_room' => [], 'ufm_by_room' => [], 'p1' => null]];
            } elseif (empty($data['papers'])) {
                return response()->json(['message' => 'No exam is scheduled for this date and inning.'], 422);
            }
            // Seating-based forms are meaningless without a saved plan.
            if (in_array($doc, ['seating-plan', 'room-plan', 'p7', 'desk-slip'], true) && collect($data['papers'])->sum(fn ($p) => $p->seats->count()) === 0) {
                return response()->json(['message' => 'No seating plan is saved for this date and inning — save the Seating Plan first.'], 422);
            }

            $pdf = Pdf::loadView($view, $data + ['mode' => $mode])->setPaper($size, $orient);
            $name = $prefix . '-' . $date . ($req->paper_code ? '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $req->paper_code) : '') . '.pdf';
            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $name . '"',
                'Cache-Control' => 'no-store',
            ]);
        } catch (\Throwable $e) {
            \Log::error("Exam print [$doc] failed: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Could not generate the PDF.'], 500);
        }
    }
}
