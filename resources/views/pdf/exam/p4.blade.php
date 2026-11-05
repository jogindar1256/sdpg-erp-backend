{{-- P-4: absent / unfair-means roll numbers, one form per paper --}}
@php
    use App\Support\ExamPrintData as D;
    use App\Support\HindiAsset as H;
    $formNo = 'P-4';
    $list = collect($papers)->values();
@endphp
<!DOCTYPE html><html><head>@include('pdf.exam._style', ['margin' => '8mm 10mm'])
<style>
    .f td { font-size: 11px; padding: 3px 0; vertical-align: bottom; }
    .v { border-bottom: 1px dotted #000; font-weight: bold; padding: 0 4px; }
    .t td, .t th { border: 1px solid #000; }
    .t td { height: 23px; font-size: 11.5px; padding: 0 5px; }
</style></head><body>
@forelse($list as $pi => $p)
    @php
        $abs = collect($p->absent)->values(); $uf = collect($p->ufm)->values();
        $pages = max(1, (int) ceil($abs->count() / 40), (int) ceil($uf->count() / 20));
        $seated = $p->seats->count() ?: $p->enrolled;
    @endphp
    @for($pg = 0; $pg < $pages; $pg++)
        @php $isLast = ($pi === $list->count() - 1) && ($pg === $pages - 1); @endphp
        <div class="page {{ $isLast ? 'last' : '' }}">
            @include('pdf.exam._formhead')
            <div class="c" style="margin-top:2px;">{!! H::img('p4_sub1') !!}</div>
            <div class="c" style="margin:1px 0 6px;">{!! H::img('p4_sub2') !!}</div>
            <div style="margin-bottom:3px;">{!! H::img('p4_nirdesh') !!}</div>
            <div style="padding-left:10px;line-height:1.1;">{!! H::img('p4_i1') !!}<br>{!! H::img('p4_i2') !!}<br>{!! H::img('p4_i3') !!}<br>{!! H::img('p4_i4') !!}</div>
            <table width="100%" class="f" style="margin-top:4px;">
                <tr><td width="14%">{!! H::img('l_centre') !!}</td><td class="v" colspan="5">({{ $centre['code'] }}) {{ $centre['full'] }}</td></tr>
                <tr><td>{!! H::img('l_college') !!}</td><td class="v" colspan="5">{{ $centre['name'] }}</td></tr>
                <tr><td>{!! H::img('l_exam') !!}</td><td class="v" width="40%">{{ $term }} {{ $session }}</td><td width="8%" class="c">{!! H::img('l_part') !!}</td><td class="v" colspan="3">{{ $p->course }}, {{ $p->semester }} Sem</td></tr>
                <tr><td>{!! H::img('l_subject') !!}</td><td class="v">{{ $p->subject }}</td><td class="c">{!! H::img('l_paper') !!}</td><td class="v" width="16%">&nbsp;</td><td width="9%" class="c">{!! H::img('l_code') !!}</td><td class="v" width="14%">{{ $p->paper_code }}</td></tr>
                <tr><td>{!! H::img('l_examdate') !!}</td><td class="v">{{ D::dmy($date) }}</td><td class="c">{!! H::img('l_session') !!}</td><td class="v" colspan="3">{{ $inning->inning_name }} ({{ $inning->time_start }} - {{ $inning->time_end }})</td></tr>
                <tr><td colspan="3" style="text-align:right;">{!! H::img('l_present') !!}</td><td class="v" colspan="3">{{ max(0, $seated - $abs->count()) }}</td></tr>
            </table>

            <table width="100%" class="t" style="margin-top:8px;table-layout:fixed;">
                <tr><td colspan="4" class="c" style="height:34px;">{!! H::img('p4_h_abs', 1.1) !!}</td><td colspan="2" class="c">{!! H::img('p4_h_ufm', 0.95) !!}</td></tr>
                <tr class="c"><td width="7%">{!! H::img('h_sr', 0.9) !!}</td><td width="26%">{!! H::img('h_roll') !!}</td><td width="7%">{!! H::img('h_sr', 0.9) !!}</td><td width="26%">{!! H::img('h_roll') !!}</td><td width="7%">{!! H::img('h_sr', 0.9) !!}</td><td width="27%">{!! H::img('h_roll') !!}</td></tr>
                @for($r = 0; $r < 20; $r++)
                    @php
                        $i1 = $pg * 40 + $r; $i2 = $pg * 40 + 20 + $r; $i3 = $pg * 20 + $r;
                    @endphp
                    <tr>
                        <td class="c">{{ $i1 + 1 }}</td><td class="b">{{ $abs->get($i1) }}</td>
                        <td class="c">{{ $i2 + 1 }}</td><td class="b">{{ $abs->get($i2) }}</td>
                        <td class="c">{{ $r + 1 + $pg * 20 }}</td><td class="b">{{ $uf->get($i3) }}</td>
                    </tr>
                @endfor
                <tr><td colspan="2">{!! H::img('h_total') !!} &nbsp;<b>{{ $pg === $pages - 1 ? $abs->count() : '' }}</b></td><td colspan="2">{!! H::img('h_total') !!}</td><td colspan="2">{!! H::img('h_total') !!} &nbsp;<b>{{ $pg === $pages - 1 ? $uf->count() : '' }}</b></td></tr>
            </table>
            @if($pages > 1)<div class="r" style="font-size:9px;margin-top:4px;">Sheet {{ $pg + 1 }} of {{ $pages }}</div>@endif
        </div>
    @endfor
@empty
    <p class="c" style="margin-top:80px;font-size:14px;">No paper is scheduled for this date and inning.</p>
@endforelse
</body></html>
