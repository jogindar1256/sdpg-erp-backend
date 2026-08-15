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
     *   semester_name  "odd" | "even" | ""        (optional)
     *   semester_no    1-8 | ""                   (optional)
     */
    public function index(Request $request)
    {
        $session      = $request->query('session_year', '');
        $semName      = strtolower($request->query('semester_name', ''));  // odd|even|''
        $semNo        = $request->query('semester_no', '');                // 1-8|''

        // ── Helper: base query for admissions (joined to programs) ────────────
        $admBase = function () use ($session) {
            $q = DB::table('admissions as a')
                ->join('programs as p', 'p.id', '=', 'a.program_id')
                ->whereNull('a.deleted_at');
            if ($session) $q->where('a.academic_year', $session);
            return $q;
        };

        // ── Semester filter helpers (admissions only — real column is semester_no) ──
        $applyOddEven = function ($q, string $name) {
            if ($name === 'odd')  return $q->whereIn('a.semester_no', [1, 3, 5, 7]);
            if ($name === 'even') return $q->whereIn('a.semester_no', [2, 4, 6, 8]);
            return $q;
        };

        $applySemNo = function ($q, $no) {
            if ($no !== '' && $no !== null) return $q->where('a.semester_no', (int)$no);
            return $q;
        };

        $ugLevels = ['UG'];
        // PG group includes BEd per the original dashboard's own comment/labeling.
        $pgLevels = ['PG', 'BEd'];

        $filterLevel = function ($q, array $levels) {
            return $q->whereIn('p.level', $levels);
        };

        // ─────────────────────────────────────────────────────────────────────
        // 1-2. NEW REGISTERED — UG / PG, from direct_registrations.
        // A registration has no semester field — it only exists at all as a
        // "semester 1" event, so an explicit non-1 semester filter zeroes it.
        // ─────────────────────────────────────────────────────────────────────
        $regCount = function (array $regTypes) use ($session, $semName, $semNo) {
            if ($semName === 'even') return 0;
            if ($semNo !== '' && $semNo !== null && (int) $semNo !== 1) return 0;

            $q = DB::table('direct_registrations')
                ->whereNull('deleted_at')
                ->whereIn('reg_type', $regTypes);
            if ($session) $q->where('session_year', $session);
            return $q->count();
        };

        $ugRegisteredNew = $regCount(['UG']);
        $pgRegisteredNew = $regCount(['PG', 'BED']);

        // ─────────────────────────────────────────────────────────────────────
        // 3. NEW ADMISSION — UG (1st sem)
        // ─────────────────────────────────────────────────────────────────────
        $ugAdmissionNew = $filterLevel(
            $applySemNo($applyOddEven($admBase()->where('a.semester_no', 1), $semName), $semNo === '' ? '' : $semNo),
            $ugLevels
        )->count();

        // 4. NEW ADMISSION — PG (1st sem)
        $pgAdmissionNew = $filterLevel(
            $applySemNo($applyOddEven($admBase()->where('a.semester_no', 1), $semName), $semNo === '' ? '' : $semNo),
            $pgLevels
        )->count();

        // ─────────────────────────────────────────────────────────────────────
        // 5. UPGRADED ADMISSION — UG (3rd & 5th sem)
        // ─────────────────────────────────────────────────────────────────────
        $ugAdmissionUpgrade = $filterLevel(
            $applySemNo(
                $applyOddEven($admBase()->whereIn('a.semester_no', [3, 5]), $semName),
                $semNo === '' ? '' : $semNo
            ),
            $ugLevels
        )->count();

        // 6. UPGRADED ADMISSION — PG (3rd sem)
        $pgAdmissionUpgrade = $filterLevel(
            $applySemNo(
                $applyOddEven($admBase()->where('a.semester_no', 3), $semName),
                $semNo === '' ? '' : $semNo
            ),
            $pgLevels
        )->count();

        // ─────────────────────────────────────────────────────────────────────
        // 7-9. Gender breakdown — New / Upgraded / Total Admission
        //      Joins with students table to get gender
        // ─────────────────────────────────────────────────────────────────────
        $genderCount = function (string $type) use ($applyOddEven, $applySemNo, $semName, $semNo, $session) {
            $q = DB::table('admissions as a')
                ->join('students as s', 's.id', '=', 'a.student_id')
                ->whereNull('a.deleted_at');

            if ($session) $q->where('a.academic_year', $session);

            // type filter
            if ($type === 'new')      $q->where('a.semester_no', 1);
            if ($type === 'upgraded') $q->whereNotIn('a.semester_no', [1]);

            // semester filters
            $q = $applyOddEven($q, $semName);
            $q = $applySemNo($q, $semNo);

            $rows = $q->select(
                DB::raw("LOWER(s.gender) as gender"),
                DB::raw("COUNT(*) as total")
            )
            ->groupBy('s.gender')
            ->get();

            $male   = 0; $female = 0; $trans = 0;
            foreach ($rows as $r) {
                $g = strtolower($r->gender ?? '');
                if (str_starts_with($g, 'm'))      $male   += $r->total;
                elseif (str_starts_with($g, 'f'))  $female += $r->total;
                else                                $trans  += $r->total;
            }
            return ['male' => $male, 'female' => $female, 'trans' => $trans, 'total' => $male + $female + $trans];
        };

        $newGender      = $genderCount('new');
        $upgradedGender = $genderCount('upgraded');
        $allGender      = $genderCount('all');

        // ── Sessions list for filter dropdown ─────────────────────────────────
        $sessions = DB::table('admissions')
            ->whereNotNull('academic_year')
            ->distinct()
            ->orderByDesc('academic_year')
            ->pluck('academic_year');

        return response()->json([
            'filters' => [
                'session_year'  => $session,
                'semester_name' => $semName,
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
