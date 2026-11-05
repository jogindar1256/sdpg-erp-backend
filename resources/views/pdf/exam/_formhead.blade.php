{{-- University letter-head (P-4 / P-6 / P-7 / P-9 …): emblem · Hindi title · form no. Needs $formNo. --}}
@php use App\Support\HindiAsset as H; @endphp
<table width="100%" style="margin-bottom:2px;"><tr>
    <td width="58" style="vertical-align:middle;">{!! H::logo(52) !!}</td>
    <td style="vertical-align:middle;padding-left:4px;">{!! H::img('title', 1.0) !!}</td>
    <td width="48" class="b r" style="font-size:15px;vertical-align:top;font-family:'DejaVu Sans',sans-serif;">{{ $formNo }}</td>
</tr></table>
