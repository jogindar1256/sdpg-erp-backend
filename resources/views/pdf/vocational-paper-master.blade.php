@php
    $collegeName = $org->name ?? 'Swami Devanand Post Graduate College, Math Lar';
    $courseName = $program->full_name ?? $program->short_name ?? '';
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 12mm; }
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 11px; color: #1f2937; }
    .head { text-align: center; border-bottom: 2px solid #b8860b; padding-bottom: 8px; margin-bottom: 10px; }
    .head .college { font-size: 16px; font-weight: bold; color: #6B2D0F; }
    .head .sub { font-size: 11px; color: #555; }
    .title { text-align: center; background: #b8860b; color: #fff; font-weight: bold; padding: 6px; font-size: 13px; letter-spacing: 0.5px; margin-bottom: 10px; }
    table.info { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    table.info td { padding: 3px 8px; border: 1px solid #d1d5db; }
    table.info td.k { width: 20%; background: #f9fafb; font-weight: bold; }
    .group-head { background: #a9d08e; font-weight: bold; padding: 5px 8px; font-size: 12px; margin-top: 10px; }
    table.papers { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    table.papers th { background: #f2c94c; padding: 4px 8px; border: 1px solid #b8860b; text-align: left; font-size: 11px; }
    table.papers td { padding: 4px 8px; border: 1px solid #d1d5db; }
    .foot { margin-top: 20px; font-size: 10px; color: #6b7280; text-align: center; }
</style>
</head>
<body>

    <div class="head">
        <div class="college">{{ $collegeName }}</div>
        <div class="sub">Vocational & Co-Curriculum Paper Master</div>
    </div>

    <div class="title">Vocational & Co-Curriculum Paper For Session- {{ $sessionYear }}</div>

    <table class="info">
        <tr>
            <td class="k">Class</td><td>{{ $program->short_name ?? '-' }} — {{ $courseName }}</td>
            <td class="k">Semester No.</td><td>{{ $semesterNo }}</td>
        </tr>
    </table>

    @forelse($groups as $groupNo => $papers)
        @php $first = $papers->first(); @endphp
        <div class="group-head">
            Group {{ $groupNo }}{{ $first->group_name ? " — {$first->group_name}" : '' }}
            &nbsp;&nbsp;(Max. Select: {{ $first->max_select ?? 1 }}, Min Select: {{ $first->min_select ?? 1 }})
        </div>
        <table class="papers">
            <thead>
                <tr>
                    <th style="width:20%">Paper Code</th>
                    <th style="width:50%">Name of Minor Paper</th>
                    <th style="width:15%">Max</th>
                    <th style="width:15%">Min</th>
                </tr>
            </thead>
            <tbody>
                @foreach($papers as $p)
                    <tr>
                        <td>{{ $p->paper_code }}</td>
                        <td>{{ $p->paper_name }}</td>
                        <td>{{ $p->max_marks }}</td>
                        <td>{{ $p->min_marks }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p style="text-align:center;color:#6b7280;padding:20px 0;">No papers defined for this class / semester / session.</p>
    @endforelse

    <div class="foot">Printed on {{ now()->format('d-M-Y H:i') }}</div>

</body>
</html>
