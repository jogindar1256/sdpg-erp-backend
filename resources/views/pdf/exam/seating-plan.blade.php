@php
    use App\Support\ExamPrintData as D;
    $cols = 6; $perCol = 10; $perPage = $cols * $perCol;
    $groups = [];
    foreach ($papers as $p) foreach ($p->rooms as $room) $groups[] = [$p, $room];
@endphp
<!DOCTYPE html><html><head>@include('pdf.exam._style', ['margin' => '8mm 9mm'])
<style>
    .cell { width: 16.66%; height: 60px; border: 1px solid #000; vertical-align: top; padding: 3px 5px; }
    .cell .roll { font-size: 11.5px; font-weight: bold; }
    .cell .sm { font-size: 9px; }
    .band td { border: 1px solid #000; background: #ececec; font-size: 9.5px; padding: 3px 5px; }
</style></head><body>
@forelse($groups as $gi => [$p, $room])
    @php $chunks = $room->seats->chunk($perPage); @endphp
    @foreach($chunks as $ci => $chunk)
        @php $isLast = ($gi === count($groups) - 1) && ($ci === $chunks->count() - 1); @endphp
        <div class="page {{ $isLast ? 'last' : '' }}">
            <table width="100%"><tr>
                <td style="vertical-align:top;">
                    <div class="c b" style="font-size:19px;">{{ $centre['university'] }}</div>
                    <div class="c b" style="font-size:14px;">{{ $term }} Exam Year-{{ $session }}</div>
                    <div class="b" style="font-size:11px;border-top:1px solid #000;margin-top:5px;padding-top:4px;">Exam Center :- ({{ $centre['code'] }}) <span style="font-weight:normal">{{ $centre['place'] }}</span></div>
                    <table width="100%" style="margin-top:3px;"><tr>
                        <td class="b" style="font-size:12px;">Exam Date:- &nbsp;{{ D::dmy($date, 'd-M-Y') }}</td>
                        <td class="b" style="font-size:11px;">Inning:- <span style="font-weight:normal">{{ $inning->inning_name }}</span></td>
                        <td class="b" style="font-size:11px;">Timing:- <span style="font-weight:normal">{{ $inning->time_start }}</span> To <span style="font-weight:normal">{{ $inning->time_end }}</span></td>
                    </tr></table>
                </td>
                <td width="70" class="c" style="font-size:54px;font-weight:bold;vertical-align:top;">{{ $room->room_no }}</td>
            </tr></table>
            <table width="100%" class="band" style="margin:4px 0 6px;"><tr>
                <td width="22%"><b>Class:-</b> {{ $p->course }}</td>
                <td width="22%"><b>Semester:-</b> {{ $p->semester }}</td>
                <td><b>Subjects :-</b> {{ $p->subject }}</td>
                <td width="22%"><b>Paper Code :-</b> {{ $p->paper_code }}</td>
            </tr></table>

            <table width="100%" style="table-layout:fixed;">
                @for($r = 0; $r < $perCol; $r++)
                    <tr>
                    @for($c = 0; $c < $cols; $c++)
                        @php $s = $chunk->values()->get($c * $perCol + $r); @endphp
                        <td class="cell">
                            @if($s)
                                <div class="roll">{{ $s->roll_no }}</div>
                                <div class="sm b">{{ $p->course }}</div>
                                <div class="sm">{{ $p->paper_code }}</div>
                                <div class="sm b r">{{ ucfirst((string) $s->gender) }}</div>
                            @endif
                        </td>
                    @endfor
                    </tr>
                @endfor
            </table>

            <table width="100%" style="margin-top:6px;" class="grid">
                <tr style="background:#ececec;font-size:10px;"><th width="14%">Total</th><th width="18%">Subjects</th><th width="10%">Students</th><th>Absents Roll No.</th><th width="9%">Present</th></tr>
                <tr style="font-size:9.5px;height:34px;">
                    <td style="padding:3px 5px;vertical-align:top;">{{ $p->course }}</td>
                    <td style="padding:3px 5px;vertical-align:top;">{{ $p->subject }}</td>
                    <td class="c" style="vertical-align:top;padding-top:3px;">{{ $room->seats->count() }}</td><td></td><td></td>
                </tr>
            </table>
        </div>
    @endforeach
@empty
    <p class="c" style="margin-top:80px;font-size:14px;">No seating plan is saved for this date and inning.</p>
@endforelse
</body></html>
