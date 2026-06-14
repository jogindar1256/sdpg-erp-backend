<?php

namespace App\Jobs;

use App\Models\FeeReceipt;
use Barryvdh\Snappy\Facades\SnappyPdf;
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

        $pdf = SnappyPdf::loadView('pdf.fee-receipt', compact('receipt'))
            ->setPaper('A4')
            ->setOption('margin-top', '10mm')
            ->setOption('margin-bottom', '10mm')
            ->setOption('margin-left', '10mm')
            ->setOption('margin-right', '10mm');

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
