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
        <div class="sub">Subject Selection Master</div>
    </div>

    <div class="title">Subject Selection{{ $semesterNo ? " — Semester {$semesterNo}" : '' }}</div>

    <table class="info">
        <tr>
            <td class="k">Class</td><td>{{ $program->short_name ?? '-' }} — {{ $courseName }}</td>
            <td class="k">Total Groups</td><td>{{ count($groups) }}</td>
        </tr>
    </table>

    @forelse($groups as $g)
        <div class="group-head">
            Group {{ $g['group_label'] }}{{ $g['group_name'] ? " — {$g['group_name']}" : '' }}
            &nbsp;&nbsp;(Max. Select: {{ $g['max_select'] }}, Min Select: {{ $g['min_select'] }})
        </div>
        <table class="papers">
            <thead>
                <tr>
                    <th style="width:10%">#</th>
                    <th style="width:30%">Subject Code</th>
                    <th>Subject Name</th>
                </tr>
            </thead>
            <tbody>
                @foreach($g['subjects'] as $i => $s)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $s['subject_code'] ?? '-' }}</td>
                        <td>{{ $s['subject_name'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p style="text-align:center;color:#6b7280;padding:20px 0;">No subject groups defined for this class / semester.</p>
    @endforelse

    <div class="foot">Printed on {{ now()->format('d-M-Y H:i') }}</div>

</body>
</html>
