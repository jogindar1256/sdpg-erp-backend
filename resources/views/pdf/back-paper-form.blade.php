@php
    $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d-M-Y') : '—';
    $val = fn ($v) => ($v === null || $v === '') ? '—' : $v;
    $collegeName = $org->name ?? 'Swami Devanand Post Graduate College, Math Lar';
    $course = $program->full_name ?? $program->short_name ?? '—';
    $total = collect($papers)->count();
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 14mm; }
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 11px; color: #1f2937; }
    .head { text-align: center; border-bottom: 2px solid #b45309; padding-bottom: 8px; margin-bottom: 10px; }
    .head .college { font-size: 16px; font-weight: bold; color: #b45309; }
    .head .sub { font-size: 11px; color: #555; }
    .title { text-align: center; background: #b45309; color: #fff; font-weight: bold; padding: 5px; font-size: 13px; letter-spacing: 1px; margin-bottom: 10px; }
    .sec { background: #e5e7eb; font-weight: bold; padding: 4px 8px; margin-top: 10px; font-size: 11px; border-left: 4px solid #b45309; }
    table.kv { width: 100%; border-collapse: collapse; margin-top: 3px; }
    table.kv td { padding: 4px 8px; border: 1px solid #d1d5db; vertical-align: top; }
    table.kv td.k { width: 22%; background: #f9fafb; font-weight: bold; color: #374151; }
    table.papers { width: 100%; border-collapse: collapse; margin-top: 3px; }
    table.papers th { background: #b45309; color: #fff; padding: 5px 8px; text-align: left; font-size: 10.5px; }
    table.papers td { padding: 5px 8px; border: 1px solid #d1d5db; }
    .pay { border: 2px solid #15803d; border-radius: 4px; margin-top: 10px; }
    .pay .ph { background: #15803d; color: #fff; font-weight: bold; padding: 5px 8px; font-size: 12px; }
    table.pay-t { width: 100%; border-collapse: collapse; }
    table.pay-t td { padding: 5px 8px; border: 1px solid #bbf7d0; }
    table.pay-t td.k { background: #f0fdf4; font-weight: bold; width: 22%; }
    .foot { margin-top: 22px; font-size: 10px; color: #6b7280; }
    .sign { margin-top: 34px; text-align: right; font-size: 11px; }
</style>
</head>
<body>

    <div class="head">
        <div class="college">{{ $collegeName }}</div>
        <div class="sub">Back Paper Application Form &mdash; Session {{ $sa->academic_year }}</div>
    </div>

    <div class="title">BACK PAPER FORM</div>

    <div class="sec">Application</div>
    <table class="kv">
        <tr><td class="k">Application No.</td><td>{{ $val($sa->application_no) }}</td><td class="k">Back Semester</td><td>{{ $val($sa->semester_no) }}</td></tr>
        <tr><td class="k">Back In Session</td><td>{{ $val($sa->academic_year) }}</td><td class="k">Class</td><td>{{ $course }}</td></tr>
        <tr><td class="k">Registration No.</td><td>{{ $val($admission->admission_no ?? null) }}</td><td class="k">Curr. Session</td><td>{{ $val($admission->academic_year ?? null) }}</td></tr>
    </table>

    <div class="sec">Student</div>
    <table class="kv">
        <tr><td class="k">Student Name</td><td>{{ $val($student->name ?? null) }}</td><td class="k">Father's Name</td><td>{{ $val($student->father_name ?? null) }}</td></tr>
        <tr><td class="k">Mother's Name</td><td>{{ $val($student->mother_name ?? null) }}</td><td class="k">DOB</td><td>{{ $fmt($student->dob ?? null) }}</td></tr>
        <tr><td class="k">Mobile No.</td><td>{{ $val($student->mobile ?? null) }}</td><td class="k">Enrolment No.</td><td>{{ $val($student->enrollment_no ?? null) }}</td></tr>
        <tr><td class="k">Uni. Roll No.</td><td>{{ $val($student->university_roll_no ?? null) }}</td><td class="k">ABC ID</td><td>{{ $val($student->abc_id ?? null) }}</td></tr>
    </table>

    <div class="sec">Subjects Selected ({{ $total }})</div>
    <table class="papers">
        <thead>
            <tr><th>Code</th><th>Subject Name</th><th>Type</th><th>Max Marks</th><th>Min Marks</th><th>Credits</th></tr>
        </thead>
        <tbody>
            @foreach ($papers as $p)
                <tr>
                    <td>{{ $p->code }}</td>
                    <td>{{ $p->name }}</td>
                    <td>{{ ucfirst($p->type) }}</td>
                    <td>{{ $p->max_marks }}</td>
                    <td>{{ $p->min_marks }}</td>
                    <td>{{ $p->credits }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="pay">
        <div class="ph">Payment Status</div>
        <table class="pay-t">
            <tr>
                <td class="k">Status</td><td>{{ $sa->fee_paid ? 'Paid' : 'Pending' }}</td>
                <td class="k">Amount Paid</td><td>&#8377; {{ number_format((float) ($sa->fee_amount ?? 0), 2) }}</td>
            </tr>
            <tr>
                <td class="k">Payment Ref.</td><td>{{ $val($sa->payment_ref) }}</td>
                <td class="k">Paid On</td><td>{{ $fmt($sa->paid_at) }}</td>
            </tr>
        </table>
    </div>

    <div class="sign">Authorised Signatory</div>
    <div class="foot">This is a system-generated back paper application form. Application No. {{ $sa->application_no }} — printed on {{ now()->format('d-M-Y H:i') }}.</div>

</body>
</html>
