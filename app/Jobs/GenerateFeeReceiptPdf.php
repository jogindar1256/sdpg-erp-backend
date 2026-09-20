<?php

namespace App\Jobs;

use App\Http\Controllers\Api\ApplicationController;
use App\Models\FeeReceipt;
use Barryvdh\DomPDF\Facade\Pdf;   // pure-PHP PDF (no wkhtmltopdf binary needed)
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenerateFeeReceiptPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $receiptId) {}

    public function handle(): void
    {
        $receipt = FeeReceipt::with([
            'student', 'organization', 'admission.program', 'admission.application', 'generatedBy'
        ])->findOrFail($this->receiptId);

        // The reference "Applicant Admission Detail" slip needs everything
        // buildApplicationFormPdfData() already assembles for the printed
        // application form (student/program/org/registration/part_1../
        // photo) — reuse it instead of re-deriving the same joins here.
        // Falls back to nulls (never fatal) if this receipt has no linked
        // application, e.g. a legacy/manual receipt.
        $sa = $receipt->admission->application ?? null;

        $data = [
            'sa' => null, 'student' => $receipt->student, 'program' => $receipt->admission->program ?? null,
            'org' => $receipt->organization, 'admission' => $receipt->admission, 'registration' => null,
            'documents' => collect(), 'parts' => [], 'subjectRows' => [], 'subjectsById' => collect(),
            'vocById' => collect(), 'photoDataUri' => null, 'signatureDataUri' => null,
        ];
        if ($sa) {
            $data = array_merge($data, app(ApplicationController::class)->buildApplicationFormPdfData($sa));
        }
        $data['receipt'] = $receipt;

        // dompdf takes page margins from the Blade's CSS @page rule, not setOption().
        $pdf = Pdf::loadView('pdf.fee-receipt', $data)->setPaper('a4');

        $path = "receipts/{$receipt->organization_id}/{$receipt->academic_year}/{$receipt->receipt_no}.pdf";

        Storage::put($path, $pdf->output());

        $receipt->update(['pdf_path' => $path]);
    }

    public function failed(\Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::error("FeeReceipt PDF generation failed", [
            'receipt_id' => $this->receiptId,
            'error'      => $exception->getMessage(),
        ]);
    }
}
