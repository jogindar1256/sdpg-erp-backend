@php
    use App\Support\ExamPrintData as D;
    $rows = [];
    foreach ($papers as $p) foreach ($p->rooms as $room) $rows[] = [$p, $room];
@endphp
<!DOCTYPE html><html><head>@include('pdf.exam._style', ['margin' => '9mm 10mm'])
<style>
    .rp th { border: 1px solid #000; background: #ececec; font-size: 11px; padding: 4px; }
    .rp td { border: 1px solid #000; padding: 6px 5px; font-size: 11px; vertical-align: middle; }
</style></head><body>
<div class="c b" style="font-size:22px;border-bottom:2px solid #000;padding-bottom:4px;">{{ $term }} Exam Seating Plan- &nbsp;{{ $session }}</div>
<div class="c b" style="font-size:16px;margin:6px 0 3px;">{{ $centre['university'] }}</div>
<div style="font-size:10.5px;"><b>Exam Centre Code:-</b> &nbsp;<b style="font-size:13px">{{ $centre['code'] }}</b> &nbsp; <b>Exam Centre:-</b> &nbsp;{{ $centre['place'] }} {{ $centre['pin'] }}</div>
<table width="100%" style="margin:6px 0;"><tr>
    <td class="b" style="font-size:13px;">Exam Date:- &nbsp;{{ D::dmy($date, 'd-M-Y') }}({{ $weekday }})</td>
    <td class="b" style="font-size:12px;">Inning:- <span style="font-weight:normal">{{ $inning->inning_name }}</span></td>
    <td class="b" style="font-size:12px;">Exam Time:- <span style="font-weight:normal">{{ $inning->time_start }}</span> TO <span style="font-weight:normal">{{ $inning->time_end }}</span></td>
</tr></table>
<table width="100%" class="rp">
    <thead><tr><th width="9%">Class</th><th width="9%">Semester</th><th width="17%">Subject</th><th>Roll No. From</th><th>Roll No. Till</th><th width="7%">Total</th><th width="7%">Room</th></tr></thead>
    <tbody>
    @forelse($rows as [$p, $room])
        <tr>
            <td>{{ $p->course }}</td><td class="c">{{ $p->semester }}</td>
            <td><b style="font-size:12px">{{ $p->subject }}</b><div style="font-size:8px">{{ $p->paper_code }}</div></td>
            <td class="b c" style="font-size:12px;">{{ $room->seats->first()->roll_no }}</td>
            <td class="b c" style="font-size:12px;">{{ $room->seats->last()->roll_no }}</td>
            <td class="c">{{ $room->seats->count() }}</td><td class="c b" style="font-size:15px;">{{ $room->room_no }}</td>
        </tr>
    @empty
        <tr><td colspan="7" class="c" style="padding:30px;">No seating plan is saved for this date and inning.</td></tr>
    @endforelse
    </tbody>
</table>
</body></html>
