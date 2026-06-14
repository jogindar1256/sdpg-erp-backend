<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateFeeReceiptPdf;
use App\Models\FeeReceipt;
use App\Models\FeeStructure;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeeReceiptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FeeReceipt::with(['student', 'admission.program', 'generatedBy'])
            ->where('organization_id', $request->user()->organization_id);

        if ($request->filled('academic_year')) $query->where('academic_year', $request->academic_year);
        if ($request->filled('receipt_type'))  $query->where('receipt_type', $request->receipt_type);
        if ($request->filled('student_id'))    $query->where('student_id', $request->student_id);
        if ($request->filled('status'))        $query->where('status', $request->status);
        if ($request->filled('from_date'))     $query->whereDate('receipt_date', '>=', $request->from_date);
        if ($request->filled('to_date'))       $query->whereDate('receipt_date', '<=', $request->to_date);

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
     * Generate a fee receipt for a student
     */
    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'student_id'     => 'required|exists:students,id',
            'admission_id'   => 'required|exists:admissions,id',
            'receipt_type'   => 'required|in:regular_admission,back_paper,semester_upgrade,miscellaneous',
            'academic_year'  => 'required|string',
            'semester_no'    => 'required|integer',
            'payment_mode'   => 'required|in:cash,dd,online,neft,upi,cheque',
            'transaction_id' => 'nullable|string',
            'bank_name'      => 'nullable|string',
            'dd_no'          => 'required_if:payment_mode,dd|string',
            'dd_date'        => 'required_if:payment_mode,dd|date',
            'concession'     => 'nullable|numeric|min:0',
            'fee_head_ids'   => 'required|array|min:1',
            'fee_head_ids.*' => 'exists:fee_heads,id',
        ]);

        // Fetch fee structure for selected heads
        $structures = FeeStructure::where('program_id', function ($q) use ($request) {
            $q->select('program_id')->from('admissions')->where('id', $request->admission_id);
        })
        ->where('academic_year', $request->academic_year)
        ->where('semester_no', $request->semester_no)
        ->whereIn('fee_head_id', $request->fee_head_ids)
        ->with('feeHead')
        ->get();

        // Calculate late fine (overdue check)
        $lateFine = $structures->sum(function ($s) {
            if ($s->due_date && now()->gt($s->due_date) && $s->late_fine_per_day > 0) {
                $days = now()->diffInDays($s->due_date);
                return $days * $s->late_fine_per_day;
            }
            return 0;
        });

        $totalAmount = $structures->sum('amount');
        $concession  = $request->concession ?? 0;
        $netAmount   = $totalAmount + $lateFine - $concession;

        $feeBreakdown = $structures->map(fn($s) => [
            'fee_head_id'   => $s->fee_head_id,
            'fee_head_name' => $s->feeHead->name,
            'amount'        => $s->amount,
        ])->toArray();

        $receipt = DB::transaction(fn() => FeeReceipt::create([
            'organization_id' => $request->user()->organization_id,
            'student_id'      => $request->student_id,
            'admission_id'    => $request->admission_id,
            'academic_year'   => $request->academic_year,
            'semester_no'     => $request->semester_no,
            'receipt_type'    => $request->receipt_type,
            'receipt_no'      => FeeReceipt::generateReceiptNo(
                                    $request->user()->organization_id,
                                    $request->academic_year,
                                    $request->receipt_type
                                 ),
            'receipt_date'    => now()->toDateString(),
            'total_amount'    => $totalAmount,
            'late_fine'       => $lateFine,
            'concession'      => $concession,
            'net_amount'      => $netAmount,
            'payment_mode'    => $request->payment_mode,
            'transaction_id'  => $request->transaction_id,
            'bank_name'       => $request->bank_name,
            'dd_no'           => $request->dd_no,
            'dd_date'         => $request->dd_date,
            'fee_breakdown'   => $feeBreakdown,
            'generated_by'    => $request->user()->id,
            'status'          => 'active',
        ]));

        // Generate PDF in background
        GenerateFeeReceiptPdf::dispatch($receipt->id);

        return response()->json([
            'message'    => 'Fee receipt generated successfully.',
            'receipt'    => $receipt->load(['student', 'organization']),
            'receipt_no' => $receipt->receipt_no,
        ], 201);
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
            'status'        => 'cancelled',
            'cancel_reason' => $request->cancel_reason,
        ]);

        return response()->json(['message' => 'Receipt cancelled.']);
    }

    public function financialSummary(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $year  = $request->get('academic_year');

        $query = FeeReceipt::where('organization_id', $orgId)->where('status', 'active');
        if ($year) $query->where('academic_year', $year);

        return response()->json([
            'total_collection' => $query->sum('net_amount'),
            'by_type'          => $query->selectRaw('receipt_type, sum(net_amount) as total')
                                        ->groupBy('receipt_type')
                                        ->pluck('total', 'receipt_type'),
            'by_month'         => $query->selectRaw("to_char(receipt_date, 'YYYY-MM') as month, sum(net_amount) as total")
                                        ->groupBy('month')
                                        ->orderBy('month')
                                        ->pluck('total', 'month'),
            'by_payment_mode'  => $query->selectRaw('payment_mode, count(*) as count, sum(net_amount) as total')
                                        ->groupBy('payment_mode')
                                        ->get(),
        ]);
    }
}
