<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * GET /dashboard
     *
     * Query params:
     *   session_year   e.g. "2025-2026"          (optional)
     *   semester_no    1..10 | ""                 (optional)
     *
     * semester_name was removed: a semester number already implies its
     * ODD/EVEN parity, so the two filters were one filter. The valid range
     * comes from semester_masters (seeded 1..10), not from a hardcoded list.
     */
    public function index(Request $request)
    {
        $session      = $request->query('session_year', '');
        $semNo        = $request->query('semester_no', '');                // 1..10|''

        // ── Helper: base query for admissions (joined to programs) ────────────
        $admBase = function () use ($session) {
            $q = DB::table('admissions as a')
                ->join('programs as p', 'p.id', '=', 'a.program_id')
                ->whereNull('a.deleted_at');
            if ($session) $q->where('a.academic_year', $session);
            return $q;
        };

        // ── Semester filter helper (admissions only — real column is semester_no) ──
        $applySemNo = function ($q, $no) {
            if ($no !== '' && $no !== null) return $q->where('a.semester_no', (int)$no);
            return $q;
        };

        $ugLevels = ['UG'];
        // PG group includes BEd per the original dashboard's own comment/labeling.
        $pgLevels = ['PG', 'BEd'];

        // ─────────────────────────────────────────────────────────────────────
        // 1-2. NEW REGISTERED — UG / PG, from direct_registrations.
        // A registration has no semester field — it only exists at all as a
        // "semester 1" event, so an explicit non-1 semester filter zeroes it.
        // Both counts come from one query (COUNT … FILTER).
        // ─────────────────────────────────────────────────────────────────────
        $in = fn (array $values) => implode(', ', array_fill(0, count($values), '?'));
        $ugRegTypes = ['UG'];
        $pgRegTypes = ['PG', 'BED'];

        $ugRegisteredNew = 0;
        $pgRegisteredNew = 0;
        if (!($semNo !== '' && $semNo !== null && (int) $semNo !== 1)) {
            $q = DB::table('direct_registrations')
                ->whereNull('deleted_at')
                ->where('status', '!=', 'cancelled');
            if ($session) $q->where('session_year', $session);
            $regs = $q->selectRaw(
                'COUNT(*) FILTER (WHERE reg_type IN (' . $in($ugRegTypes) . ')) AS ug,
                 COUNT(*) FILTER (WHERE reg_type IN (' . $in($pgRegTypes) . ')) AS pg',
                [...$ugRegTypes, ...$pgRegTypes]
            )->first();
            $ugRegisteredNew = (int) $regs->ug;
            $pgRegisteredNew = (int) $regs->pg;
        }

        // ─────────────────────────────────────────────────────────────────────
        // 3-6. NEW ADMISSION (1st sem) and UPGRADED ADMISSION (UG 3rd & 5th,
        // PG 3rd sem), UG / PG — all four from one query.
        // ─────────────────────────────────────────────────────────────────────
        $adm = $applySemNo($admBase(), $semNo)->selectRaw(
            'COUNT(*) FILTER (WHERE a.semester_no = 1 AND p.level IN (' . $in($ugLevels) . ')) AS ug_new,
             COUNT(*) FILTER (WHERE a.semester_no = 1 AND p.level IN (' . $in($pgLevels) . ')) AS pg_new,
             COUNT(*) FILTER (WHERE a.semester_no IN (3, 5) AND p.level IN (' . $in($ugLevels) . ')) AS ug_upgrade,
             COUNT(*) FILTER (WHERE a.semester_no = 3 AND p.level IN (' . $in($pgLevels) . ')) AS pg_upgrade',
            [...$ugLevels, ...$pgLevels, ...$ugLevels, ...$pgLevels]
        )->first();

        $ugAdmissionNew     = (int) $adm->ug_new;
        $pgAdmissionNew     = (int) $adm->pg_new;
        $ugAdmissionUpgrade = (int) $adm->ug_upgrade;
        $pgAdmissionUpgrade = (int) $adm->pg_upgrade;

        // ─────────────────────────────────────────────────────────────────────
        // 7-9. Gender breakdown — New / Upgraded / Total Admission
        //      Joins with students table to get gender. One grouped query:
        //      new = semester 1, upgraded = any other (non-null) semester,
        //      total = every row.
        // ─────────────────────────────────────────────────────────────────────
        $q = DB::table('admissions as a')
            ->join('students as s', 's.id', '=', 'a.student_id')
            ->whereNull('a.deleted_at');
        if ($session) $q->where('a.academic_year', $session);
        $genderRows = $applySemNo($q, $semNo)
            ->select(
                DB::raw("LOWER(s.personal_info->>'gender') as gender"),
                DB::raw('COUNT(*) FILTER (WHERE a.semester_no = 1) as new_total'),
                DB::raw('COUNT(*) FILTER (WHERE a.semester_no <> 1) as upgraded_total'),
                DB::raw('COUNT(*) as all_total')
            )
            ->groupBy('s.personal_info->gender')
            ->get();

        $genderCount = function (string $column) use ($genderRows) {
            $male   = 0; $female = 0; $trans = 0;
            foreach ($genderRows as $r) {
                $g = strtolower($r->gender ?? '');
                if (str_starts_with($g, 'm'))      $male   += $r->{$column};
                elseif (str_starts_with($g, 'f'))  $female += $r->{$column};
                else                                $trans  += $r->{$column};
            }
            return ['male' => $male, 'female' => $female, 'trans' => $trans, 'total' => $male + $female + $trans];
        };

        $newGender      = $genderCount('new_total');
        $upgradedGender = $genderCount('upgraded_total');
        $allGender      = $genderCount('all_total');

        // ── Sessions list for filter dropdown ─────────────────────────────────
        // Union with registration sessions so a session with registrations but
        // no admission yet is still selectable. UNION de-duplicates in
        // Postgres instead of pulling one value per admission row into PHP.
        $sessions = DB::table('admissions')
            ->whereNotNull('academic_year')
            ->select('academic_year as session')
            ->union(
                DB::table('direct_registrations')
                    ->whereNull('deleted_at')
                    ->whereNotNull('session_year')
                    ->select('session_year as session')
            )
            ->pluck('session')
            ->sortDesc()
            ->values();

        return response()->json([
            'filters' => [
                'session_year'  => $session,
                'semester_no'   => $semNo,
            ],
            'sessions' => $sessions,
            'stats' => [
                // UG
                'ug_registered_new'    => $ugRegisteredNew,
                'ug_admission_new'     => $ugAdmissionNew,
                'ug_admission_upgrade' => $ugAdmissionUpgrade,
                // PG
                'pg_registered_new'    => $pgRegisteredNew,
                'pg_admission_new'     => $pgAdmissionNew,
                'pg_admission_upgrade' => $pgAdmissionUpgrade,
                // Gender
                'new_admission'      => $newGender,
                'upgraded_admission' => $upgradedGender,
                'total_admission'    => $allGender,
            ],
        ]);
    }
}
