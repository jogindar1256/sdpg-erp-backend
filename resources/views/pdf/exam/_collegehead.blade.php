{{-- College / centre letter-head for the college-level lists (accept list, form-ID list, marksheet distribution, result update …).
     Everything comes from the Institute Profile — nothing is typed in here. Optional: $title (line under the head). --}}
@php
    $org = \App\Support\OrgBrand::current();
    $chLogo = \App\Support\OrgBrand::logoDataUri($org);
    $chUniv = trim((string) ($org->university_name ?? '')) ?: 'Deen Dayal Upadhyay Gorakhpur University, Gorakhpur';
    $chCode = \App\Support\OrgBrand::collegeCode($org) ?: ($org->code ?? '');
@endphp
<table width="100%" style="margin-bottom:0;"><tr>
    <td width="70" style="vertical-align:middle;">@if($chLogo)<img src="{{ $chLogo }}" height="62" alt="">@endif</td>
    <td class="c" style="vertical-align:middle;">
        <div class="b" style="font-size:30px;line-height:1.05;letter-spacing:0.5px;">{{ \App\Support\OrgBrand::name($org) }}</div>
        <div style="font-size:12.5px;">{{ \App\Support\OrgBrand::fullAddress($org) }}</div>
        <div style="font-size:12.5px;"><b>Affiliated: -</b> {{ $chUniv }}.</div>
        <div style="font-size:14px;"><b>College Code: -</b> {{ $chCode }}</div>
    </td>
    <td width="70"></td>
</tr></table>
<div style="height:4px;margin:3px 18px 6px;background:#3f6fa6;border-radius:50%;"></div>
@isset($title)<div class="c b" style="font-size:14px;margin-bottom:4px;">{{ $title }}</div>@endisset
