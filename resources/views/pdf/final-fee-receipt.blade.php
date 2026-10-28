{{--
    Final Fee Receipt (Provisional) — student copy + office copy on one A4,
    laid out after the college's sample "Final Fee Receipt.pdf".
    No logo image asset exists in this repo, so the banner is CSS/text.
--}}
@php
    $fmt = fn($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d-M-Y') : '';
    $money = fn($v) => number_format((float) ($v ?? 0), 2);
    $v = fn($x) => ($x === null || $x === '') ? '' : $x;
    $collegeName = $org->name ?? 'Swami Devanand P.G. College';
    $session = $receipt->academic_year;
    $half = (int) ceil(count($feeRows) / 2);
    $left = array_slice($feeRows, 0, $half);
    $right = array_slice($feeRows, $half);
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 8mm 10mm; }
    * { font-family: DejaVu Serif, serif; }
    body { font-size: 9.5px; color: #111; }
    .banner { border: 1px solid #7fb2d6; background: #d9ecf8; text-align: center; padding: 5px 0 4px; }
    .banner .n { font-size: 21px; font-weight: bold; color: #1e3a8a; letter-spacing: 1px; }
    .banner .a { font-size: 9px; color: #1e3a8a; font-weight: bold; }
    .banner .g { font-size: 9px; font-weight: bold; color: #7c2d12; }
    .title { width: 100%; border-collapse: collapse; }
    .title td { padding: 3px 0; font-weight: bold; }
    .title .t { font-size: 13px; text-align: center; }
    .title .c { font-size: 9px; text-align: right; width: 90px; }
    table.box { width: 100%; border-collapse: collapse; border: 1.5px solid #111; }
    table.box td { vertical-align: top; padding: 3px 6px; font-size: 9.5px; }
    table.inner { width: 100%; border-collapse: collapse; }
    table.inner td { padding: 1.5px 0; font-size: 9.5px; vertical-align: top; }
    td.k { font-weight: bold; white-space: nowrap; width: 34%; }
    .bar { background: #c8c8c8; font-weight: bold; font-size: 10px; padding: 4px 6px; margin-top: 6px; }
    table.fees { width: 100%; border-collapse: collapse; border: 1.5px solid #111; }
    table.fees td { padding: 3px 5px; font-size: 9.5px; border-bottom: 1px solid #ddd; }
    table.fees td.n { width: 14px; font-weight: bold; text-align: right; }
    table.fees td.h { font-weight: bold; }
    table.fees td.a { text-align: right; width: 62px; }
    .gap { width: 10px; border-bottom: none !important; }
    .tot { width: 100%; border-collapse: collapse; border: 1.5px solid #111; border-top: none; }
    .tot td { padding: 5px 6px; font-weight: bold; font-size: 10.5px; }
    .foot { width: 100%; border-collapse: collapse; margin-top: 6px; }
    .foot td { font-size: 9.5px; padding: 2px 6px; }
    .nice { background: #fcd9a8; text-align: center; font-weight: bold; font-size: 12px; padding: 4px; margin-top: 6px; }
    .cut { border-top: 2px dashed #555; margin: 8px 0; }
    .officetitle { text-align: center; font-size: 17px; font-weight: bold; margin-top: 2px; }
    .officesub { text-align: center; font-size: 11px; font-weight: bold; margin: 2px 0 6px; }
</style>
</head>
<body>

{{-- ───────────── STUDENT COPY ───────────── --}}
<div class="banner">
    <div class="n">{{ strtoupper($collegeName) }}</div>
    <div class="a">MATH-LAR, DEORIA.(Uttar Pradesh) 274502 &nbsp;·&nbsp; Affiliated By- Deen Dayal Upadhyay Gorakhpur University, Gorakhpur</div>
    <div class="g">Grant-in-aid by Uttar Pradesh Government</div>
</div>
<table class="title"><tr>
    <td class="t">Final Fee Receipt (Provisional) For Session- {{ $session }}</td>
    <td class="c">(Student Copy)</td>
</tr></table>

<table class="box"><tr>
    <td style="width:50%; border-right:1.5px solid #111;">
        <table class="inner">
            <tr><td class="k" style="font-size:11px;">Name &nbsp;:-</td><td style="font-size:11px;font-weight:bold;">{{ strtoupper((string) $name) }}</td></tr>
            <tr><td class="k">Father &nbsp;:-</td><td>{{ strtoupper((string) $father) }}</td></tr>
            <tr><td class="k">Mother &nbsp;:-</td><td>{{ strtoupper((string) $mother) }}</td></tr>
            <tr><td class="k">DOB &nbsp;:-</td><td>{{ $fmt($dob) }}</td></tr>
            <tr><td class="k">Category :-</td><td>{{ strtoupper((string) $category) }} &nbsp;&nbsp;&nbsp;<b>Gender :-</b> {{ ucfirst((string) $gender) }}</td></tr>
            <tr><td class="k">State &nbsp;:-</td><td>{{ $state }}</td></tr>
            <tr><td class="k">Mobile No. :-</td><td>{{ $mobile }}</td></tr>
        </table>
    </td>
    <td style="width:50%;">
        <table class="inner">
            <tr><td class="k">Course Name :-</td><td><b>{{ $courseName }}</b></td></tr>
            <tr><td class="k">Class A/C No. :-</td><td>{{ $classAc }} &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Semester:-</b> {{ $semester }}</td></tr>
            <tr><td class="k">Selected Subjects :-</td><td>{{ $subjects }}</td></tr>
            <tr><td class="k">Minor Subject :-</td><td>{{ $minor }}</td></tr>
            <tr><td class="k">ITRP Sub. :-</td><td></td></tr>
            <tr><td class="k">TC Status :-</td><td>{{ $tcStatus }}</td></tr>
            <tr><td class="k">Admission Mode :-</td><td>{{ $admissionMode }}</td></tr>
        </table>
    </td>
</tr></table>

<div class="bar">Admission Data Details</div>
<table class="box"><tr>
    <td style="width:36%;">
        <table class="inner">
            <tr><td class="k">Registration No :-</td><td>{{ $registration->registration_no ?? '' }}</td></tr>
            <tr><td class="k">Application No :-</td><td>{{ $sa->application_no ?? '' }}</td></tr>
            <tr><td class="k">Roll No. :-</td><td>{{ $student->university_roll_no ?? '' }}</td></tr>
        </table>
    </td>
    <td style="width:28%;">
        <table class="inner">
            <tr><td class="k">A.B.C. ID. :-</td><td>{{ $abc }}</td></tr>
            <tr><td class="k">Enrollment No. :-</td><td>{{ $student->enrollment_no ?? '' }}</td></tr>
            <tr><td class="k">DDURN :-</td><td>{{ $ddurn }}</td></tr>
        </table>
    </td>
    <td style="width:36%;">
        <table class="inner">
            <tr><td class="k">Student ID :-</td><td><b>{{ $studentUid }}</b></td></tr>
            <tr><td class="k">Fee Receipt No :-</td><td><b>{{ $receipt->receipt_no }}</b></td></tr>
            <tr><td class="k">Date Of Receipt :-</td><td>{{ $fmt($receipt->receipt_date) }}</td></tr>
        </table>
    </td>
</tr></table>

<div class="bar">Fee Details</div>
<table class="fees">
    @for ($i = 0; $i < $half; $i++)
        <tr>
            @if (isset($left[$i]))
                <td class="n">{{ $i + 1 }}</td><td class="h">{{ $left[$i]['name'] }}</td><td class="a">{{ $money($left[$i]['amount']) }}</td>
            @else
                <td></td><td></td><td></td>
            @endif
            <td class="gap"></td>
            @if (isset($right[$i]))
                <td class="n">{{ $half + $i + 1 }}</td><td class="h">{{ $right[$i]['name'] }}</td><td class="a">{{ $money($right[$i]['amount']) }}</td>
            @else
                <td></td><td></td><td></td>
            @endif
        </tr>
    @endfor
</table>
<table class="tot"><tr>
    <td>Rupees :-{{ $inWords }}.</td>
    <td style="text-align:right;">Total Amount Rs. &nbsp;&nbsp;&nbsp; {{ $money($total) }}</td>
</tr></table>
<table class="foot"><tr>
    <td><b>Approved By:-</b> Admission Committee</td>
    <td style="text-align:right;"><b>Receipt Print By:-</b> {{ $printedBy }}</td>
</tr></table>
<div class="nice">Have A Nice Day</div>

<div class="cut"></div>

{{-- ───────────── OFFICE COPY ───────────── --}}
<table class="title"><tr><td></td><td class="c">(Office Copy)</td></tr></table>
<div class="officetitle">{{ $collegeName }} Math-Lar, Deoria</div>
<div class="officesub">Final Fee Receipt (Provisional) For Session- {{ $session }}</div>

<table class="box"><tr>
    <td style="width:50%; border-right:1.5px solid #111;">
        <table class="inner">
            <tr><td class="k">Student ID :-</td><td><b>{{ $studentUid }}</b></td></tr>
            <tr><td class="k">Name :-</td><td>{{ strtoupper((string) $name) }}</td></tr>
            <tr><td class="k">Father :-</td><td>{{ strtoupper((string) $father) }}</td></tr>
            <tr><td class="k">Mother :-</td><td>{{ strtoupper((string) $mother) }}</td></tr>
            <tr><td class="k">DOB :-</td><td>{{ $fmt($dob) }} &nbsp;&nbsp;&nbsp;<b>Gender :-</b> {{ ucfirst((string) $gender) }}</td></tr>
            <tr><td class="k">Category :-</td><td>{{ strtoupper((string) $category) }}</td></tr>
            <tr><td class="k">Domestic State :-</td><td>{{ $state }}</td></tr>
            <tr><td class="k">Mobile No. :-</td><td>{{ $mobile }}</td></tr>
        </table>
    </td>
    <td style="width:50%;">
        <table class="inner">
            <tr><td class="k">Fee Receipt No :-</td><td><b>{{ $receipt->receipt_no }}</b></td></tr>
            <tr><td class="k">Course Name :-</td><td>{{ $courseName }} &nbsp;&nbsp;&nbsp;<b>Sem :</b> {{ $semester }}</td></tr>
            <tr><td class="k">Subjects :-</td><td>{{ $subjects }}</td></tr>
            <tr><td class="k">A/C :-</td><td>{{ $classAc }}</td></tr>
            <tr><td class="k">TC Status :-</td><td>{{ $tcStatus }}</td></tr>
            <tr><td class="k">Admission Mode :-</td><td>{{ $admissionMode }}</td></tr>
            <tr><td class="k">Payment Ref. No :-</td><td>{{ $receipt->transaction_id }}</td></tr>
            <tr><td class="k">Date Of Issue :-</td><td>{{ $fmt($receipt->receipt_date) }}</td></tr>
        </table>
    </td>
</tr></table>
<table class="tot"><tr>
    <td>Rupees :-{{ $inWords }}.</td>
    <td style="text-align:right;">Total Amount Rs.:- &nbsp;&nbsp; {{ $money($total) }}</td>
</tr></table>
<table class="foot"><tr>
    <td><b>Approved By:-</b> Admission Committee</td>
    <td style="text-align:right;"><b>Receipt Print By:-</b> {{ $printedBy }}</td>
</tr></table>

</body>
</html>
