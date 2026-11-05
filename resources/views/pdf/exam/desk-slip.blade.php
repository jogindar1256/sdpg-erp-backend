{{-- Desk slip: 10 per page (2 x 5), one set per room + paper, in seat order --}}
@php
    use App\Support\ExamPrintData as D;
    $perPage = 10;
    $groups = [];
    foreach ($papers as $p) foreach ($p->rooms as $room) $groups[] = [$p, $room];
@endphp
<!DOCTYPE html><html><head>@include('pdf.exam._style', ['margin' => '8mm 9mm'])
<style>
    .outer { border: 1px solid #000; }
    .bar { background: #7b3a10; color: #fff; text-align: center; font-size: 17px; font-weight: bold; padding: 2px 0; }
    .slip { width: 100%; height: 132px; border: 1px solid #000; }
    .slip td { font-size: 10px; font-weight: bold; vertical-align: top; }
    .photo { width: 78px; height: 96px; border: 1px solid #000; text-align: center; vertical-align: middle; font-size: 11px; font-weight: normal; }
    .photo img { width: 78px; height: 96px; }
    .roll { border: 1px solid #000; font-size: 12px; padding: 2px 6px; }
</style></head><body>
@forelse($groups as $gi => [$p, $room])
    @php $chunks = $room->seats->chunk($perPage); @endphp
    @foreach($chunks as $ci => $chunk)
        @php $isLast = ($gi === count($groups) - 1) && ($ci === $chunks->count() - 1); $rows = $chunk->values(); @endphp
        <div class="page {{ $isLast ? 'last' : '' }}">
            <div class="outer">
                <div class="bar">Desk Slip</div>
                <div class="c" style="font-size:11px;">Exam:- {{ $session }}</div>
                <table width="100%" style="font-size:11px;font-weight:bold;margin:2px 6px 6px;">
                    <tr><td width="34%">Exam Date:- {{ D::dmy($date, 'd-M-Y') }}</td><td width="34%">Inning: - {{ $inning->inning_name }}</td><td>Time: - {{ $inning->time_start }} To {{ $inning->time_end }}</td></tr>
                    <tr><td>Subject:- {{ $p->subject }}</td><td>Paper Code: - {{ $p->paper_code }}</td><td>Room No.: - {{ $room->room_no }}</td></tr>
                </table>
                <table width="100%" style="table-layout:fixed;margin-bottom:6px;">
                    @for($r = 0; $r < 5; $r++)
                        <tr>
                        @for($c = 0; $c < 2; $c++)
                            @php $s = $rows->get($r * 2 + $c); @endphp
                            <td width="50%" style="padding:{{ $c === 0 ? '0 5px 8px 8px' : '0 8px 8px 5px' }};">
                            @if($s)
                                <table class="slip"><tr>
                                    <td style="padding:4px 6px;">
                                        <div class="roll" style="width:120px;">Roll No: {{ $s->roll_no }}</div>
                                        <table width="100%" style="margin-top:5px;"><tr><td>Class: {{ $p->course }}</td><td>Sem.: {{ $p->semester_no }}</td></tr></table>
                                        <div style="margin-top:3px;">Name: {{ $s->name }}</div>
                                        <div style="margin-top:3px;">Father: {{ $s->father }}</div>
                                        <div style="margin-top:3px;">Subject Code: {{ $p->paper_code }}</div>
                                    </td>
                                    <td width="82" style="padding:30px 4px 0 0;"><div class="photo">@if($s->photo)<img src="{{ $s->photo }}" alt="">@else Photo @endif</div></td>
                                </tr></table>
                            @endif
                            </td>
                        @endfor
                        </tr>
                    @endfor
                </table>
            </div>
        </div>
    @endforeach
@empty
    <p class="c" style="margin-top:80px;font-size:14px;">No seating plan is saved for this date and inning.</p>
@endforelse
</body></html>
