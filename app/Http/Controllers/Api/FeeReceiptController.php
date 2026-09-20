<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateFeeReceiptPdf;
use App\Models\FeeReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeeReceiptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FeeReceipt::with(['student', 'admission.program', 'generatedBy'])
            ->where('organization_id', $request->user()->organization_id);

        if ($request->filled('academic_year'))
            $query->where('academic_year', $request->academic_year);
        if ($request->filled('receipt_type'))
            $query->where('receipt_type', $request->receipt_type);
        if ($request->filled('student_id'))
            $query->where('student_id', $request->student_id);
        if ($request->filled('status'))
            $query->where('status', $request->status);
        if ($request->filled('from_date'))
            $query->whereDate('receipt_date', '>=', $request->from_date);
        if ($request->filled('to_date'))
            $query->whereDate('receipt_date', '<=', $request->to_date);

        return response()->json(
            $query->orderBy('receipt_date', 'desc')->paginate($request->get('per_page', 20))
        );
    }

    public function show(FeeReceipt $feeReceipt): JsonResponse
    {
        $feeReceipt->load(['student', 'admission.program', 'generatedBy', 'verifiedBy', 'organization']);
        return response()->json($feeReceipt);
    }

    /**
     * Download PDF — returns binary or signed URL
     */
    public function download(FeeReceipt $feeReceipt)
    {
        if (!$feeReceipt->pdf_path || !\Illuminate\Support\Facades\Storage::exists($feeReceipt->pdf_path)) {
            // Generate on demand if not ready
            GenerateFeeReceiptPdf::dispatchSync($feeReceipt->id);
            $feeReceipt->refresh();
        }

        return \Illuminate\Support\Facades\Storage::download(
            $feeReceipt->pdf_path,
            "FeeReceipt-{$feeReceipt->receipt_no}.pdf"
        );
    }

    /**
     * Office: Verify a fee receipt
     */
    public function verify(Request $request, FeeReceipt $feeReceipt): JsonResponse
    {
        $this->authorize('verify-fee-receipts');

        $feeReceipt->update([
            'is_verified' => true,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ]);

        return response()->json(['message' => 'Receipt verified successfully.']);
    }

    /**
     * Cancel a fee receipt
     */
    public function cancel(Request $request, FeeReceipt $feeReceipt): JsonResponse
    {
        $request->validate(['cancel_reason' => 'required|string|max:500']);

        if ($feeReceipt->status !== 'active') {
            return response()->json(['message' => 'This receipt is already cancelled.'], 422);
        }

        $feeReceipt->update([
            'status' => 'cancelled',
            'cancel_reason' => $request->cancel_reason,
        ]);

        return response()->json(['message' => 'Receipt cancelled.']);
    }

    public function financialSummary(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $year = $request->get('academic_year');

        $query = FeeReceipt::where('organization_id', $orgId)->where('status', 'active');
        if ($year)
            $query->where('academic_year', $year);

        return response()->json([
            'total_collection' => $query->sum('net_amount'),
            'by_type' => $query->selectRaw('receipt_type, sum(net_amount) as total')
                ->groupBy('receipt_type')
                ->pluck('total', 'receipt_type'),
            'by_month' => $query->selectRaw("to_char(receipt_date, 'YYYY-MM') as month, sum(net_amount) as total")
                ->groupBy('month')
                ->orderBy('month')
                ->pluck('total', 'month'),
            'by_payment_mode' => $query->selectRaw('payment_mode, count(*) as count, sum(net_amount) as total')
                ->groupBy('payment_mode')
                ->get(),
        ]);
    }
}