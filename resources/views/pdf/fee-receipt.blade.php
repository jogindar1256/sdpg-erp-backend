{{--
    Payment / Fee Slip — "Applicant Admission Detail". Rebuilt field-for-field
    against the exact reference the college supplied (Payment Fee Slip.pdf),
    not the generic receipt layout this file used to have.

    Data comes from two payments that are genuinely separate in this system:
      - "Online Registration Fee Payment Detail" — the direct_registrations
        row ($registration), paid before the application even existed.
      - "Online Admission Fee Payment Detail" — this FeeReceipt ($receipt),
        the education fee paid at application-approval time.
    Everything else (course, personal, subjects) comes from the application's
    own part_1/part_2/part_6 JSON, same source buildApplicationFormPdfData()
    already assembles for the application form PDF — falling back to the
    registration snapshot, then the students-table row, in that order.

    No college logo image asset exists in this repo, so the header banner
    below is recreated in CSS/text rather than an embedded image.
--}}
@php
    $fmt = fn($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d-M-Y') : null;
    $val = fn($v) => ($v === null || $v === '') ? '—' : $v;
    $money = fn($v) => number_format((float) ($v ?? 0), 2);

    $collegeName = $org->name ?? 'Swami Devanand P.G. College';
    $collegeAddr = trim(implode(', ', array_filter([$org->address ?? 'Math-Lar', $org->district ?? 'Deoria'])))
        . ' (' . ($org->state ?? 'Uttar Pradesh') . ') ' . ($org->pin_code ?? '');
    $affiliation = $org->university_name ?? 'Deen Dayal Upadhyay Gorakhpur University, Gorakhpur';

    $p1 = $parts[1] ?? [];
    $p2 = $parts[2] ?? [];
    $p6 = $parts[6] ?? [];

    $studentName = $p1['name_english'] ?? $registration->name ?? ($student ? trim(implode(' ', array_filter([$student->first_name ?? null, $student->middle_name ?? null, $student->last_name ?? null]))) : null);
    $fatherName  = $p1['father_name_english'] ?? $registration->father_name ?? null;
    $motherName  = $p1['mother_name_english'] ?? $registration->mother_name ?? null;
    $gender      = $p1['gender'] ?? $registration->gender ?? $student->gender ?? null;
    $category    = $p1['social_category'] ?? $registration->category ?? $student->category ?? null;
    $religion    = $p1['religion'] ?? $registration->religion ?? $student->religion ?? null;
    $dob         = $p1['date_of_birth'] ?? $registration->dob ?? $student->date_of_birth ?? null;
    $domicile    = $p1['domestic_state'] ?? $registration->domestic_state ?? null;
    $abcId       = $p1['abc_id'] ?? $registration->abc_id ?? $student->abc_id ?? null;
    $ddurn       = $p1['ddurn_id'] ?? $registration->ddurn_no ?? $student->ddurn ?? null;
    $familyId    = $p1['family_id'] ?? $registration->family_id ?? $student->family_id ?? null;
    $aadhar      = $p1['aadhar_no'] ?? $registration->aadhar_no ?? $student->aadhar_no ?? null;
    $mobile      = $p2['mobile'] ?? $registration->mobile ?? $student->mobile ?? null;
    $email       = $p2['email'] ?? $registration->email ?? $student->email ?? null;

    // Address — same same_as_permanent logic Part8Declaration.tsx / the
    // application-form PDF use, falling back to the students-table columns.
    $addrPrefix = ($p2['same_as_permanent'] ?? true) ? 'perm' : 'pres';
    $addressLine = collect([
        $p2["{$addrPrefix}_house_no"] ?? null,
        $p2["{$addrPrefix}_mohalla"] ?? null,
        $p2["{$addrPrefix}_post_office"] ?? null,
        $p2["{$addrPrefix}_district"] ?? $student->permanent_district ?? null,
        $p2["{$addrPrefix}_state"] ?? $student->permanent_state ?? null,
        $p2["{$addrPrefix}_pin_code"] ?? $student->permanent_pin ?? null,
    ])->filter()->implode(', ') ?: ($student->permanent_address ?? null);

    $typeOfCourse = match ($sa->application_type ?? null) {
        'regular'          => 'Regular',
        'back_paper'       => 'Back Paper',
        'semester_upgrade' => 'Upgrade',
        default            => $val(null),
    };

    $courseName = $program->full_name ?? $program->short_name ?? null;

    $majorSubjects = collect([
        $p6['major_subject_1'] ?? null,
        $p6['major_subject_2'] ?? null,
        $p6['major_subject_3'] ?? null,
    ])->filter()->map(fn ($id) => $subjectsById->get($id)->name ?? null)->filter()->values();

    $minorSubject = isset($p6['minor_subject_1']) ? ($subjectsById->get($p6['minor_subject_1'])->name ?? null) : null;
@endphp
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 10mm 12mm; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #111827; }

        table.outer { width: 100%; border-collapse: collapse; }

        /* ── Header banner ─────────────────────────────────────────── */
        .banner {
            border: 2px solid #0f5132;
            background: linear-gradient(#eafaf1, #d7f2e3);
            padding: 6px 10px;
        }
        .banner-name { color: #b91c1c; font-size: 18px; font-weight: bold; letter-spacing: 0.5px; }
        .banner-addr { color: #1f2937; font-size: 10px; font-weight: bold; }
        .banner-affil { color: #374151; font-size: 8px; }
        .banner-estd { color: #92400e; font-size: 8px; font-weight: bold; }

        .appno-box { border: 1px solid #111827; text-align: center; padding: 4px 8px; }
        .appno-box .lbl { font-size: 9px; }
        .appno-box .val { font-size: 15px; font-weight: bold; }

        .title-bar {
            text-align: center; background: #d1d5db; font-weight: bold;
            font-size: 12px; padding: 4px; border: 1px solid #111827; border-top: none;
        }

        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { border: 1px solid #111827; padding: 2px 5px; vertical-align: top; font-size: 9.5px; }
        table.kv td.k { width: 15%; font-weight: bold; white-space: nowrap; }
        table.kv td.v { width: 27%; }

        .sec-bar {
            background: #d1d5db; font-weight: bold; font-size: 10px;
            padding: 3px 6px; border: 1px solid #111827; border-top: none;
        }

        table.photo-wrap { width: 100%; border-collapse: collapse; }
        table.photo-wrap td { border: 1px solid #111827; border-top: none; padding: 2px 5px; font-size: 9.5px; vertical-align: top; }
        .photo-box { width: 90px; height: 105px; border: 1px solid #9ca3af; text-align: center; }
        .photo-box img { width: 86px; height: 101px; object-fit: cover; }

        table.subj { width: 100%; border-collapse: collapse; }
        table.subj td { border: 1px solid #111827; border-top: none; padding: 3px 6px; font-size: 9.5px; vertical-align: top; }

        .pay-title {
            text-align: center; background: #111827; color: #fff; font-weight: bold;
            font-size: 11px; padding: 4px; border: 1px solid #111827; border-top: none;
        }
        table.pay-split { width: 100%; border-collapse: collapse; }
        table.pay-split > tbody > tr > td { border: 1px solid #111827; border-top: none; vertical-align: top; width: 50%; padding: 0; }
        .pay-h { background: #f0fdf4; font-weight: bold; text-align: center; padding: 3px; border-bottom: 1px solid #111827; font-size: 9.5px; }
        table.pay-kv { width: 100%; border-collapse: collapse; }
        table.pay-kv td { padding: 2px 6px; font-size: 9.5px; }
        table.pay-kv td.k { width: 55%; font-weight: bold; }
        .amt { font-weight: bold; }

        .office-title {
            text-align: center; background: #d1d5db; font-weight: bold; font-size: 10px;
            padding: 3px; border: 1px solid #111827; border-top: none;
        }
        table.office { width: 100%; border-collapse: collapse; }
        table.office > tbody > tr > td { border: 1px solid #111827; border-top: none; vertical-align: top; width: 50%; padding: 4px 6px; font-size: 9.5px; }
        .office h4 { font-weight: bold; text-decoration: underline; font-size: 9.5px; margin: 0 0 4px; }
        .sign-line { margin-top: 18px; }

        .foot-note {
            text-align: center; font-weight: bold; font-size: 9.5px; padding: 5px;
            border: 1px solid #111827; border-top: none;
        }
        .applicant-sign { text-align: right; font-weight: bold; font-size: 10px; margin-top: 16px; }
    </style>
</head>

<body>

    <table class="outer">
        <tr>
            <td style="width: 75%; padding-right: 4px;">
                <div class="banner">
                    <div class="banner-name">{{ strtoupper($collegeName) }}</div>
                    <div class="banner-addr">{{ $collegeAddr }}</div>
                    <div class="banner-affil">Affiliated By&mdash; {{ $affiliation }}</div>
                </div>
            </td>
            <td style="width: 25%;">
                <div class="appno-box">
                    <div class="lbl">Application No.</div>
                    <div class="val">{{ $val($sa->application_no ?? null) }}</div>
                    @if(!empty($sa->fee_ref_id ?? null))
                        <div class="lbl" style="margin-top: 3px;">Fee Ref. No.</div>
                        <div style="font-size: 11px; font-weight: bold;">{{ $sa->fee_ref_id }}</div>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="title-bar">Applicant Admission Detail</div>

    <table class="kv">
        <tr>
            <td class="k">Academic Session</td><td class="v">{{ $val($sa->academic_year ?? null) }}</td>
            <td class="k">ABC/APAAR ID No.</td><td class="v">{{ $val($abcId) }}</td>
        </tr>
        <tr>
            <td class="k">Course</td><td class="v">{{ $val($courseName) }}</td>
            <td class="k">DDURN No.</td><td class="v">{{ $val($ddurn) }}</td>
        </tr>
        <tr>
            <td class="k">Semester</td><td class="v">{{ $val($sa->semester_no ?? null) }}</td>
            <td class="k">Family ID</td><td class="v">{{ $val($familyId) }}</td>
        </tr>
        <tr>
            <td class="k">Type of Course</td><td class="v">{{ $typeOfCourse }}</td>
            <td class="k">Adhar Card No.</td><td class="v">{{ $val($aadhar) }}</td>
        </tr>
        <tr>
            <td class="k">Enrolment No.</td><td class="v">{{ $val($student->enrollment_no ?? null) }}</td>
            <td class="k">Mobile No.</td><td class="v">{{ $val($mobile) }}</td>
        </tr>
        <tr>
            <td class="k">&nbsp;</td><td class="v">&nbsp;</td>
            <td class="k">E-Mail ID</td><td class="v">{{ $val($email) }}</td>
        </tr>
    </table>

    <table class="photo-wrap">
        <tr>
            <td style="width: 78%;">
                <table class="kv" style="border: none;">
                    <tr>
                        <td class="k" style="border: none; width: 20%;">Univ. Roll No.</td>
                        <td class="v" style="border: none; width: 80%; font-weight: bold; text-decoration: underline;">{{ $val($student->university_roll_no ?? null) }}</td>
                    </tr>
                </table>
                <table class="kv" style="border: none;">
                    <tr><td class="k" style="border: none; width: 20%;">Student Name</td><td class="v" style="border: none; font-weight: bold;">{{ $val($studentName) }}</td></tr>
                    <tr>
                        <td class="k" style="border: none;">Category</td><td class="v" style="border: none; width: 20%;">{{ $val($category) }}</td>
                        <td class="k" style="border: none; width: 15%;">Gender</td><td class="v" style="border: none;">{{ $val($gender) }}</td>
                    </tr>
                    <tr><td class="k" style="border: none;">Fathers Name</td><td class="v" style="border: none;" colspan="3">{{ $val($fatherName) }}</td></tr>
                    <tr><td class="k" style="border: none;">Mother Name</td><td class="v" style="border: none;" colspan="3">{{ $val($motherName) }}</td></tr>
                    <tr>
                        <td class="k" style="border: none;">Date of Birth</td><td class="v" style="border: none;">{{ $fmt($dob) ?? '—' }}</td>
                        <td class="k" style="border: none;">Religion</td><td class="v" style="border: none;">{{ $val($religion) }}</td>
                    </tr>
                    <tr><td class="k" style="border: none;">Domicile State</td><td class="v" style="border: none;" colspan="3">{{ $val($domicile) }}</td></tr>
                </table>
            </td>
            <td style="width: 22%; text-align: center;">
                <div class="photo-box">
                    @if($photoDataUri)
                        <img src="{{ $photoDataUri }}">
                    @else
                        <div style="padding-top: 42px; color: #9ca3af;">Photo</div>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="kv">
        <tr><td class="k" style="width: 10%;">Address</td><td class="v" colspan="3">{{ $val($addressLine) }}</td></tr>
    </table>

    <table class="subj">
        <tr>
            <td style="width: 60%;">
                <strong>Selected Subject</strong>
                @forelse($majorSubjects as $i => $name)
                    <div>{{ $i + 1 }}- {{ $name }}</div>
                @empty
                    <div>—</div>
                @endforelse
            </td>
            <td style="width: 40%;">
                <div><strong>Minor Subject</strong> :- {{ $val($minorSubject) }}</div>
                <div style="margin-top: 4px;"><strong>Research Subject</strong> :- —</div>
                <div style="margin-top: 4px;"><strong>Drop Subject</strong> :- —</div>
            </td>
        </tr>
    </table>

    <div class="sec-bar">Fee Payment By Applicant</div>

    <table class="pay-split">
        <tr>
            <td>
                <div class="pay-h">Online Registration Fee Payment Detail</div>
                <table class="pay-kv">
                    <tr><td class="k">Payment Date</td><td>{{ $fmt($registration->paid_at ?? null) ?? '—' }}</td></tr>
                    <tr><td class="k">Payment Mode</td><td>Online</td></tr>
                    <tr><td class="k">Bank Ref. No.</td><td>{{ $val($registration->razorpay_order_id ?? null) }}</td></tr>
                    <tr><td class="k">Transaction ID</td><td>{{ $val($registration->razorpay_payment_id ?? null) }}</td></tr>
                    <tr><td class="k">Payment Amount</td><td class="amt">{{ $registration ? $money($registration->fee_amount) : '—' }}</td></tr>
                </table>
            </td>
            <td>
                <div class="pay-h">Online Admission Fee Payment Detail</div>
                <table class="pay-kv">
                    <tr><td class="k">Payment Date</td><td>{{ $fmt($receipt->receipt_date ?? null) ?? '—' }}</td></tr>
                    <tr><td class="k">Payment Mode</td><td>{{ ucfirst($val($receipt->payment_mode ?? null)) }}</td></tr>
                    <tr><td class="k">Bank Ref No.</td><td>{{ $val($sa->razorpay_order_id ?? null) }}</td></tr>
                    <tr><td class="k">Transaction ID</td><td>{{ $val($receipt->transaction_id ?? null) }}</td></tr>
                    <tr><td class="k">Payment Amount Rs.</td><td class="amt">{{ $money($receipt->net_amount ?? 0) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="office-title">Office Use Only</div>

    <table class="office">
        <tr>
            <td>
                <h4>Admission Detail</h4>
                <div>Student ID :- {{ $val($student->student_code ?? null) }}</div>
                <div>Final Receipt No. :- {{ $val($receipt->receipt_no ?? null) }}</div>
                <div>Class A/C No. :- —</div>
                <div class="sign-line">Date :- &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Emp. Sign.</div>
            </td>
            <td>
                <h4>Exam Form Update Detail</h4>
                <div>Exam Form Regi. No:- —</div>
                <div class="sign-line">Date :- &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Emp. Sign.</div>
            </td>
        </tr>
    </table>

    <div class="foot-note">Please Submit this receipt in college office with your approved application form.</div>

    <div class="applicant-sign">Applicant Sign.</div>

</body>

</html>
