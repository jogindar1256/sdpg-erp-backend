{{-- P1: daily answer-book usage sheet, one form per ROOM of the exam date + inning.
     $mode: all = everything filled · received = only the returned (वापस) column · blank = empty form --}}
@php
    use App\Support\ExamPrintData as D;
    use App\Support\HindiAsset as H;
    $formNo = 'P1';
    $blank = $mode === 'blank';

    // room => [[paper, room entry], ...] (papers seated in that room)
    $forms = [];
    if ($blank) {
        $forms[] = ['room_no' => '', 'items' => []];
    } else {
        $byRoom = [];
        foreach ($papers as $p) foreach ($p->rooms as $room) $byRoom[$room->room_no][] = [$p, $room];
        uksort($byRoom, 'strnatcmp');
        foreach ($byRoom as $no => $items) $forms[] = ['room_no' => $no, 'items' => $items];
    }
@endphp
<!DOCTYPE html><html><head>@include('pdf.exam._style', ['margin' => '9mm 12mm'])
<style>
    .f td { font-size: 11px; padding: 4px 0; vertical-align: bottom; }
    .v { border-bottom: 1px dotted #000; font-weight: bold; padding: 0 4px; }
    .t td, .t th { border: 1px solid #000; }
    .t th { font-weight: normal; padding: 5px 4px; text-align: center; }
    .t td { height: 21px; font-size: 10.5px; padding: 0 5px; }
</style></head><body>
@foreach($forms as $fi => $form)
    @php
        $items = $form['items'];
        $saved = fn ($p, $room) => collect($p->p1['rooms'] ?? [])->firstWhere('room_id', $room->room_id);
        $absRows = collect($items)->map(fn ($it) => [$it[0], collect($it[0]->absent_rolls_by_room[$it[1]->room_id] ?? [])])->filter(fn ($r) => $r[1]->count());
    @endphp
    <div class="page {{ $fi === count($forms) - 1 ? 'last' : '' }}">
        @include('pdf.exam._formhead')

        <table width="100%" class="f" style="margin:8px 0 0 12px;width:96%;">
            <tr>
                <td width="70%">{!! H::img('p1_centre') !!} <b style="font-size:11.5px;">({{ $centre['code'] }}) {{ $centre['name'] }}</b></td>
                <td>{!! H::img('p1_room') !!} <span class="v">&nbsp;<b style="font-size:13px">{{ $form['room_no'] }}</b>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span></td>
            </tr>
        </table>
        <table width="100%" class="f" style="margin:0 0 0 12px;width:96%;">
            <tr>
                <td width="34%">{!! H::img('p1_group') !!} <span class="v">&nbsp;{{ $blank ? '' : $term . ' ' . $session }}&nbsp;</span></td>
                <td width="40%">{!! H::img('p1_time') !!} <span class="v">&nbsp;{{ $blank ? '' : $inning->inning_name . ' ' . $inning->time_start . '-' . $inning->time_end }}&nbsp;</span></td>
                <td>{!! H::img('p1_date') !!} <span class="v">&nbsp;{{ $blank ? '' : D::dmy($date) }}&nbsp;&nbsp;&nbsp;</span></td>
            </tr>
        </table>

        <div class="c" style="margin:8px 0 6px;">{!! H::img('p1_h1') !!}</div>
        <table width="100%" class="t">
            <tr>
                <th rowspan="2" width="24%">{!! H::img('p1_exam') !!}</th><th rowspan="2" width="38%">{!! H::img('p1_subcode') !!}</th>
                <th colspan="3">{!! H::img('p1_omr') !!}</th>
            </tr>
            <tr><th width="12.6%">{!! H::img('p1_filled') !!}</th><th width="12.6%">{!! H::img('p1_used') !!}</th><th width="12.6%">{!! H::img('p1_ret') !!}</th></tr>
            @foreach($items as [$p, $room])
                @php
                    $s = $saved($p, $room);
                    $abs = $p->absent_by_room[$room->room_id] ?? 0;
                    $used = max(0, $room->seats->count() - $abs);
                @endphp
                <tr>
                    <td>{{ $p->course }} Sem {{ $p->semester_no }}</td><td>{{ $p->subject }} - {{ $p->paper_code }}</td>
                    <td class="c">{{ $mode === 'received' ? '' : ($s['copy_in_room'] ?? '') }}</td>
                    <td class="c">{{ $mode === 'received' ? '' : $used }}</td>
                    <td class="c">{{ $s['received_copy'] ?? '' }}</td>
                </tr>
            @endforeach
            @for($i = count($items); $i < 4; $i++)<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>@endfor
        </table>

        <div class="c" style="margin:26px 0 6px;">{!! H::img('p1_h2') !!}</div>
        <table width="100%" class="t">
            <tr><th width="19%">{!! H::img('p1_exam') !!}</th><th width="25%">{!! H::img('p1_subcode') !!}</th><th>{!! H::img('p1_roll') !!}</th><th width="8%">{!! H::img('p1_sum') !!}</th></tr>
            @foreach($absRows as [$p, $rolls])
                <tr>
                    <td>{{ $p->course }} Sem {{ $p->semester_no }}</td><td>{{ $p->subject }} - {{ $p->paper_code }}</td>
                    <td style="font-size:10px;line-height:1.5;padding:3px 5px;">{{ $mode === 'received' ? '' : $rolls->implode(',  ') }}</td>
                    <td class="c">{{ $mode === 'received' ? '' : $rolls->count() }}</td>
                </tr>
            @endforeach
            @for($i = $absRows->count(); $i < 9; $i++)<tr><td>&nbsp;</td><td></td><td></td><td></td></tr>@endfor
        </table>

        <div style="margin:22px 0 0 8px;">{!! H::img('p1_other') !!} <span class="dot">.................................................................................</span></div>
        <table style="margin:20px 0 0 8px;font-size:10px;" class="b">
            <tr><td style="vertical-align:top;padding-right:6px;">{!! H::img('p1_sign') !!}</td><td>
                1:-<span style="font-weight:normal"> .....................................................</span><br><br>
                2:-<span style="font-weight:normal"> .....................................................</span><br><br>
                3:-<span style="font-weight:normal"> .....................................................</span></td></tr>
        </table>
        <div class="r" style="margin:16px 14px 0 0;">{!! H::img('p1_cs') !!}</div>
    </div>
@endforeach
</body></html>
