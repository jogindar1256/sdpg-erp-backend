{{-- P-7 header: emblem + Hindi title + instructions. $fields = [centre, code, room, date, session, paper, class, sem, subject] --}}
@php use App\Support\HindiAsset as H; @endphp
@php $formNo = 'P-7'; @endphp
@include('pdf.exam._formhead')
<div style="margin:3px 0 4px;">{!! H::img('p7_ins', 0.95) !!}</div>
<table width="100%" style="font-size:11px;">
    <tr><td colspan="3" style="padding:2px 0;">{!! H::img('p7_1') !!} &nbsp;<span class="dot">&nbsp;{{ $f['centre'] ?? '' }}&nbsp;</span></td></tr>
    <tr>
        <td width="36%" style="padding:2px 0;">{!! H::img('p7_2') !!} &nbsp;<b style="font-size:14px">{{ $f['code'] ?? '' }}</b></td>
        <td colspan="2">{!! H::img('p7_3') !!} &nbsp;<b style="font-size:15px">{{ $f['room'] ?? '' }}</b></td>
    </tr>
    <tr>
        <td style="padding:2px 0;">{!! H::img('p7_4') !!} <span class="dot">&nbsp;{{ $f['date'] ?? '' }}&nbsp;&nbsp;&nbsp;&nbsp;</span></td>
        <td width="32%">{!! H::img('p7_5') !!} <span class="dot">&nbsp;{{ $f['inning'] ?? '' }}&nbsp;&nbsp;&nbsp;</span></td>
        <td>{!! H::img('p7_6') !!} <span class="dot">&nbsp;{{ $f['paper'] ?? '' }}&nbsp;&nbsp;</span></td>
    </tr>
    <tr>
        <td style="padding:2px 0;">{!! H::img('p7_7') !!} <span class="dot">&nbsp;{{ $f['class'] ?? '' }}&nbsp;&nbsp;&nbsp;&nbsp;</span></td>
        <td>{!! H::img('p7_8') !!} <span class="dot">&nbsp;{{ $f['sem'] ?? '' }}&nbsp;&nbsp;</span></td>
        <td>{!! H::img('p7_9') !!} <span class="dot">&nbsp;{{ $f['subject'] ?? '' }}&nbsp;&nbsp;</span></td>
    </tr>
</table>
