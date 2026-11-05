{{-- P-6: centre statement, one form per paper --}}
@php
    use App\Support\ExamPrintData as D;
    use App\Support\HindiAsset as H;
    $formNo = 'P-6';
    $list = collect($papers)->values();
@endphp
<!DOCTYPE html><html><head>@include('pdf.exam._style', ['margin' => '9mm 11mm'])
<style>
    .f td { font-size: 11px; padding: 4px 0; vertical-align: bottom; }
    .v { border-bottom: 1px dotted #000; font-weight: bold; padding: 0 4px; font-size: 11.5px; }
    .rolls { border-bottom: 1px dotted #000; min-height: 20px; font-size: 11px; font-weight: bold; line-height: 1.9; padding: 0 4px; }
</style></head><body>
@forelse($list as $pi => $p)
    @php
        $seated = $p->seats->count() ?: $p->enrolled;
        $appeared = max(0, $seated - count($p->absent));
        $copies = $p->p9['total_copy'] ?? null;
        $isLast = $pi === $list->count() - 1;
    @endphp
    <div class="page {{ $isLast ? 'last' : '' }}">
        @include('pdf.exam._formhead')
        <div style="margin:10px 0 6px;">{!! H::img('p6_para') !!}</div>
        <table width="100%" class="f" style="margin-left:30px;width:94%;">
            <tr><td width="22%">{!! H::img('p6_college') !!}</td><td class="v">{{ $centre['name'] }}</td></tr>
            <tr><td>{!! H::img('p6_centre') !!}</td><td class="v">({{ $centre['code'] }}) {{ $centre['full'] }}</td></tr>
            <tr><td>{!! H::img('p6_class') !!}</td><td><table width="100%"><tr><td class="v" width="45%">{{ $p->course }}, {{ $p->semester }} Sem</td><td width="14%" class="r">{!! H::img('p6_year') !!}</td><td class="v">{{ $term }} {{ $session }}</td></tr></table></td></tr>
        </table>
        <table width="100%" class="f" style="margin-top:14px;">
            <tr><td width="4%">1.</td><td>{!! H::img('p6_1') !!} <span class="v">{{ $p->subject }}</span> &nbsp;{!! H::img('p6_1b') !!} <span class="v">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span> &nbsp;{!! H::img('p6_1c') !!} <span class="v">{{ $p->paper_code }}</span></td></tr>
            <tr><td>2.</td><td>{!! H::img('p6_2') !!} <span class="v">&nbsp;{{ $p->enrolled }}&nbsp;</span></td></tr>
            <tr><td>3.</td><td>{!! H::img('p6_3') !!} <span class="v">&nbsp;{{ $appeared }}&nbsp;</span></td></tr>
            <tr><td style="vertical-align:top;">4.</td><td>{!! H::img('p6_4') !!} <span class="v">&nbsp;{{ count($p->ufm) }}&nbsp;</span>
                <div style="margin-top:4px;">{!! H::img('p6_roll') !!}</div><div class="rolls" style="min-height:44px;">{{ implode(',  ', $p->ufm) }}</div></td></tr>
            <tr><td style="vertical-align:top;">5.</td><td>{!! H::img('p6_5') !!} <span class="v">&nbsp;{{ count($p->absent) }}&nbsp;</span>
                <div style="margin-top:4px;">{!! H::img('p6_roll') !!}</div><div class="rolls" style="min-height:80px;">{{ implode(',  ', $p->absent) }}</div></td></tr>
            <tr><td style="vertical-align:top;">6.</td><td>{!! H::img('p6_6') !!}
                <div class="rolls" style="min-height:100px;margin-top:4px;">{{ implode(',  ', $p->back_rolls) }}</div></td></tr>
            <tr><td>7.</td><td>{!! H::img('p6_7') !!} <span class="v">&nbsp;{{ $copies }}&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span></td></tr>
        </table>
        <table width="100%" style="margin-top:40px;font-size:10px;" class="b"><tr><td>Centre Superintendent</td><td class="r">Date : {{ D::dmy($date) }}</td></tr></table>
    </div>
@empty
    <p class="c" style="margin-top:80px;font-size:14px;">No paper is scheduled for this date and inning.</p>
@endforelse
</body></html>
