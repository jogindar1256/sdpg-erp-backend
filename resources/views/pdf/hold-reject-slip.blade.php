{{--
    Hold/Reject decision slip — printed once a decision exists (status is
    on_hold or rejected). Styled after pdf/fee-receipt.blade.php's banner +
    kv-table conventions for visual consistency with the rest of this app's
    printable slips.
--}}
@php
    $fmt = fn($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d-M-Y') : null;
    $fmtDt = fn($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d-M-Y h:i A') : null;
    $val = fn($v) => ($v === null || $v === '') ? '—' : $v;

    $collegeName = $org->name ?? 'Swami Devanand P.G. College';
    $collegeAddr = trim(implode(', ', array_filter([$org->address ?? 'Math-Lar', $org->district ?? 'Deoria'])))
        . ' (' . ($org->state ?? 'Uttar Pradesh') . ') ' . ($org->pin_code ?? '');
    $affiliation = $org->university_name ?? 'Deen Dayal Upadhyay Gorakhpur University, Gorakhpur';

    $isHold = $decision->decision === 'hold';
    $objections = $decision->objections ? (json_decode($decision->objections, true) ?? []) : [];
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

        .banner {
            border: 2px solid #0f5132;
            background: linear-gradient(#eafaf1, #d7f2e3);
            padding: 6px 10px;
        }
        .banner-name { color: #b91c1c; font-size: 18px; font-weight: bold; letter-spacing: 0.5px; }
        .banner-addr { color: #1f2937; font-size: 10px; font-weight: bold; }
        .banner-affil { color: #374151; font-size: 8px; }

        .appno-box { border: 1px solid #111827; text-align: center; padding: 4px 8px; }
        .appno-box .lbl { font-size: 9px; }
        .appno-box .val { font-size: 15px; font-weight: bold; }

        .title-bar {
            text-align: center; font-weight: bold;
            font-size: 12px; padding: 4px; border: 1px solid #111827; border-top: none;
        }
        .title-bar.hold { background: #fef3c7; }
        .title-bar.reject { background: #fee2e2; }

        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { border: 1px solid #111827; padding: 3px 6px; vertical-align: top; font-size: 9.5px; }
        table.kv td.k { width: 22%; font-weight: bold; white-space: nowrap; }
        table.kv td.v { width: 28%; }

        .sec-bar {
            background: #d1d5db; font-weight: bold; font-size: 10px;
            padding: 3px 6px; border: 1px solid #111827; border-top: none;
        }
        .sec-body {
            border: 1px solid #111827; border-top: none; padding: 6px; font-size: 9.5px;
        }
        .sec-body ol { margin: 4px 0 0 16px; padding: 0; }

        .sign-block { margin-top: 28px; }
        table.sign { width: 100%; border-collapse: collapse; margin-top: 40px; }
        table.sign td { width: 33%; text-align: center; font-size: 9.5px; border-top: 1px solid #111827; padding-top: 3px; }
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
                    <div class="lbl" style="margin-top: 3px;">Ref. No.</div>
                    <div style="font-size: 11px; font-weight: bold;">{{ $val($decision->ref_no ?? null) }}</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="title-bar {{ $isHold ? 'hold' : 'reject' }}">
        Application {{ $isHold ? 'Hold' : 'Rejection' }} Slip
    </div>

    <table class="kv">
        <tr>
            <td class="k">Student Name</td><td class="v">{{ $val($studentName) }}</td>
            <td class="k">Academic Session</td><td class="v">{{ $val($sa->academic_year ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">Course</td><td class="v">{{ $val(\App\Models\Program::label($program)) }}</td>
            <td class="k">Semester</td><td class="v">{{ $val($sa->semester_no ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">Decision</td><td class="v">{{ $isHold ? 'Hold' : 'Reject' }}</td>
            @if($isHold)
                <td class="k">Hold Type</td><td class="v">{{ $val($decision->hold_type ?? null) }}</td>
            @else
                <td class="k">Status</td><td class="v">Permanent — no release path</td>
            @endif
        </tr>
        <tr>
            <td class="k">Submitted By</td><td class="v">{{ $val($decision->submitted_by ?? null) }}</td>
            <td class="k">Date Of Submission</td><td class="v">{{ $fmt($decision->submitted_at ?? null) ?? '—' }}</td>
        </tr>
        <tr>
            <td class="k">Decided By</td><td class="v">{{ $val($decidedByName) }}</td>
            <td class="k">Decided At</td><td class="v">{{ $fmtDt($decision->decided_at ?? null) ?? '—' }}</td>
        </tr>
    </table>

    <div class="sec-bar">Reason</div>
    <div class="sec-body">{{ $val($decision->reason ?? null) }}</div>

    @if(!empty($objections))
        <div class="sec-bar">Submitted Objections</div>
        <div class="sec-body">
            <ol>
                @foreach($objections as $o)
                    <li>{{ $o }}</li>
                @endforeach
            </ol>
        </div>
    @endif

    @if(($decision->status ?? null) === 'released')
        <div class="sec-bar">Release Details</div>
        <table class="kv">
            <tr>
                <td class="k">Release Due To</td><td class="v">{{ $val($decision->release_due_to ?? null) }}</td>
                <td class="k">Release Ordered By</td><td class="v">{{ $val($releasedByName) }}</td>
            </tr>
            <tr>
                <td class="k">Release Date</td><td class="v">{{ $fmtDt($decision->released_at ?? null) ?? '—' }}</td>
                <td class="k">Remarks</td><td class="v">{{ $val($decision->release_remarks ?? null) }}</td>
            </tr>
        </table>
    @endif

    <table class="sign">
        <tr>
            <td>Applicant Signature</td>
            <td>Office Verifier Signature</td>
            <td>Principal / Authorized Signatory</td>
        </tr>
    </table>

</body>

</html>
