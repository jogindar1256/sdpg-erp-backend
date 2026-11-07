{{-- P-9: answer-book packet handover, one form per exam date + inning --}}
@php
    use App\Support\ExamPrintData as D;
    use App\Support\HindiAsset as H;
    $formNo = 'P-9';
    $rows = collect($papers)->values();
    $ufmRows = $rows->filter(fn ($p) => count($p->ufm) > 0)->values();
    $present = fn ($p) => max(0, ($p->seats->count() ?: $p->enrolled) - count($p->absent));
    $w = [4, 8, 7, 8, 9, 17, 9, 8, 6, 7, 9, 8];
@endphp
<!DOCTYPE html><html><head>@include('pdf.exam._style', ['margin' => '8mm 9mm'])
<style>
    .f td { font-size: 11px; padding: 3px 0; vertical-align: bottom; }
    .v { border-bottom: 1px dotted #000; font-weight: bold; padding: 0 4px; }
    .t td, .t th { border: 1px solid #000; }
    .t th { height: 118px; vertical-align: middle; text-align: center; padding: 0; }
    .t td { font-size: 10px; text-align: center; height: 21px; padding: 0 2px; }
    .box td { border: 1px solid #000; }
</style></head><body>
<div class="page last">
    @include('pdf.exam._formhead')
    <div class="c" style="margin-top:2px;">{!! H::img('p9_sub1') !!}</div>
    <div class="c" style="margin:2px 0 8px;">{!! H::img('p9_sub2') !!}</div>
    <table width="100%" class="f">
        <tr><td width="11%">{!! H::img('p9_centre') !!}</td><td class="v" colspan="3">{{ $centre['full'] }}</td></tr>
        <tr><td>{!! H::img('p9_centrecode') !!}</td><td class="v" width="24%">{{ $centre['code'] }}</td><td width="29%" class="r">{!! H::img('p9_joint') !!}</td><td class="v">&nbsp;</td></tr>
    </table>
    <div style="margin:3px 0 6px;">{!! H::img('p9_note') !!}</div>
    <div class="c" style="margin-bottom:3px;">{!! H::img('p9_detail') !!}</div>

    <table width="100%" class="t" style="table-layout:fixed;">
        <tr>@for($i = 1; $i <= 12; $i++)<th width="{{ $w[$i - 1] }}%">{!! H::img('p9c_' . $i) !!}</th>@endfor</tr>
        @php $minRows = 17; @endphp
        @foreach($rows as $i => $p)
            <tr>
                <td>{{ $i + 1 }}</td><td>{{ D::dmy($date) }}</td><td>{{ substr($weekday, 0, 3) }}</td><td>{{ $inning->inning_name }}</td>
                <td>{{ $p->course }} Sem {{ $p->semester_no }}</td><td style="text-align:left;">{{ $p->subject }}</td><td>{{ $p->paper_code }}</td>
                <td>{{ $present($p) }}</td><td>{{ !empty($p->p9['packet_no']) ? 1 : '' }}</td><td>{{ $p->p9['total_copy'] ?? '' }}</td>
                <td>{{ $p->p9['packet_no'] ?? '' }}</td><td>&nbsp;</td>
            </tr>
        @endforeach
        @for($i = $rows->count(); $i < $minRows; $i++)<tr>@for($k = 0; $k < 12; $k++)<td>&nbsp;</td>@endfor</tr>@endfor
    </table>

    <div class="b" style="margin:8px 0 3px;">{!! H::img('p9_ufmhead') !!}</div>
    <table width="100%" class="t" style="table-layout:fixed;">
        @foreach($ufmRows as $i => $p)
            <tr>
                <td width="{{ $w[0] }}%">{{ $i + 1 }}</td><td width="{{ $w[1] }}%">{{ D::dmy($date) }}</td><td width="{{ $w[2] }}%">{{ substr($weekday, 0, 3) }}</td><td width="{{ $w[3] }}%">{{ $inning->inning_name }}</td>
                <td width="{{ $w[4] }}%">{{ $p->course }} Sem {{ $p->semester_no }}</td><td width="{{ $w[5] }}%" style="text-align:left;">{{ $p->subject }}</td><td width="{{ $w[6] }}%">{{ $p->paper_code }}</td>
                <td width="{{ $w[7] }}%">{{ count($p->ufm) }}</td><td width="{{ $w[8] }}%">{{ !empty($p->p9['ufm_packet_no']) ? 1 : '' }}</td><td width="{{ $w[9] }}%">{{ $p->p9['ufm_total_copy'] ?? '' }}</td>
                <td width="{{ $w[10] }}%">{{ $p->p9['ufm_packet_no'] ?? '' }}</td><td width="{{ $w[11] }}%">&nbsp;</td>
            </tr>
        @endforeach
        @for($i = $ufmRows->count(); $i < max(6, $ufmRows->count()); $i++)<tr>@for($k = 0; $k < 12; $k++)<td width="{{ $w[$k] }}%">&nbsp;</td>@endfor</tr>@endfor
    </table>

    <table width="100%" class="box" style="margin-top:0;">
        <tr>
            <td width="50%" style="padding:6px 8px;height:80px;vertical-align:top;">{!! H::img('p9_recv1') !!}<div style="margin-top:26px;">{!! H::img('p9_sign_recv') !!}</div><div>{!! H::img('p9_two_copies') !!}</div></td>
            <td width="50%" style="padding:6px 8px;vertical-align:top;">{!! H::img('p9_recv1') !!}<div class="r" style="margin-top:26px;">{!! H::img('p9_sign_cs') !!}</div></td>
        </tr>
        <tr><td colspan="2" style="padding:6px 8px;">{!! H::img('p9_recv2') !!}<div class="r" style="margin-top:6px;">{!! H::img('p9_coord') !!} ....................................</div></td></tr>
    </table>
</div>
</body></html>
