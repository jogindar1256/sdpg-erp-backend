<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything the exam-conduct print forms need for one exam date + inning:
 * the schedule, the seated roll numbers (room by room), enrolled / absent /
 * UFM counts and the saved P1 / P9 values. One query set, shared by every form,
 * so Single and All prints always agree with each other and with the screens.
 */
class ExamPrintData
{
    public static function ordinal(string|int|null $n): string
    {
        $n = (int) $n;
        if ($n <= 0) return '';
        $s = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : [1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th';
        return $n . $s;
    }

    public static function dmy(?string $d, string $fmt = 'd/m/Y'): string
    {
        return $d ? Carbon::parse($d)->format($fmt) : '';
    }

    public static function centre(): array
    {
        $org = OrgBrand::current();
        $place = collect([$org->name ?? '', $org->city ?? '', $org->district ?? ''])->map(fn ($v) => trim((string) $v))->filter()->unique()->implode(', ');
        return [
            'code' => OrgBrand::collegeCode($org) ?: ($org->code ?? ''),
            'name' => trim((string) ($org->name ?? '')),
            'place' => $place,
            'pin' => trim((string) ($org->pin_code ?? '')),
            // "Name, City, District-274502" as written in the Hindi forms' centre-name field
            'full' => $place . (trim((string) ($org->pin_code ?? '')) !== '' ? '-' . trim((string) $org->pin_code) : ''),
            'address' => OrgBrand::fullAddress($org),
            'university' => trim((string) ($org->university_name ?? '')) ?: 'Deen Dayal Upadhyay Gorakhpur University Gorakhpur',
        ];
    }

    /**
     * Adds ->name, ->father and ->photo (data URI) to every seat row.
     * Photos are read from storage once per student; the caller raises the time limit.
     */
    private static function attachStudents($seatGroups): void
    {
        $seats = $seatGroups->flatten(1);
        $ids = $seats->pluck('student_id')->unique()->values()->all();
        if (!$ids) return;

        $latestReg = DB::table('direct_registrations')->select('user_id', DB::raw('MAX(id) as reg_id'))->whereNull('deleted_at')->groupBy('user_id');
        $people = DB::table('students as s')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->whereIn('s.id', $ids)
            ->get(['s.id', 'dr.name as reg_name', 'dr.father_name',
                DB::raw("s.personal_info->>'first_name' as first_name"), DB::raw("s.personal_info->>'middle_name' as middle_name"), DB::raw("s.personal_info->>'last_name' as last_name")])
            ->keyBy('id');

        $appByStudent = DB::table('admissions')->whereIn('student_id', $ids)->whereNull('deleted_at')->orderBy('id')->pluck('application_id', 'student_id');
        $photoByApp = DB::table('student_application_documents')->whereIn('application_id', $appByStudent->filter()->values()->all())
            ->where('document_type', 'photo')->get()->keyBy('application_id');

        $photoOf = function (?object $doc): ?string {
            if (!$doc || empty($doc->path)) return null;
            $disks = array_values(array_unique(array_filter([$doc->disk ?? null, 'supabase', 'public'])));
            foreach ($disks as $disk) {
                try {
                    $bytes = \Illuminate\Support\Facades\Storage::disk($disk)->get($doc->path);
                    if ($bytes !== null && $bytes !== '') {
                        return 'data:' . ((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/jpeg') . ';base64,' . base64_encode($bytes);
                    }
                } catch (\Throwable $e) {
                    continue; // disk missing / file not there — try the next one
                }
            }
            return null;
        };

        $cache = [];
        foreach ($ids as $id) {
            $p = $people->get($id);
            $name = trim((string) ($p->reg_name ?? '')) ?: trim(implode(' ', array_filter([$p->first_name ?? null, $p->middle_name ?? null, $p->last_name ?? null])));
            $cache[$id] = ['name' => $name, 'father' => trim((string) ($p->father_name ?? '')), 'photo' => $photoOf($photoByApp->get($appByStudent->get($id)))];
        }
        foreach ($seats as $seat) {
            $c = $cache[$seat->student_id] ?? [];
            $seat->name = $c['name'] ?? ''; $seat->father = $c['father'] ?? ''; $seat->photo = $c['photo'] ?? null;
        }
    }

    /** @return array{session:string,date:string,inning:object,centre:array,term:string,papers:array,weekday:string} */
    public static function load(string $session, string $date, int $inningId, ?string $paper = null, ?int $roomId = null, bool $withStudents = false): array
    {
        $inning = DB::table('exam_innings')->where('id', $inningId)->first();
        $inningName = $inning->inning_name ?? '';

        $schedules = DB::table('exam_schedules as es')
            ->join('programs as p', 'p.id', 'es.program_id')
            ->join('subjects as sub', 'sub.id', 'es.subject_id')
            ->where('es.session_year', $session)->where('es.exam_date', $date)->where('es.inning', $inningName)
            ->when($paper, fn ($q, $v) => $q->whereRaw('upper(trim(es.paper_code)) = ?', [strtoupper(trim($v))]))
            ->orderBy('p.short_name')->orderBy('es.semester_no')->orderBy('es.paper_code')
            ->get(['es.id', 'es.program_id', 'es.semester_no', 'es.exam_mode', 'es.paper_code', 'p.short_name as course', 'sub.name as subject']);

        $seats = DB::table('exam_seating as es')
            ->join('students as s', 's.id', 'es.student_id')
            ->join('exam_rooms as r', 'r.id', 'es.room_id')
            ->where('es.session_year', $session)->where('es.exam_date', $date)->where('es.inning_id', $inningId)
            ->when($roomId, fn ($q, $v) => $q->where('es.room_id', $v))
            ->orderBy('es.seat_no')
            ->get(['es.student_id', 'es.paper_code', 'es.seat_no', 'es.room_id', 'r.room_no', 'r.building_name', 's.university_roll_no as roll_no', DB::raw("s.personal_info->>'gender' as gender")])
            ->groupBy(fn ($x) => strtoupper(trim($x->paper_code)));

        if ($withStudents) self::attachStudents($seats);

        $mark = fn (string $t) => DB::table($t)->where('session_year', $session)->where('exam_date', $date)->where('inning_id', $inningId)
            ->orderBy('roll_no')->get(['paper_code', 'roll_no', 'room_id'])->groupBy(fn ($x) => strtoupper(trim($x->paper_code)));
        $absent = $mark('exam_absent');
        $ufm = $mark('exam_ufm');

        $centre = self::centre();
        $saved = fn (string $t) => collect(json_decode((string) (DB::table($t)->where([
            'session_year' => $session, 'exam_date' => $date, 'inning_id' => $inningId, 'center_code' => $centre['code'],
        ])->value('lines') ?? '[]'), true) ?: [])->keyBy(fn ($l) => strtoupper(trim($l['paper_code'] ?? '')));
        $p1 = $saved('exam_conduct_p1');
        $p9 = $saved('exam_packets');

        $papers = $schedules->map(function ($sch) use ($session, $seats, $absent, $ufm, $p1, $p9) {
            $key = strtoupper(trim($sch->paper_code));
            $q = DB::table('exam_form_papers as efp')
                ->join('exam_forms as ef', 'ef.id', 'efp.exam_form_id')
                ->join('admissions as a', 'a.id', 'ef.admission_id')
                ->join('students as s', 's.id', 'a.student_id')
                ->whereRaw('upper(trim(efp.paper_code)) = ?', [$key])
                ->where('ef.session_year', $session)->where('ef.semester_no', $sch->semester_no)
                ->where('a.program_id', $sch->program_id)->where('ef.status', 'Accepted')
                ->whereIn('efp.exam_type', ['Regular', 'Back Paper']);
            $enrolled = (clone $q)->distinct()->count('s.id');
            $back = (clone $q)->where('efp.exam_type', 'Back Paper')->distinct()->orderBy('s.university_roll_no')->pluck('s.university_roll_no')->filter()->values()->all();

            $mine = $seats->get($key, collect());
            return (object) [
                'paper_code' => $sch->paper_code, 'course' => $sch->course, 'semester_no' => (string) $sch->semester_no,
                'semester' => self::ordinal($sch->semester_no), 'subject' => $sch->subject, 'exam_mode' => $sch->exam_mode,
                'enrolled' => $enrolled, 'back_rolls' => $back,
                'seats' => $mine,
                'rooms' => $mine->groupBy('room_id')->map(fn ($g) => (object) ['room_id' => $g[0]->room_id, 'room_no' => $g[0]->room_no, 'building' => $g[0]->building_name, 'seats' => $g->values()])
                    ->sortBy(fn ($r) => $r->room_no, SORT_NATURAL)->values(),
                'absent' => ($absent->get($key) ?? collect())->pluck('roll_no')->all(),
                'absent_by_room' => ($absent->get($key) ?? collect())->groupBy('room_id')->map->count()->all(),
                'ufm' => ($ufm->get($key) ?? collect())->pluck('roll_no')->all(),
                'ufm_by_room' => ($ufm->get($key) ?? collect())->groupBy('room_id')->map->count()->all(),
                'p1' => $p1->get($key),
                'p9' => $p9->get($key),
            ];
        })->values()->all();

        $first = $papers[0]->semester_no ?? null;
        return [
            'session' => $session, 'date' => $date, 'inning' => $inning, 'centre' => $centre,
            'weekday' => Carbon::parse($date)->format('l'),
            'term' => ($first && ((int) $first) % 2 === 0) ? 'Even Semester' : 'Odd Semester',
            'papers' => $papers,
        ];
    }
}
