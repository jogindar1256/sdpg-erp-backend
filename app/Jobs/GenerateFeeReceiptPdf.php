<?php

namespace App\Jobs;

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
            'student', 'organization', 'admission.program', 'generatedBy'
        ])->findOrFail($this->receiptId);

        // dompdf takes page margins from the Blade's CSS @page rule, not setOption().
        $pdf = Pdf::loadView('pdf.fee-receipt', compact('receipt'))->setPaper('a4');

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
