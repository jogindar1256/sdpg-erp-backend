@php
    $fmt   = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d-M-Y') : '—';
    $val   = fn ($v) => ($v === null || $v === '' ) ? '—' : $v;
    $yesno = fn ($v) => $v ? 'Yes' : 'No';

    $reg  = $registration;
    $p    = fn (int $n) => $parts[$n] ?? [];
    $p1 = $p(1); $p2 = $p(2); $p3 = $p(3); $p4 = $p(4); $p5 = $p(5); $p6 = $p(6); $p7 = $p(7); $p8 = $p(8);

    $collegeName = $org->name ?? 'Swami Devanand Post Graduate College';
    $collegeAddr = $org->address ?? 'Math Lar, Deoria (Uttar Pradesh)';
    $course      = $program->full_name ?? $program->short_name ?? '—';

    $studentName = $p1['name_english'] ?? $reg->name ?? $student->name ?? '—';
    $fatherName  = $p1['father_name_english'] ?? $reg->father_name ?? $student->father_name ?? '—';
    $motherName  = $p1['mother_name_english'] ?? $reg->mother_name ?? '—';

    // Passed in as base64 data: URIs from the controller (dompdf has
    // enable_remote=false, so a plain https:// <img src> would render blank).
    $photoUrl     = $photoDataUri ?? null;
    $signatureUrl = $signatureDataUri ?? null;

    // Address (permanent) for the शपथ-पत्र line — same "same_as_permanent"
    // logic Part8Declaration.tsx uses.
    $addrPrefix = ($p2['same_as_permanent'] ?? false) ? 'perm' : 'pres';
    $addressLine = collect([
        $p2["{$addrPrefix}_house_no"] ?? null,
        $p2["{$addrPrefix}_mohalla"] ?? null,
        $p2["{$addrPrefix}_post_office"] ?? null,
        $p2["{$addrPrefix}_district"] ?? null,
        $p2["{$addrPrefix}_state"] ?? null,
    ])->filter()->implode(', ');

    // Same 10-point declaration text as Part8Declaration.tsx's POINTS array —
    // kept in exact sync so the PDF matches what the student actually
    // accepted on screen before Final Submit.
    $oathPoints = [
        'मैं अपने अध्ययन कक्षा के चयनित समस्त विषयों के सैद्धान्तिक एवं प्रायोगात्मक कक्षाओं में निर्धारित उपस्थिति का मानक प्रतिशत पूर्ण रखूँगा/रखूँगी।',
        'इस महाविद्यालय में अध्ययन की अवधि में मैं किसी भी अन्य पाठ्यक्रम के लिए इस महाविद्यालय में अथवा किसी अन्य संस्था में संस्थागत छात्र के रूप में अध्ययन नहीं करूँगा/करूँगी।',
        'यदि अध्ययन की अवधि के किसी कालखण्ड के लिए मैं पुलिस अभिरक्षा/जेल में रहता हूँ तो इसकी सूचना समय से महाविद्यालय के प्रशासनिक अधिकारी को दे दूँगा/दूँगी।',
        'अपने अध्ययन की अवधि में मैं कभी भी महाविद्यालय के किसी भी सम्पत्ति को या उसके किसी भी भाग को क्षतिग्रस्त नहीं करूँगा/करूँगी। यदि मेरे द्वारा ऐसा कोई कृत किया जाता है तो उसकी भरपाई मेरे द्वारा स्वेच्छा से किया जायेगा। अन्यथा की स्थिति में महाविद्यालय को यह अधिकार होगा कि वह नुकसान की भरपाई उत्तर प्रदेश राजस्व अधिनियम के अनुसार मुझसे वसूली कर लेवे।',
        'मैं महाविद्यालय के किसी भी प्रशासनिक अधिकारी, प्रोफेसर, कर्मचारी अथवा छात्र/छात्रा से अनुशासनहीन, अभद्र, अश्लील या हिंसक व्यवहार नहीं करूँगा। यदि ऐसा करते हुए मैं कभी पाया जाता हूँ तो महाविद्यालय प्रशासन को मेरे खिलाफ विधि सम्मत कार्यवाही करने का स्वतंत्र अधिकार होगा। महाविद्यालय प्रशासन बिना किसी पूर्व सूचना के मेरा प्रवेश निरस्त करते हुए मुझे महाविद्यालय से सदैव के लिए निष्कासित कर सकता।',
        'मैं महाविद्यालय में अथवा महाविद्यालय के किसी भी परिसर में महाविद्यालय अथवा उसके किसी भी अधिकारी/कर्मचारी या महाविद्यालय के किसी भी नियम अथवा नीति के खिलाफ अथवा किसी भी अन्य प्रकार के वाद विवाद की स्थिति में न तो धरना प्रदर्शन, हड़ताल, या उपद्रव करूँगा/करूँगी और न ही ऐसी किसी क्रिया का हिस्सा बनूँगा/बनूँगी।',
        'मैं महाविद्यालय के समस्त शुल्क को निर्धारित समय के अन्तर्गत जमा करूँगा/करूँगी। यदि महाविद्यालय किसी मद में शुल्क की वृद्धि करता है या किसी नये मद के लिए शुल्क का निर्धारण करता है तो मैं उसका विरोध कभी भी नहीं करूँगा/करूँगी, तथा शुल्क का भुगतान समय से करूँगा/करूँगी।',
        'यदि महाविद्यालय के किसी अधिकारी या कर्मचारी द्वारा अनभिज्ञता अथवा भूलवश अथवा तकनीकी गड़बड़ी के कारण मुझे प्रवेश दे दिया जाता है किन्तु मैं इस प्रवेश के लिए अर्हत/योग्य नहीं था तो मेरी अयोग्यता के बाद में प्रकाश में आने पर मेरा प्रवेश तत्काल प्रभाव से निरस्त कर दिया जाय। मेरे शुल्क की वापसी मुझे विधि सम्मत ढंग से स्वीकार होगी तथा मैं इसके किसी भी अंश के लिए क्षतिपूर्ति की मांग नहीं करूँगा/करूँगी।',
        'उपरोक्त किसी भी वचन को यदि मेरे द्वारा जानबूझ कर या अनजाने में तोड़ा जाता है तो महाविद्यालय प्रशासन को यह अधिकार होगा कि वह मेरे खिलाफ उचित विधिक कार्यवाही करे जिसमें मेरा प्रवेश निरस्त करना भी शामिल है। मेरा प्रवेश निरस्त होने की स्थिति में मैं अपने द्वारा जमा किये गये शुल्क की मांग या अन्य किसी भी प्रकार की क्षतिपूर्ति की मांग नहीं करूँगा/करूँगी।',
        'आवेदन पत्र में अंकित समस्त सूचनायें मेरे निजी जानकारी में मेरी उपस्थिति में अंकित की गयी हैं। समस्त संलग्न प्रपत्र सत्य एवं सही हैं, इनमें किसी को भी व्यक्तिगत लाभ के लिए छल-कपट पूर्वक तैयार नहीं किया गया है। यह वचन पत्र जब तक मैं इस महाविद्यालय में किसी भी प्रकार का (संस्थागत, व्यक्तिगत, बैक, एकल विषय आदि) छात्र/छात्रा रहूँगा/रहूँगी तब तक के लिए लागू रहेगा।',
    ];

    $docLabels = [
        'photo' => 'Applicant Photo', 'signature' => 'Applicant Signature', 'aadhar' => 'Aadhar Card',
        'abc_id' => 'ABC ID Paper', 'ddurn' => 'DDURN Paper', 'family_id' => 'Family ID Paper',
        'id_proof' => 'ID Proof', 'tc' => 'Transfer Certificate (TC)', 'migration' => 'Migration Certificate',
        'caste_cert' => 'Caste Certificate', 'domicile' => 'Domicile Certificate', 'income_cert' => 'Income Certificate',
    ];
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 12mm 14mm; }
    * { font-family: 'Noto Sans Devanagari', 'DejaVu Sans', sans-serif; }
    body { font-size: 10.5px; color: #1a1a1a; }
    .page { page-break-after: always; }
    .page:last-child { page-break-after: auto; }

    .letterhead { width: 100%; border: 2px solid #b45309; border-collapse: collapse; margin-bottom: 8px; }
    .letterhead td { padding: 6px 10px; vertical-align: middle; }
    .letterhead .name { font-size: 15px; font-weight: bold; color: #b45309; }
    .letterhead .addr { font-size: 10px; color: #555; }
    .letterhead .appno { text-align: right; border-left: 2px solid #b45309; }
    .letterhead .appno .lbl { font-size: 9px; color: #666; }
    .letterhead .appno .num { font-size: 16px; font-weight: bold; }

    .sec-title { background: #b45309; color: #fff; font-weight: bold; padding: 5px 10px; font-size: 11.5px; margin: 10px 0 0 0; }
    .sec-title.blue { background: #154FA3; }

    table.kv { width: 100%; border-collapse: collapse; border: 1px solid #333; margin-bottom: 2px; }
    table.kv td { padding: 4px 8px; border: 1px solid #333; vertical-align: top; }
    table.kv td.k { background: #f3f4f6; font-weight: bold; width: 20%; }

    table.grid { width: 100%; border-collapse: collapse; border: 1px solid #333; font-size: 9.5px; }
    table.grid th { background: #e5e7eb; border: 1px solid #333; padding: 3px 5px; text-align: left; }
    table.grid td { border: 1px solid #333; padding: 3px 5px; }

    .two-col { width: 100%; }
    .two-col td { width: 50%; vertical-align: top; padding: 0 4px; }

    .photo-box { width: 90px; height: 105px; border: 1px solid #333; text-align: center; vertical-align: middle; font-size: 9px; color: #999; }
    .photo-box img { width: 90px; height: 105px; object-fit: cover; }
    .sig-box { border: 1px solid #333; padding: 4px; height: 40px; text-align: center; vertical-align: middle; }
    .sig-box img { max-height: 34px; }

    .oath-title { text-align: center; font-size: 20px; font-weight: bold; margin: 4px 0 12px 0; }
    .oath-points li { margin-bottom: 6px; font-size: 10.5px; line-height: 1.5; }
    .foot { margin-top: 10px; font-size: 8.5px; color: #888; text-align: center; border-top: 1px solid #ddd; padding-top: 4px; }
    .sign-row { margin-top: 26px; width: 100%; }
    .sign-row td { width: 50%; text-align: center; font-size: 10px; padding-top: 26px; border-top: 1px solid #333; }
</style>
</head>
<body>

{{-- ══════════════════════════ PAGE 1 — Registration Detail ══════════════════════════ --}}
<div class="page">
    <table class="letterhead">
        <tr>
            <td>
                <div class="name">{{ $collegeName }}</div>
                <div class="addr">{{ $collegeAddr }}</div>
            </td>
            <td class="appno" style="width:150px;">
                <div class="lbl">Application No.</div>
                <div class="num">{{ $sa->application_no }}</div>
            </td>
        </tr>
    </table>

    <div class="sec-title">Registration Detail</div>
    <table class="kv">
        <tr>
            <td class="k">Registration Session</td><td>{{ $val($reg->session_year ?? $sa->academic_year) }}</td>
            <td class="k">Registration No.</td><td>{{ $val($reg->registration_no ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">Course</td><td>{{ $course }}</td>
            <td class="k">Semester</td><td>{{ $val($sa->semester_no) }}</td>
        </tr>
        <tr>
            <td class="k">Registration Date</td><td>{{ $fmt($reg->reg_date ?? null) }}</td>
            <td class="k">Type Of Course</td><td>{{ ucwords(str_replace('_', ' ', $sa->application_type)) }}</td>
        </tr>
        <tr>
            <td class="k">Name</td><td>{{ $studentName }}</td>
            <td class="k">Father</td><td>{{ $fatherName }}</td>
        </tr>
    </table>

    <div class="sec-title" style="background:#15803d;">Registration Fee Payment Detail</div>
    <table class="kv">
        <tr>
            <td class="k">Payment Mode</td><td>{{ $val($reg->payment_status ?? null) === 'paid' ? 'Online' : ($val($reg->payment_status ?? null)) }}</td>
            <td class="k">Payment Date</td><td>{{ $fmt($reg->paid_at ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">Paid Amount Rs.</td><td>{{ $reg->fee_amount ?? '0.00' }}</td>
            <td class="k">Payment Ref. No.</td><td>{{ $val($reg->razorpay_payment_id ?? null) }}</td>
        </tr>
    </table>

    <table class="two-col" style="margin-top:8px;">
        <tr>
            <td style="width:100px;">
                <div class="photo-box">
                    @if($photoUrl)
                        <img src="{{ $photoUrl }}" alt="Photo">
                    @else
                        Photo
                    @endif
                </div>
            </td>
            <td>
                <table class="kv" style="margin:0;">
                    <tr><td class="k">ABC/APAAR ID No.</td><td>{{ $val($p1['abc_id'] ?? $reg->abc_id ?? null) }}</td></tr>
                    <tr><td class="k">DDURN</td><td>{{ $val($p1['ddurn_id'] ?? $reg->ddurn_no ?? null) }}</td></tr>
                    <tr><td class="k">Family ID</td><td>{{ $val($p1['family_id'] ?? $reg->family_id ?? null) }}</td></tr>
                </table>
            </td>
            <td style="width:130px;">
                <div class="sig-box">
                    @if($signatureUrl)
                        <img src="{{ $signatureUrl }}" alt="Signature">
                    @else
                        Signature
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="sec-title">I have checked all conditions and attached documents which are as below</div>
    <table class="grid">
        <thead>
            <tr><th style="width:26px;">Sr.No</th><th>Enclosed Documents</th><th style="width:80px;">Enclosure No.</th><th style="width:70px;">Hard Copy Status</th></tr>
        </thead>
        <tbody>
            @forelse($documents as $i => $doc)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $docLabels[$doc->document_type] ?? ucwords(str_replace('_', ' ', $doc->document_type)) }}</td>
                    <td>{{ $doc->id }}</td>
                    <td>&#9633;</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center;color:#999;">No documents uploaded.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="sec-title" style="background:#6b7280;">Remark By Committee If Any</div>
    <table class="kv"><tr><td style="height:30px;">&nbsp;</td></tr></table>

    <p style="margin-top:8px;">After verification of the documents which is enclosed with this application, candidate registration
        number <strong>{{ $val($reg->registration_no ?? null) }}</strong> has been verified and allowing for the next process of admission.</p>

    <table class="two-col" style="margin-top:20px;">
        <tr>
            <td>Approval Date:- ____/____/________</td>
            <td style="text-align:center;">Round Seal</td>
            <td style="text-align:right;">Approved By</td>
        </tr>
    </table>

    <div class="foot">{{ now()->format('n/j/Y g:i:sA') }} &nbsp; Application No.:- {{ $sa->application_no }} &nbsp; Page 1 of 5</div>
</div>

{{-- ══════════════════════════ PAGE 2 — Personal Information ══════════════════════════ --}}
<div class="page">
    <h2 style="text-align:center;">Application Form</h2>

    <div class="sec-title">Personal Information Of Applicant</div>
    <table class="kv">
        <tr>
            <td class="k">Student Name (English)</td><td>{{ $studentName }}</td>
            <td class="k">Student Name (Hindi)</td><td>{{ $val($p1['name_hindi'] ?? $reg->name_hindi ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">Father's Name (English)</td><td>{{ $fatherName }}</td>
            <td class="k">Father's Name (Hindi)</td><td>{{ $val($p1['father_name_hindi'] ?? $reg->father_name_hindi ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">Mother's Name (English)</td><td>{{ $motherName }}</td>
            <td class="k">Mother's Name (Hindi)</td><td>{{ $val($p1['mother_name_hindi'] ?? $reg->mother_name_hindi ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">Gender</td><td>{{ $val($p1['gender'] ?? $reg->gender ?? null) }}</td>
            <td class="k">Marital Status</td><td>{{ ucfirst($val($p1['marital_status'] ?? null)) }}</td>
        </tr>
        <tr>
            <td class="k">Date Of Birth</td><td>{{ $fmt($p1['date_of_birth'] ?? $reg->dob ?? null) }}</td>
            <td class="k">Blood Group</td><td>{{ $val($p1['blood_group'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">Religion</td><td>{{ $val($p1['religion'] ?? $reg->religion ?? null) }}</td>
            <td class="k">Category</td><td>{{ strtoupper($val($p1['social_category'] ?? $reg->category ?? null)) }}</td>
        </tr>
        <tr>
            <td class="k">Nationality</td><td>{{ $val($p1['nationality'] ?? $reg->nationality ?? 'Indian') }}</td>
            <td class="k">Minority Status</td><td>{{ ucfirst($val($p1['is_minority'] ?? null)) }}</td>
        </tr>
        <tr>
            <td class="k">ID Proof Type</td><td>{{ $val($p1['id_proof_type'] ?? $reg->id_proof_type ?? null) }}</td>
            <td class="k">ID Proof No.</td><td>{{ $val($p1['id_proof_number'] ?? $reg->id_proof_no ?? $reg->aadhar_no ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">Caste Cert. No.</td><td>{{ $val($p1['caste_cert_no'] ?? $reg->caste_cert_no ?? null) }}</td>
            <td class="k">Caste Cert. Date</td><td>{{ $fmt($p1['caste_cert_date'] ?? $reg->caste_cert_date ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">Identity Mark</td><td>{{ $val($p1['identity_mark'] ?? null) }}</td>
            <td class="k">Domestic State</td><td>{{ $val($p1['domestic_state'] ?? $reg->domestic_state ?? null) }}</td>
        </tr>
    </table>

    <div class="sec-title blue">Electronic Communication</div>
    <table class="kv">
        <tr>
            <td class="k">Student Mobile No.</td><td>{{ $val($p2['mobile'] ?? $reg->mobile ?? $student->mobile ?? null) }}</td>
            <td class="k">Student E-Mail</td><td>{{ $val($p2['email'] ?? $reg->email ?? $student->email ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">Alternate Mobile No.</td><td>{{ $val($p2['alternate_mobile'] ?? null) }}</td>
            <td class="k">Father's/Guardian's Mobile</td><td>{{ $val($p2['father_mobile'] ?? null) }}</td>
        </tr>
    </table>

    <table class="two-col">
        <tr>
            <td>
                <div class="sec-title blue">Permanent Address</div>
                <table class="kv">
                    <tr><td class="k">House/Mohalla</td><td>{{ $val($p2['perm_house_no'] ?? null) }} {{ $p2['perm_mohalla'] ?? '' }}</td></tr>
                    <tr><td class="k">Post Office</td><td>{{ $val($p2['perm_post_office'] ?? null) }}</td></tr>
                    <tr><td class="k">District</td><td>{{ $val($p2['perm_district'] ?? null) }}</td></tr>
                    <tr><td class="k">State</td><td>{{ $val($p2['perm_state'] ?? null) }}</td></tr>
                    <tr><td class="k">Pin Code</td><td>{{ $val($p2['perm_pin_code'] ?? null) }}</td></tr>
                    <tr><td class="k">Police Station</td><td>{{ $val($p2['perm_police_station'] ?? null) }}</td></tr>
                </table>
            </td>
            <td>
                <div class="sec-title blue">Present Address</div>
                @if($p2['same_as_permanent'] ?? false)
                    <table class="kv"><tr><td>Same as Permanent Address</td></tr></table>
                @else
                    <table class="kv">
                        <tr><td class="k">House/Mohalla</td><td>{{ $val($p2['pres_house_no'] ?? null) }} {{ $p2['pres_mohalla'] ?? '' }}</td></tr>
                        <tr><td class="k">Post Office</td><td>{{ $val($p2['pres_post_office'] ?? null) }}</td></tr>
                        <tr><td class="k">District</td><td>{{ $val($p2['pres_district'] ?? null) }}</td></tr>
                        <tr><td class="k">State</td><td>{{ $val($p2['pres_state'] ?? null) }}</td></tr>
                        <tr><td class="k">Pin Code</td><td>{{ $val($p2['pres_pin_code'] ?? null) }}</td></tr>
                        <tr><td class="k">Police Station</td><td>{{ $val($p2['pres_police_station'] ?? null) }}</td></tr>
                    </table>
                @endif
            </td>
        </tr>
    </table>

    <div class="sec-title blue">Educational Statistics</div>
    <table class="grid">
        <thead>
            <tr>
                <th>Course</th><th>Board/University</th><th>Institute</th><th>Year</th><th>Roll No.</th>
                <th>Cert./Mark Sheet Sr.</th><th>Full Marks</th><th>Obtain</th><th>Obtain %</th><th>Subject/Group</th>
            </tr>
        </thead>
        <tbody>
            @forelse(($p3['education_records'] ?? []) as $r)
                <tr>
                    <td>{{ $val($r['course_name'] ?? null) }}</td>
                    <td>{{ $val($r['board_university'] ?? null) }}</td>
                    <td>{{ $val($r['institute_name'] ?? null) }}</td>
                    <td>{{ $val($r['passing_year'] ?? null) }}</td>
                    <td>{{ $val($r['roll_no'] ?? null) }}</td>
                    <td>{{ $val($r['cert_sr_no'] ?? null) }}</td>
                    <td>{{ $val($r['full_marks'] ?? null) }}</td>
                    <td>{{ $val($r['obtain_mark'] ?? null) }}</td>
                    <td>{{ $val($r['obtain_percent'] ?? null) }}{{ !empty($r['obtain_percent']) ? '%' : '' }}</td>
                    <td>{{ implode(', ', array_filter(array_merge([$r['group'] ?? null], $r['subjects'] ?? []))) }}</td>
                </tr>
            @empty
                <tr><td colspan="10" style="text-align:center;color:#999;">No educational records.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="foot">{{ now()->format('n/j/Y g:i:sA') }} &nbsp; Application No.:- {{ $sa->application_no }} &nbsp; Page 2 of 5</div>
</div>

{{-- ══════════════════════════ PAGE 3 — Course / TC / Migration ══════════════════════════ --}}
<div class="page">
    <div class="sec-title">Course Detail</div>
    <table class="kv">
        <tr>
            <td class="k">Name Of Course</td><td>{{ $course }}</td>
            <td class="k">Semester</td><td>{{ $val($sa->semester_no) }}</td>
        </tr>
    </table>

    <div class="sec-title blue">Paper Name</div>
    <table class="grid">
        <thead><tr><th>Definition</th><th>Subject</th><th>Paper Code</th><th>Paper Name</th></tr></thead>
        <tbody>
            @forelse($subjectRows as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td>{{ $row['subject'] }}</td>
                    <td>{{ $row['paper_code'] }}</td>
                    <td>{{ $row['paper_title'] }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center;color:#999;">No subjects selected.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="sec-title">Transfer Certificate (TC) Detail</div>
    <table class="kv">
        <tr>
            <td class="k">Condition</td>
            <td colspan="3">
                @php
                    $tcLabels = [
                        'have_original_tc' => 'I have Original TC',
                        'regular_not_applicable' => 'Regular student of this college — condition not applicable',
                        'dont_have_tc' => "I don't have TC at application date",
                    ];
                @endphp
                {{ $tcLabels[$p4['tc_condition'] ?? ''] ?? '—' }}
            </td>
        </tr>
        @if(($p4['tc_condition'] ?? '') !== 'regular_not_applicable')
        <tr>
            <td class="k">Name of Organization</td><td>{{ $val($p4['tc_org_name'] ?? null) }}</td>
            <td class="k">Contact No.</td><td>{{ $val($p4['tc_contact'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">TC Serial No.</td><td>{{ $val($p4['tc_serial_no'] ?? null) }}</td>
            <td class="k">Issue Date</td><td>{{ $fmt($p4['tc_issue_date'] ?? null) }}</td>
        </tr>
        @endif
    </table>
    @if(($p4['tc_condition'] ?? '') === 'dont_have_tc')
        <p style="font-size:9.5px;line-height:1.5;border:1px solid #ccc;padding:6px;background:#fafafa;">
            कतिपय व्यक्तिगत कारणों से मैं अपना मूल स्थानान्तरण प्रमाण-पत्र (TC) महाविद्यालय को नहीं उपलब्ध करा पा रहा/रही हूँ। प्रवेश लेने के 20 दिन के अन्दर मैं अपना मूल स्थानान्तरण प्रमाण-पत्र (TC) महाविद्यालय को प्राप्त करा दूँगा/दूँगी।
            <br>{{ $p4['tc_statement_signed'] ?? false ? 'Statement Signed By Student ✓' : '' }}
        </p>
    @endif

    @if(($p4['has_migration'] ?? false))
    <div class="sec-title">Migration Certificate (MC) Detail</div>
    <table class="kv">
        <tr>
            <td class="k">Name of University</td><td>{{ $val($p4['mig_university'] ?? null) }}</td>
            <td class="k">Leave Institute In Year</td><td>{{ $val($p4['mig_leave_year'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">Last Institute</td><td>{{ $val($p4['mig_last_institute'] ?? null) }}</td>
            <td class="k">Reason</td><td>{{ $val($p4['mig_reason'] ?? null) }}</td>
        </tr>
    </table>
    @if(($p4['mig_condition'] ?? '') === 'dont_have_migration')
        <p style="font-size:9.5px;line-height:1.5;border:1px solid #ccc;padding:6px;background:#fafafa;">
            कतिपय व्यक्तिगत कारणों से मैं अपना मूल प्रवजन प्रमाण-पत्र (माइग्रेशन) आवेदन पत्र के साथ संलग्न नहीं कर पा रहा/रही हूँ। प्रवेश तिथि से 25 दिन के अन्दर मैं अपना मूल प्रवजन प्रमाण-पत्र महाविद्यालय में जमा कर दूँगा/दूँगी।
        </p>
    @endif
    @endif

    <div class="foot">{{ now()->format('n/j/Y g:i:sA') }} &nbsp; Application No.:- {{ $sa->application_no }} &nbsp; Page 3 of 5</div>
</div>

{{-- ══════════════════════════ PAGE 4 — Bank + Declarations ══════════════════════════ --}}
<div class="page">
    <div class="sec-title">Bank Information</div>
    <table class="kv">
        <tr>
            <td class="k">Have Bank Account?</td><td>{{ ucfirst($val($p5['has_bank_account'] ?? null)) }}</td>
            <td class="k">Account Type</td><td>{{ ucfirst($val($p5['account_type'] ?? null)) }}</td>
        </tr>
        <tr>
            <td class="k">Name In Account</td><td>{{ $val($p5['name_in_account'] ?? null) }}</td>
            <td class="k">Father</td><td>{{ $val($p5['father_name'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">Bank Name</td><td>{{ $val($p5['bank_name'] ?? null) }}</td>
            <td class="k">Branch</td><td>{{ $val($p5['bank_branch'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">Account No.</td><td>{{ $val($p5['bank_account_no'] ?? null) }}</td>
            <td class="k">IFSC Code</td><td>{{ $val($p5['bank_ifsc'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">Branch Address</td><td colspan="3">{{ $val($p5['branch_address'] ?? null) }}</td>
        </tr>
    </table>

    <div class="sec-title blue" style="margin-top:16px;">Declaration Of Father/Guardian</div>
    <div style="border:1px solid #333;padding:8px;font-size:9.5px;line-height:1.6;">
        पिता/अभिभावक का घोषणा:- मैंने अपने पाल्य के प्रवेश से सम्बंधित सभी नियमों एवं शर्तों को अच्छी तरह से समझ लिया है। मेरा पाल्य आवेदित कक्षा में प्रवेश लेने की समस्त अर्हता को पूर्ण करता है तथा प्रवेश हेतु आवेदन कर रहा है।
    </div>
    <table class="sign-row"><tr><td>Date:- {{ now()->format('d-M-Y') }}</td><td>Signature Father/Guardian</td></tr></table>

    <div class="sec-title blue" style="margin-top:20px;">Declaration Of Student</div>
    <div style="border:1px solid #333;padding:8px;font-size:9.5px;line-height:1.6;">
        घोषणा:- मैंने प्रवेश से सम्बंधित सभी नियमों एवं शर्तों को अच्छी तरह से समझ लिया है। मैं आवेदित कक्षा में प्रवेश लेने की समस्त अर्हता को पूर्ण करता/करती हूँ तथा प्रवेश हेतु आवेदन कर रहा/रही हूँ।
    </div>
    <table class="sign-row">
        <tr>
            <td>Date:- {{ ($sa->status === 'submitted' || $sa->status === 'approved') ? now()->format('d-M-Y') : '—' }}</td>
            <td>
                @if($signatureUrl)
                    <img src="{{ $signatureUrl }}" alt="Signature" style="max-height:30px;">
                @else
                    Student Signature
                @endif
            </td>
        </tr>
    </table>

    <div class="foot">{{ now()->format('n/j/Y g:i:sA') }} &nbsp; Application No.:- {{ $sa->application_no }} &nbsp; Page 4 of 5</div>
</div>

{{-- ══════════════════════════ PAGE 5 — शपथ-पत्र (Oath) ══════════════════════════ --}}
<div class="page">
    <div class="oath-title">शपथ–पत्र</div>

    <p style="font-size:10px;">
        सेवा में- प्राचार्य/प्रशासनिक अधिकारी/प्रवेश समिति/अनुशासनात्मक समिति,<br>
        {{ $collegeName }}, {{ $collegeAddr }}।
    </p>

    <table class="kv" style="margin:10px 0;">
        <tr>
            <td class="k">मैं (Name)</td><td>{{ $studentName }}</td>
            <td class="k">पुत्र/पुत्री (Father)</td><td>{{ $fatherName }}</td>
        </tr>
        <tr>
            <td class="k">आधार कार्ड नं.</td><td>{{ $val($p1['aadhar_no'] ?? $reg->aadhar_no ?? null) }}</td>
            <td class="k">पंजीकरण सं.</td><td>{{ $val($reg->registration_no ?? null) }}</td>
        </tr>
        <tr>
            <td class="k">स्थायी निवासी</td><td colspan="3">{{ $addressLine ?: '—' }}</td>
        </tr>
    </table>

    <p style="font-size:10px;font-weight:bold;">शपथ पूर्वक वचन देता/देती हूँ कि —</p>

    <ol class="oath-points">
        @foreach($oathPoints as $point)
            <li>{{ $point }}</li>
        @endforeach
    </ol>

    <table class="sign-row" style="margin-top:30px;">
        <tr>
            <td>Date:- {{ ($sa->status === 'submitted' || $sa->status === 'approved') ? now()->format('d-M-Y') : '—' }}</td>
            <td>
                @if($signatureUrl)
                    <img src="{{ $signatureUrl }}" alt="Signature" style="max-height:30px;"><br>
                @endif
                {{ $studentName }}<br>
                <span style="font-size:9px;color:#666;">छात्र/छात्रा का हस्ताक्षर</span>
            </td>
        </tr>
    </table>

    <div class="foot">{{ now()->format('n/j/Y g:i:sA') }} &nbsp; Application No.:- {{ $sa->application_no }} &nbsp; Page 5 of 5</div>
</div>

</body>
</html>
