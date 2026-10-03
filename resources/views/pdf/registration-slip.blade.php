{{--
    Student Registration Slip — rebuilt field-for-field against the exact
    reference the college supplied (Registration Slip.pdf), replacing the
    old, much more elaborate multi-section design this file used to have.

    Two fields in the reference have no real backing data in this schema and
    are intentionally NOT faked here:
      - "Sem-N" after the class name — direct_registrations has no
        semester_no column (a fresh registration doesn't have one yet).
      - "Research Subject" / "Drop Subject" — no such fields exist anywhere
        in Part6Subjects' data; the reference's values for them were blank
        in the sample too.
    "Registration Mode :- Regular" is not per-record data either, but IS a
    genuine constant of this flow — resolveRegistrationFee() itself only
    ever looks up registration_mode = 'Regular' for direct registrations.

    No college logo image asset exists in this repo, so the header banner
    below is recreated in CSS/text rather than an embedded image.
--}}
@php
    $fmt = fn($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d-M-Y h:i A') : '—';
    $val = fn($v) => ($v === null || $v === '') ? '—' : $v;
    $collegeName = $org->name ?? 'Swami Devanand P.G. College';
    $collegeAddr = trim(implode(', ', array_filter([$org->address ?? 'Math-Lar', $org->district ?? 'Deoria'])))
        . ' (' . ($org->state ?? 'Uttar Pradesh') . ') ' . ($org->pin_code ?? '');
    $affiliation = $org->university_name ?? 'Deen Dayal Upadhyay Gorakhpur University, Gorakhpur';
    $course = \App\Models\Program::label($program) ?? $reg->reg_type;
    $subjectNames = collect($subjects ?? [])->filter()->values();
@endphp
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 10mm 12mm; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10.5px; color: #111827; }

        .banner {
            border: 2px solid #0f5132;
            background: linear-gradient(#eafaf1, #d7f2e3);
            padding: 6px 10px;
        }
        .banner-name { color: #b91c1c; font-size: 18px; font-weight: bold; letter-spacing: 0.5px; }
        .banner-addr { color: #1f2937; font-size: 10px; font-weight: bold; }
        .banner-affil { color: #374151; font-size: 8px; }

        .title-row { width: 100%; margin-top: 8px; }
        .title-row .t { font-size: 14px; font-weight: bold; text-align: center; }
        .title-row .copy { font-size: 9px; font-weight: bold; text-align: right; }

        table.kv { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.kv td { border: 1px solid #111827; padding: 4px 8px; vertical-align: top; font-size: 10px; }
        table.kv td.k { font-weight: bold; width: 22%; }
        table.kv td.v { width: 28%; }

        .sec-bar {
            background: #d1d5db; font-weight: bold; font-size: 10.5px;
            padding: 4px 8px; border: 1px solid #111827; border-top: none;
        }

        .foot-note { margin-top: 14px; font-size: 9.5px; text-align: justify; }
    </style>
</head>

<body>

    <div class="banner">
        <div class="banner-name">{{ strtoupper($collegeName) }}</div>
        <div class="banner-addr">{{ $collegeAddr }}</div>
        <div class="banner-affil">Affiliated By&mdash; {{ $affiliation }}</div>
    </div>

    <table class="title-row">
        <tr>
            <td class="t">Student Registration Slip</td>
            <td class="copy" style="width: 90px;">Student Copy</td>
        </tr>
    </table>

    <table class="kv">
        <tr>
            <td class="k">Registration No:</td>
            <td class="v">{{ $val($reg->registration_no) }}</td>
            <td class="k">Class :-</td>
            <td class="v">{{ $course }}</td>
        </tr>
        <tr>
            <td class="k">Apar ID</td>
            <td class="v">{{ $val($reg->abc_id) }}</td>
            <td class="k">DDURN :</td>
            <td class="v">{{ $val($reg->ddurn_no) }}</td>
        </tr>
    </table>

    <table class="kv">
        <tr>
            <td class="k">Student Name :-</td>
            <td class="v" colspan="3">{{ $val($reg->name) }}</td>
        </tr>
        <tr>
            <td class="k">Father Name :-</td>
            <td class="v" colspan="3">{{ $val($reg->father_name) }}</td>
        </tr>
        <tr>
            <td class="k">Subjects:-</td>
            <td class="v" colspan="3">
                @forelse($subjectNames as $name)
                    {{ $name }}{{ !$loop->last ? '&nbsp;&nbsp;&nbsp;' : '' }}
                @empty
                    —
                @endforelse
            </td>
        </tr>
        <tr>
            <td class="k">Registration Mode :-</td>
            <td class="v" colspan="3">Regular</td>
        </tr>
        <tr>
            <td class="k">Gender :</td>
            <td class="v">{{ $val($reg->gender) }}</td>
            <td class="k">Category :-</td>
            <td class="v">{{ $val($reg->category) }}</td>
        </tr>
    </table>

    <div class="sec-bar">Registration Fee Paymet Detail</div>
    <table class="kv">
        <tr>
            <td class="k">Fee Amount :-</td>
            <td class="v">{{ number_format((float) ($reg->fee_amount ?? 0), 2) }}</td>
            <td class="k">Payment Date :-</td>
            <td class="v">{{ $fmt($reg->paid_at) }}</td>
        </tr>
        <tr>
            <td class="k">TID :-</td>
            <td class="v">{{ $val($reg->razorpay_payment_id) }}</td>
            <td class="k">UTR No. :-</td>
            <td class="v">{{ $val($reg->razorpay_order_id) }}</td>
        </tr>
    </table>

    <div class="foot-note">
        यह पंजीकरण स्लिप आपके प्रवेश को सुनिश्चित नहीं करता है। इसके बाद आप प्रवेश फॉर्म को पूर्ण रूप से भर कर उसके साथ
        आवश्यक संलग्रक लगा कर महाविद्यालय से सत्यापन कराए। सत्यापन के उपरान्त निर्धारित शुल्क जमा करें और आवेदन पत्र को
        पुनः महाविद्यालय में जमा करें।
    </div>

</body>

</html>
