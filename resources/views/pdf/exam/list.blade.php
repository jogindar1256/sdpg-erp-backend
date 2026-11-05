{{-- Generic college-level list: college letter-head + class line + table --}}
<!DOCTYPE html><html><head>@include('pdf.exam._style', ['margin' => '9mm 11mm 14mm'])
<style>
    .lt th { border: 1px solid #000; background: #ececec; font-size: 10.5px; padding: 4px; }
    .lt td { border: 1px solid #000; font-size: 10.5px; padding: 3px 5px; height: {{ array_key_exists('Signature', $cols) ? 26 : 15 }}px; }
    tr { page-break-inside: avoid; }
    .pn { position: fixed; bottom: -8mm; right: 0; font-size: 9px; }
    .pn:after { content: "Page " counter(page); }
</style></head><body>
<div class="pn"></div>
@include('pdf.exam._collegehead', ['title' => $title])
<table width="100%" style="font-size:11.5px;margin:2px 0 6px;"><tr>
    <td>Class: - &nbsp;<b>{{ $course }}</b></td>
    <td>Semester: - &nbsp;<b>{{ $semester ? \App\Support\ExamPrintData::ordinal($semester) : 'All' }}</b></td>
    <td class="r">Total:- &nbsp;<b>{{ count($rows) }}</b></td>
</tr></table>
<table width="100%" class="lt">
    <thead><tr><th width="6%">Sr.No.</th>@foreach(array_keys($cols) as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse($rows as $i => $r)
        <tr><td class="c">{{ $i + 1 }}</td>@foreach($cols as $k)<td>{{ $k ? ($r[$k] ?? '') : '' }}</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ count($cols) + 1 }}" class="c" style="padding:20px;">No records.</td></tr>
    @endforelse
    </tbody>
</table>
</body></html>
