{{-- Copy to: resources/views/pdf/registration-receipt.blade.php
     Rendered by SnappyPdf (wkhtmltopdf) in StudentRegistrationController::generateReceiptPdf(). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Registration Receipt — {{ $reg->registration_no }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; color: #1f2937; margin: 0; padding: 28px; font-size: 13px; }
        .sheet { border: 2px solid #6B2D0F; border-radius: 6px; overflow: hidden; }
        .head { background: #6B2D0F; color: #fff; padding: 14px 18px; text-align: center; }
        .head h1 { margin: 0; font-size: 19px; letter-spacing: 1px; }
        .head p  { margin: 3px 0 0; font-size: 12px; opacity: .9; }
        .ribbon { background: #f3f4f6; border-bottom: 1px solid #e5e7eb; padding: 8px 18px; display: flex; justify-content: space-between; font-size: 12px; }
        .title  { text-align: center; font-weight: 700; color: #6B2D0F; font-size: 15px; margin: 14px 0 6px; text-transform: uppercase; letter-spacing: .5px; }
        table   { width: 100%; border-collapse: collapse; margin: 8px 0; }
        td      { padding: 7px 12px; border: 1px solid #d1d5db; vertical-align: top; }
        td.k    { background: #f9fafb; font-weight: 700; width: 32%; color: #374151; }
        .paid   { display: inline-block; padding: 3px 12px; border-radius: 4px; font-weight: 700; }
        .paid.y { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .paid.n { background: #fef3c7; color: #b45309; border: 1px solid #fcd34d; }
        .amount { font-size: 22px; font-weight: 800; color: #6B2D0F; }
        .foot   { padding: 14px 18px; font-size: 11px; color: #6b7280; text-align: center; border-top: 1px dashed #d1d5db; }
        .note   { font-size: 11px; color: #9ca3af; text-align: center; margin-top: 10px; }
    </style>
</head>
<body>
    @php
        $paid    = ($reg->payment_status === 'paid');
        $course  = $program->full_name ?? ($program->short_name ?? $reg->reg_type);
        $orgName = $org->name ?? 'S.D.P.G. College, Mukhed';
    @endphp

    <div class="sheet">
        <div class="head">
            <h1>{{ strtoupper($orgName) }}</h1>
            <p>Online Student Registration — Fee Receipt</p>
        </div>

        <div class="ribbon">
            <span><strong>Receipt No:</strong> {{ $reg->receipt_no ?? '—' }}</span>
            <span><strong>Date:</strong> {{ $reg->paid_at ? \Illuminate\Support\Carbon::parse($reg->paid_at)->format('d-m-Y H:i') : date('d-m-Y') }}</span>
        </div>

        <div class="title">Registration Fee Receipt</div>

        <div style="padding: 0 18px;">
            <table>
                <tr><td class="k">Registration No.</td><td><strong>{{ $reg->registration_no }}</strong></td></tr>
                <tr><td class="k">Candidate Name</td><td>{{ $reg->name }}</td></tr>
                <tr><td class="k">Father's Name</td><td>{{ $reg->father_name }}</td></tr>
                <tr><td class="k">Mother's Name</td><td>{{ $reg->mother_name }}</td></tr>
                <tr><td class="k">Course / Level</td><td>{{ $course }} ({{ $reg->reg_type }})</td></tr>
                <tr><td class="k">Session</td><td>{{ $reg->session_year }}</td></tr>
                <tr><td class="k">Category</td><td>{{ $reg->category ?? '—' }}</td></tr>
                <tr><td class="k">Mobile / Email</td><td>{{ $reg->mobile }} &nbsp;|&nbsp; {{ $reg->email }}</td></tr>
                <tr><td class="k">Aadhar No.</td><td>{{ $reg->aadhar_no ?? '—' }}</td></tr>
                <tr><td class="k">Transaction / Payment ID</td><td>{{ $reg->razorpay_payment_id ?? '—' }}</td></tr>
                <tr>
                    <td class="k">Registration Fee</td>
                    <td><span class="amount">&#8377; {{ number_format((float) $reg->fee_amount, 2) }}</span></td>
                </tr>
                <tr>
                    <td class="k">Payment Status</td>
                    <td>
                        <span class="paid {{ $paid ? 'y' : 'n' }}">{{ $paid ? 'PAID' : 'PENDING' }}</span>
                    </td>
                </tr>
            </table>
        </div>

        <div class="foot">
            This is a computer-generated receipt and does not require a signature.<br>
            Login credentials have been sent to your registered email. Keep this receipt for your records.
        </div>
    </div>

    <div class="note">Generated on {{ date('d-m-Y H:i') }} · {{ $orgName }} ERP</div>
</body>
</html>
