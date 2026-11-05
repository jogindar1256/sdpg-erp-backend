{{-- Nominal Roll: college letter-head, landscape, 5 columns (each cell stacks the 2-3 fields of the sample) --}}
<!DOCTYPE html><html><head>@include('pdf.exam._style', ['margin' => '8mm 10mm 13mm'])
<style>
    .nt th { border: 1px solid #000; background: #bfbfbf; font-size: 10.5px; text-align: left; padding: 4px 5px; line-height: 1.35; }
    .nt td { border: 1px solid #000; font-size: 10.5px; padding: 5px 6px; vertical-align: top; line-height: 1.45; }
    tr { page-break-inside: avoid; }
    .pn { position: fixed; bottom: -7mm; right: 0; font-size: 9px; }
    .pn:after { content: "Page " counter(page); }
</style></head><body>
<div class="pn"></div>
@include('pdf.exam._collegehead')
<div class="c b" style="font-size:16px;text-decoration:underline;margin:0 0 6px;">{{ $title }}</div>
<table width="100%" style="font-size:14px;font-weight:bold;margin:4px 0 8px;"><tr>
    <td>Class: - &nbsp;{{ $course }}</td>
    <td class="c">Semester: - &nbsp;{{ $semester ? \App\Support\ExamPrintData::ordinal($semester) : 'All' }}</td>
    <td class="r">Total:- &nbsp;{{ count($rows) }}</td>
</tr></table>
<table width="100%" class="nt">
    <thead><tr>
        <th width="5%">Sr.No.</th>
        <th width="16%">Roll No.<br>Exam Form ID<br>Enrolment No.</th>
        <th width="24%">Name<br>Father Name<br>Mother Name</th>
        <th width="10%">Gender<br>DOB<br>Mode</th>
        <th>Subject<br>Subject Code</th>
    </tr></thead>
    <tbody>
    @forelse($rows as $i => $r)
        @php
            $dob = $r['dob'] ?? null;
            $dobFmt = $dob ? (strtotime((string) $dob) ? date('d/m/Y', strtotime((string) $dob)) : $dob) : '';
        @endphp
        <tr>
            <td class="c">{{ $i + 1 }}</td>
            <td>{{ $r['roll_no'] ?? '' }}<br>{{ $r['form_id'] ?? '' }}<br>{{ $r['enrollment_no'] ?? '' }}</td>
            <td><b>{{ $r['name'] ?? '' }}</b><br>{{ $r['father_name'] ?? '' }}<br>{{ $r['mother_name'] ?? '' }}</td>
            <td>{{ $r['gender'] ?? '' }}<br>{{ $dobFmt }}<br>{{ $r['exam_type'] ?? '' }}</td>
            <td>{{ implode('  ', $r['subjects'] ?? []) }}<br>{{ implode('  ', $r['paper_codes'] ?? []) }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="c" style="padding:24px;">No students are on the nominal roll for this selection.</td></tr>
    @endforelse
    </tbody>
</table>
</body></html>
