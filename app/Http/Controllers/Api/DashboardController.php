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

        // ── Helper: base query for registrations ──────────────────────────────
        $regBase = function () use ($session) {
            $q = DB::table('registrations')
                ->whereNull('deleted_at');
            if ($session) $q->where('session_year', $session);
            return $q;
        };

        // ── Helper: base query for admissions ────────────────────────────────
        $admBase = function () use ($session) {
            $q = DB::table('admissions')
                ->whereNull('deleted_at');
            if ($session) $q->where('session_year', $session);
            return $q;
        };

        // ── Semester filter helpers ───────────────────────────────────────────
        $applyOddEven = function ($q, string $name) {
            if ($name === 'odd')  return $q->whereIn('semester', [1, 3, 5, 7]);
            if ($name === 'even') return $q->whereIn('semester', [2, 4, 6, 8]);
            return $q;
        };

        $applySemNo = function ($q, $no) {
            if ($no !== '' && $no !== null) return $q->where('semester', (int)$no);
            return $q;
        };

        // UG programs: BA, BSC (semester 1 = new; 3,5 = upgraded)
        $ugPrograms = ['BA', 'B.A', 'B.A.', 'BSC', 'B.Sc', 'B.Sc.', 'B.SC', 'UG'];
        // PG programs: MA, MSC, BED (semester 1 = new; 3 = upgraded)
        $pgPrograms = ['MA', 'M.A', 'M.A.', 'MSC', 'M.Sc', 'M.Sc.', 'BED', 'B.Ed', 'B.Ed.', 'B.ED', 'PG'];

        $filterLevel = function ($q, array $levels) {
            return $q->where(function ($inner) use ($levels) {
                foreach ($levels as $i => $lvl) {
                    $method = $i === 0 ? 'where' : 'orWhere';
                    $inner->{$method}('program_code', 'ILIKE', $lvl . '%');
                }
            });
        };

        // ─────────────────────────────────────────────────────────────────────
        // 1. NEW REGISTERED — UG (1st sem)
        // ─────────────────────────────────────────────────────────────────────
        $ugRegisteredNew = $filterLevel(
            $applySemNo($applyOddEven($regBase()->where('semester', 1), $semName), $semNo === '' ? '' : $semNo),
            $ugPrograms
        )->count();

        // 2. NEW REGISTERED — PG (1st sem)
        $pgRegisteredNew = $filterLevel(
            $applySemNo($applyOddEven($regBase()->where('semester', 1), $semName), $semNo === '' ? '' : $semNo),
            $pgPrograms
        )->count();

        // ─────────────────────────────────────────────────────────────────────
        // 3. NEW ADMISSION — UG (1st sem)
        // ─────────────────────────────────────────────────────────────────────
        $ugAdmissionNew = $filterLevel(
            $applySemNo($applyOddEven($admBase()->where('semester', 1), $semName), $semNo === '' ? '' : $semNo),
            $ugPrograms
        )->count();

        // 4. NEW ADMISSION — PG (1st sem)
        $pgAdmissionNew = $filterLevel(
            $applySemNo($applyOddEven($admBase()->where('semester', 1), $semName), $semNo === '' ? '' : $semNo),
            $pgPrograms
        )->count();

        // ─────────────────────────────────────────────────────────────────────
        // 5. UPGRADED ADMISSION — UG (3rd & 5th sem)
        // ─────────────────────────────────────────────────────────────────────
        $ugAdmissionUpgrade = $filterLevel(
            $applySemNo(
                $applyOddEven($admBase()->whereIn('semester', [3, 5]), $semName),
                $semNo === '' ? '' : $semNo
            ),
            $ugPrograms
        )->count();

        // 6. UPGRADED ADMISSION — PG (3rd sem)
        $pgAdmissionUpgrade = $filterLevel(
            $applySemNo(
                $applyOddEven($admBase()->where('semester', 3), $semName),
                $semNo === '' ? '' : $semNo
            ),
            $pgPrograms
        )->count();

        // ─────────────────────────────────────────────────────────────────────
        // 7-9. Gender breakdown — New / Upgraded / Total Admission
        //      Joins with students table to get gender
        // ─────────────────────────────────────────────────────────────────────
        $genderCount = function (string $type) use ($admBase, $applyOddEven, $applySemNo, $semName, $semNo) {
            $q = DB::table('admissions as a')
                ->join('students as s', 's.id', '=', 'a.student_id')
                ->whereNull('a.deleted_at');

            // type filter
            if ($type === 'new')      $q->where('a.semester', 1);
            if ($type === 'upgraded') $q->whereNotIn('a.semester', [1]);

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
            ->whereNotNull('session_year')
            ->distinct()
            ->orderByDesc('session_year')
            ->pluck('session_year');

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
