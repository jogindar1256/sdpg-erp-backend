@php
    $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d-M-Y') : '—';
    $val = fn ($v) => ($v === null || $v === '' || $v === []) ? '—' : (is_array($v) ? '' : $v);
    $course = $program->full_name ?? $program->short_name ?? '—';
    $collegeName = $org->name ?? 'Swami Devanand Post Graduate College, Math Lar';

    // Humanize a snake_case JSON key into a readable label, e.g. "father_name_english" -> "Father Name English"
    $label = function (string $key) {
        $key = str_replace(['_1', '_2', '_3'], [' 1', ' 2', ' 3'], $key);
        return ucwords(str_replace('_', ' ', $key));
    };

    $partTitles = [
        1 => 'Personal Details',
        2 => 'Address & Communication',
        3 => 'Educational Details',
        4 => 'TC & Migration Details',
        5 => 'Bank Details',
        6 => 'Subject & Paper Selection',
        7 => 'Uploaded Documents',
        8 => 'Declaration (Shapath Patra)',
    ];
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
    .pay { border: 2px solid #15803d; border-radius: 4px; margin-bottom: 10px; }
    .pay .ph { background: #15803d; color: #fff; font-weight: bold; padding: 5px 8px; font-size: 12px; }
    table.pay-t { width: 100%; border-collapse: collapse; }
    table.pay-t td { padding: 5px 8px; border: 1px solid #bbf7d0; }
    table.pay-t td.k { background: #f0fdf4; font-weight: bold; width: 22%; }
    .foot { margin-top: 22px; font-size: 10px; color: #6b7280; }
    .sign { margin-top: 34px; text-align: right; font-size: 11px; }
    .status-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-weight: bold; }
</style>
</head>
<body>

    <div class="head">
        <div class="college">{{ $collegeName }}</div>
        <div class="sub">Admission Application Form &mdash; Session {{ $sa->academic_year }}</div>
    </div>

    <div class="title">APPLICATION FORM ({{ strtoupper(str_replace('_', ' ', $sa->application_type)) }})</div>

    {{-- Payment Status --}}
    <div class="pay">
        <div class="ph">Education Fee Payment Status</div>
        <table class="pay-t">
            <tr>
                <td class="k">Status</td><td>{{ ucfirst($admission->payment_status ?? '—') }}</td>
                <td class="k">Paid On</td><td>{{ $fmt($admission->paid_at ?? null) }}</td>
            </tr>
        </table>
    </div>

    {{-- Application --}}
    <div class="sec">Application</div>
    <table class="kv">
        <tr><td class="k">Application No.</td><td>{{ $val($sa->application_no) }}</td><td class="k">Type</td><td>{{ ucwords(str_replace('_', ' ', $sa->application_type)) }}</td></tr>
        <tr><td class="k">Course / Class</td><td>{{ $course }}</td><td class="k">Session</td><td>{{ $val($sa->academic_year) }}</td></tr>
        <tr><td class="k">Semester</td><td>{{ $val($sa->semester_no) }}</td><td class="k">Status</td><td>{{ ucwords(str_replace('_', ' ', $sa->status)) }}</td></tr>
    </table>

    {{-- Student (base profile) --}}
    <div class="sec">Student Profile</div>
    <table class="kv">
        <tr><td class="k">Name</td><td>{{ $val($student->name ?? null) }}</td><td class="k">Father's Name</td><td>{{ $val($student->father_name ?? null) }}</td></tr>
        <tr><td class="k">Mother's Name</td><td>{{ $val($student->mother_name ?? null) }}</td><td class="k">Mobile</td><td>{{ $val($student->mobile ?? null) }}</td></tr>
        <tr><td class="k">Gender</td><td>{{ $val($student->gender ?? null) }}</td><td class="k">Date of Birth</td><td>{{ $fmt($student->dob ?? null) }}</td></tr>
        <tr><td class="k">Category</td><td>{{ $val($student->category ?? null) }}</td><td class="k">Aadhar No.</td><td>{{ $val($student->aadhar_no ?? null) }}</td></tr>
    </table>

    {{-- Parts 1-8 (dynamic JSON dump, humanized) --}}
    @foreach ($parts as $n => $data)
        @if (!empty($data) && is_array($data))
            <div class="sec">{{ $partTitles[$n] ?? "Part {$n}" }}</div>
            <table class="kv">
                @php $entries = array_filter($data, fn ($v) => !is_array($v) && $v !== null && $v !== ''); @endphp
                @foreach (array_chunk($entries, 2, true) as $chunk)
                    <tr>
                        @foreach ($chunk as $k => $v)
                            <td class="k">{{ $label((string) $k) }}</td><td>{{ $val($v) }}</td>
                        @endforeach
                        @if (count($chunk) === 1)
                            <td class="k"></td><td></td>
                        @endif
                    </tr>
                @endforeach
            </table>
        @endif
    @endforeach

    <div class="sign">Authorised Signatory</div>
    <div class="foot">This is a system-generated application form. Application No. {{ $sa->application_no }} — printed on {{ now()->format('d-M-Y H:i') }}.</div>

</body>
</html>
