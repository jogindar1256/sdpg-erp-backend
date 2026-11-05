{{-- P1: room-wise answer-book copy statement. $mode = all | received | blank --}}
@php
    use App\Support\ExamPrintData as D;
    $list = collect($papers)->values();
    $blank = $mode === 'blank';
@endphp
<!DOCTYPE html><html><head>@include('pdf.exam._style', ['margin' => '10mm 12mm'])
<style>
    .t td, .t th { border: 1px solid #000; font-size: 11px; }
    .t th { background: #ececec; padding: 5px; } .t td { height: 26px; padding: 0 6px; text-align: center; }
</style></head><body>
@forelse($list as $pi => $p)
    <div class="page {{ $pi === $list->count() - 1 ? 'last' : '' }}">
        @php $formNo = 'P-1'; @endphp
        @include('pdf.exam._formhead')
        <div class="c b" style="font-size:12px;margin-top:2px;">{{ $mode === 'received' ? 'Received Copy' : '' }}</div>
        <div style="border-top:1px solid #000;margin:6px 0;padding-top:4px;font-size:11px;"><b>Exam Centre :-</b> ({{ $centre['code'] }}) {{ $centre['place'] }}</div>
        <table width="100%" style="font-size:11px;margin-bottom:6px;"><tr>
            <td><b>Exam Date:-</b> {{ $blank ? '' : D::dmy($date, 'd-M-Y') }}</td><td><b>Inning:-</b> {{ $blank ? '' : $inning->inning_name }}</td>
            <td><b>Time:-</b> {{ $blank ? '' : $inning->time_start . ' To ' . $inning->time_end }}</td>
        </tr></table>
        <table width="100%" style="font-size:11px;margin-bottom:8px;"><tr>
            <td><b>Class:-</b> {{ $blank ? '' : $p->course . ' / ' . $p->semester . ' Sem' }}</td>
            <td><b>Subject:-</b> {{ $blank ? '' : $p->subject }}</td><td><b>Paper Code:-</b> {{ $blank ? '' : $p->paper_code }}</td>
        </tr></table>
        <table width="100%" class="t">
            <tr><th>Room No.</th><th>Copy In Room</th><th>ABS In Room</th><th>Required Copy</th><th>UFM</th><th>Received Copy</th></tr>
            @php $saved = collect($p->p1['rooms'] ?? [])->keyBy('room_id'); @endphp
            @if($blank)
                @for($i = 0; $i < 12; $i++)<tr>@for($k = 0; $k < 6; $k++)<td>&nbsp;</td>@endfor</tr>@endfor
            @else
                @foreach($p->rooms as $room)
                    @php
                        $abs = $p->absent_by_room[$room->room_id] ?? 0; $uf = $p->ufm_by_room[$room->room_id] ?? 0; $s = $saved->get($room->room_id);
                        $showCopy = $mode !== 'received';
                    @endphp
                    <tr>
                        <td class="b">{{ $room->room_no }}</td>
                        <td>{{ $showCopy ? ($s['copy_in_room'] ?? '') : '' }}</td>
                        <td>{{ $abs }}</td><td class="b">{{ max(0, $room->seats->count() - $abs) }}</td><td>{{ $uf }}</td>
                        <td>{{ $s['received_copy'] ?? '' }}</td>
                    </tr>
                @endforeach
                <tr><td class="b">Total</td><td></td><td class="b">{{ array_sum($p->absent_by_room) }}</td><td class="b">{{ $p->seats->count() - array_sum($p->absent_by_room) }}</td><td class="b">{{ array_sum($p->ufm_by_room) }}</td><td></td></tr>
            @endif
        </table>
        <table width="100%" style="margin-top:34px;font-size:10px;" class="b"><tr><td>Invigilator Sign.</td><td class="r">C.S. Sign.</td></tr></table>
    </div>
@empty
    <p class="c" style="margin-top:80px;font-size:14px;">No paper is scheduled for this date and inning.</p>
@endforelse
</body></html>
