{{-- P-7 for Practical / Viva-Voce: all examinees of a paper (not room-wise), 60 per page --}}
@php
    use App\Support\ExamPrintData as D;
    $perCol = 30; $perPage = 60;
    $list = collect($papers)->map(fn ($p) => [$p, $p->seats->sortBy('roll_no')->values()])->values();
@endphp
<!DOCTYPE html><html><head>@include('pdf.exam._style', ['margin' => '8mm 10mm'])
<style>
    .t td, .t th { border: 1px solid #000; font-size: 10px; }
    .t th { padding: 4px; } .t td { height: 17.5px; padding: 0 4px; }
</style></head><body>
@forelse($list as $pi => [$p, $rolls])
    @php $chunks = $rolls->count() ? $rolls->chunk($perPage) : collect([collect()]); $pages = $chunks->count(); @endphp
    @foreach($chunks as $ci => $chunk)
        @php
            $isLast = ($pi === $list->count() - 1) && ($ci === $pages - 1);
            $f = ['centre' => $centre['full'], 'code' => $centre['code']];
            $rows = $chunk->values(); $base = $ci * $perPage;
        @endphp
        <div class="page {{ $isLast ? 'last' : '' }}">
            @include('pdf.exam._p7head')
            <table width="100%" style="margin:8px 0 4px;font-size:11px;"><tr>
                <td>Subject : &nbsp;<b>{{ $p->subject }}</b></td><td class="c">Paper Code &nbsp;<b>{{ $p->paper_code }}</b></td><td class="r">Class : &nbsp;<b>{{ $p->course }}</b></td>
            </tr></table>
            <table width="100%" class="t">
                <tr><th width="5%">Sr No.</th><th width="15%">Roll No</th><th width="13%">Copy No</th><th width="17%">Signature</th>
                    <th width="5%">Sr No.</th><th width="15%">Roll No</th><th width="13%">Copy No</th><th width="17%">Signature</th></tr>
                @for($r = 0; $r < $perCol; $r++)
                    @php $a = $rows->get($r); $b = $rows->get($r + $perCol); @endphp
                    <tr>
                        <td class="c">{{ $a ? $base + $r + 1 : '' }}</td><td>{{ $a->roll_no ?? '' }}</td><td></td><td></td>
                        <td class="c">{{ $b ? $base + $r + $perCol + 1 : '' }}</td><td>{{ $b->roll_no ?? '' }}</td><td></td><td></td>
                    </tr>
                @endfor
            </table>
            <div class="c" style="font-size:9px;margin-top:8px;">Page {{ $ci + 1 }} of {{ $pages }}</div>
        </div>
    @endforeach
@empty
    <p class="c" style="margin-top:80px;font-size:14px;">No paper is scheduled for this date and inning.</p>
@endforelse
</body></html>
