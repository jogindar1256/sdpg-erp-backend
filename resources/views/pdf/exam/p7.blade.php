{{-- P-7 room-wise: one block per room + paper, 25 rows per column, 50 per page --}}
@php
    use App\Support\ExamPrintData as D;
    $perCol = 25; $perPage = 50;
    $groups = [];
    foreach ($papers as $p) foreach ($p->rooms as $room) $groups[] = [$p, $room];
@endphp
<!DOCTYPE html><html><head>@include('pdf.exam._style', ['margin' => '8mm 10mm'])
<style>
    .t td, .t th { border: 1px solid #000; font-size: 10px; }
    .t th { padding: 4px; } .t td { height: 20px; padding: 0 4px; }
</style></head><body>
@forelse($groups as $gi => [$p, $room])
    @php $chunks = $room->seats->chunk($perPage); $pages = $chunks->count(); @endphp
    @foreach($chunks as $ci => $chunk)
        @php
            $isLast = ($gi === count($groups) - 1) && ($ci === $pages - 1);
            $f = ['centre' => $centre['full'], 'code' => $centre['code'], 'room' => $room->room_no, 'date' => D::dmy($date, 'd-M-Y'),
                  'inning' => $inning->inning_name, 'paper' => $p->paper_code, 'class' => $p->course, 'sem' => 'Semes : ' . $p->semester, 'subject' => $p->subject];
            $rows = $chunk->values();
        @endphp
        <div class="page {{ $isLast ? 'last' : '' }}">
            @include('pdf.exam._p7head')
            <table width="100%" class="t" style="margin-top:6px;">
                <tr><th width="5%">S.No.</th><th width="17%">Roll No</th><th width="11%">Copy No</th><th width="17%">Signature</th>
                    <th width="5%">S.No.</th><th width="17%">Roll No</th><th width="11%">Copy No</th><th width="17%">Signature</th></tr>
                @for($r = 0; $r < $perCol; $r++)
                    @php $a = $rows->get($r); $b = $rows->get($r + $perCol); $base = $ci * $perPage; @endphp
                    <tr>
                        <td class="c">{{ $a ? $base + $r + 1 : '' }}</td><td>{{ $a->roll_no ?? '' }}</td><td></td><td></td>
                        <td class="c">{{ $b ? $base + $r + $perCol + 1 : '' }}</td><td>{{ $b->roll_no ?? '' }}</td><td></td><td></td>
                    </tr>
                @endfor
            </table>
            <table width="100%" style="margin-top:26px;font-size:10px;" class="b"><tr>
                <td>1- Invigilator Sign.</td><td class="c">2- Invigilator Sign.</td><td class="r">C.S. Sign.</td>
            </tr></table>
            <div class="c" style="font-size:9px;margin-top:10px;">Page {{ $ci + 1 }} of {{ $pages }}</div>
        </div>
    @endforeach
@empty
    <p class="c" style="margin-top:80px;font-size:14px;">No seating plan is saved for this date and inning.</p>
@endforelse
</body></html>
