@php
    $fmt = fn($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d-M-Y') : '—';
    $val = fn($v) => ($v === null || $v === '') ? '—' : $v;
    $course = $program->full_name ?? $program->short_name ?? $reg->reg_type;
    $collegeName = $org->name ?? 'Swami Devanand Post Graduate College, Math Lar';
@endphp
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 14mm;
        }

        * {
            font-family: DejaVu Sans, sans-serif;
        }

        body {
            font-size: 11px;
            color: #1f2937;
        }

        .head {
            text-align: center;
            border-bottom: 2px solid #2d5016;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }

        .head .college {
            font-size: 16px;
            font-weight: bold;
            color: #2d5016;
        }

        .head .sub {
            font-size: 11px;
            color: #555;
        }

        .title {
            text-align: center;
            background: #2d5016;
            color: #fff;
            font-weight: bold;
            padding: 5px;
            font-size: 13px;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .sec {
            background: #e5e7eb;
            font-weight: bold;
            padding: 4px 8px;
            margin-top: 10px;
            font-size: 11px;
            border-left: 4px solid #2d5016;
        }

        table.kv {
            width: 100%;
            border-collapse: collapse;
            margin-top: 3px;
        }

        table.kv td {
            padding: 4px 8px;
            border: 1px solid #d1d5db;
            vertical-align: top;
        }

        table.kv td.k {
            width: 22%;
            background: #f9fafb;
            font-weight: bold;
            color: #374151;
        }

        .pay {
            border: 2px solid #15803d;
            border-radius: 4px;
            margin-bottom: 10px;
        }

        .pay .ph {
            background: #15803d;
            color: #fff;
            font-weight: bold;
            padding: 5px 8px;
            font-size: 12px;
        }

        table.pay-t {
            width: 100%;
            border-collapse: collapse;
        }

        table.pay-t td {
            padding: 5px 8px;
            border: 1px solid #bbf7d0;
        }

        table.pay-t td.k {
            background: #f0fdf4;
            font-weight: bold;
            width: 22%;
        }

        .foot {
            margin-top: 22px;
            font-size: 10px;
            color: #6b7280;
        }

        .sign {
            margin-top: 34px;
            text-align: right;
            font-size: 11px;
        }
    </style>
</head>

<body>

    <div class="head">
        <div class="college">{{ $collegeName }}</div>
        <div class="sub">Student Registration Slip &mdash; Session {{ $reg->session_year }}</div>
    </div>

    <div class="title">REGISTRATION SLIP</div>

    {{-- Registration --}}
    <div class="sec">Registration</div>
    <table class="kv">
        <tr>
            <td class="k">Registration No.</td>
            <td>{{ $val($reg->registration_no) }}</td>
            <td class="k">Type</td>
            <td>{{ $reg->reg_type }}</td>
        </tr>
        <tr>
            <td class="k">Course / Class</td>
            <td>{{ $course }}</td>
            <td class="k">Registration Date</td>
            <td>{{ $fmt($reg->reg_date) }}</td>
        </tr>
        @if(!empty($reg->course_group))
            <tr>
                <td class="k">Group</td>
                <td colspan="3">{{ $reg->course_group }}</td>
        </tr>@endif
    </table>

    {{-- Personal --}}
    <div class="sec">Personal Details</div>
    <table class="kv">
        <tr>
            <td class="k">Name</td>
            <td>{{ $val($reg->name) }}</td>
            <td class="k">Name (Hindi)</td>
            <td>{{ $val($reg->name_hindi) }}</td>
        </tr>
        <tr>
            <td class="k">Father's Name</td>
            <td>{{ $val($reg->father_name) }}</td>
            <td class="k">Mother's Name</td>
            <td>{{ $val($reg->mother_name) }}</td>
        </tr>
        <tr>
            <td class="k">Date of Birth</td>
            <td>{{ $fmt($reg->dob) }}</td>
            <td class="k">Gender</td>
            <td>{{ $val($reg->gender) }}</td>
        </tr>
        <tr>
            <td class="k">Aadhar No.</td>
            <td>{{ $val($reg->aadhar_no) }}</td>
            <td class="k">ABC ID</td>
            <td>{{ $val($reg->abc_id) }}</td>
        </tr>
        <tr>
            <td class="k">DDURN No.</td>
            <td>{{ $val($reg->ddurn_no) }}</td>
            <td class="k">Family ID</td>
            <td>{{ $val($reg->family_id) }}</td>
        </tr>
        <tr>
            <td class="k">Domicile State</td>
            <td>{{ $val($reg->domestic_state) }}</td>
            <td class="k">Nationality</td>
            <td>{{ $val($reg->nationality) }}</td>
        </tr>
    </table>

    {{-- Category & Eligibility --}}
    <div class="sec">Category &amp; Eligibility</div>
    <table class="kv">
        <tr>
            <td class="k">Category</td>
            <td>{{ $val($reg->category) }}</td>
            <td class="k">Admission Category</td>
            <td>{{ $val($reg->admission_category) }}</td>
        </tr>
        <tr>
            <td class="k">Religion</td>
            <td>{{ $val($reg->religion) }}</td>
            <td class="k">Divyang</td>
            <td>{{ $val($reg->is_divyang) }}</td>
        </tr>
        <tr>
            <td class="k">Eligibility Class</td>
            <td>{{ $val($reg->eligibility_class) }}</td>
            <td class="k">Passing Year</td>
            <td>{{ $val($reg->passing_year) }}</td>
        </tr>
        <tr>
            <td class="k">Eligibility Roll No.</td>
            <td>{{ $val($reg->eligibility_roll_no) }}</td>
            <td class="k">Caste Cert. No.</td>
            <td>{{ $val($reg->caste_cert_no) }}</td>
        </tr>
    </table>

    {{-- Subjects --}}
    @if(!empty($subjects))
        <div class="sec">Subjects</div>
        <table class="kv">
            @foreach($subjects as $label => $name)
                <tr>
                    <td class="k">{{ $label }}</td>
                    <td colspan="3">{{ $name }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- Previous education (PG / B.Ed) --}}
    @if(!empty($reg->ug_university) || !empty($reg->ug_roll_no))
        <div class="sec">Previous Qualification</div>
        <table class="kv">
            <tr>
                <td class="k">University</td>
                <td>{{ $val($reg->ug_university) }}</td>
                <td class="k">Institute</td>
                <td>{{ $val($reg->ug_institute) }}</td>
            </tr>
            <tr>
                <td class="k">Session</td>
                <td>{{ $val($reg->ug_session) }}</td>
                <td class="k">Roll No.</td>
                <td>{{ $val($reg->ug_roll_no) }}</td>
            </tr>
        </table>
    @endif

    {{-- Entrance (B.Ed) --}}
    @if(!empty($reg->entrance_roll_no) || !empty($reg->stream))
        <div class="sec">Entrance / Counselling</div>
        <table class="kv">
            <tr>
                <td class="k">Stream</td>
                <td>{{ $val($reg->stream) }}</td>
                <td class="k">Entrance Roll No.</td>
                <td>{{ $val($reg->entrance_roll_no) }}</td>
            </tr>
            <tr>
                <td class="k">State Rank</td>
                <td>{{ $val($reg->state_rank) }}</td>
                <td class="k">Category Rank</td>
                <td>{{ $val($reg->category_rank) }}</td>
            </tr>
        </table>
    @endif

    {{-- Contact --}}
    <div class="sec">Contact</div>
    <table class="kv">
        <tr>
            <td class="k">Mobile</td>
            <td>{{ $val($reg->mobile) }}</td>
            <td class="k">Email</td>
            <td>{{ $val($reg->email) }}</td>
        </tr>
    </table>

    {{-- Payment Status --}}
    <div class="pay">
        <div class="ph">Payment Status</div>
        <table class="pay-t">
            <tr>
                <td class="k">Status</td>
                <td>{{ ucfirst($reg->payment_status) }}</td>
                <td class="k">Payment ID</td>
                <td>{{ $val($reg->razorpay_payment_id) }}</td>
            </tr>
            <tr>
                <td class="k">Amount Paid</td>
                <td>&#8377; {{ number_format((float) ($reg->fee_amount ?? 0), 2) }}</td>
                <td class="k">Payment Date</td>
                <td>{{ $fmt($reg->paid_at) }}</td>
            </tr>
            @if(!empty($reg->receipt_no))
                <tr>
                    <td class="k">Fee Receipt No.</td>
                    <td colspan="3">{{ $reg->receipt_no }}</td>
                </tr>
            @endif
        </table>
    </div>

    <div class="sign">Authorised Signatory</div>
    <div class="foot">This is a system-generated registration slip. Registration No. {{ $reg->registration_no }} —
        printed on {{ now()->format('d-M-Y H:i') }}.</div>

</body>

</html>