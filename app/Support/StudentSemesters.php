<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A student's semester-by-semester picture, built from their applications
 * (regular / semester_upgrade / back_paper) — used by the registration
 * dashboard cards and the Educational History table.
 *
 * Exam results (CGPA, marks, result, division) don't exist yet; those fields
 * come back null until the exam module writes them.
 */
class StudentSemesters
{
    private const CYCLE_SHORT = ['JUL_DEC' => 'July-December', 'JAN_JUN' => 'January-June'];

    /** All of a user's applications with program, receipt and admission state. */
    /**
     * Every application's paid admission/semester-upgrade/back-paper
     * payment, if any — one row per application, latest paid first. Used to
     * reconstruct fee_paid/paid_at/receipt_no (no longer stored columns)
     * in applications() below without touching every call site.
     */
    private static function paidPaymentsByApplication(): \Illuminate\Database\Query\Builder
    {
        return DB::table('payments')
            ->select('student_application_id', DB::raw('MAX(id) as id'))
            ->whereIn('purpose', ['admission', 'semester_upgrade', 'back_paper'])
            ->where('status', 'paid')
            ->groupBy('student_application_id');
    }

    public static function applications(object $user)
    {
        $studentId = DB::table('students')->where('user_id', $user->id)->value('id');

        return DB::table('student_applications as sa')
            ->join('programs as p', 'p.id', 'sa.program_id')
            ->leftJoinSub(self::paidPaymentsByApplication(), 'pp', 'pp.student_application_id', 'sa.id')
            ->leftJoin('payments as pay', 'pay.id', 'pp.id')
            ->leftJoin('fee_receipts as fr', 'fr.id', 'pay.fee_receipt_id')
            ->leftJoin('admissions as a', 'a.application_id', 'sa.id')
            ->whereNull('sa.deleted_at')
            ->where(function ($q) use ($user, $studentId) {
                $q->where('sa.user_id', $user->id);
                if ($studentId) {
                    $q->orWhere('sa.student_id', $studentId);
                }
            })
            ->select(
                'sa.id', 'sa.application_no', 'sa.application_type', 'sa.academic_year', 'sa.semester_no',
                'sa.status', 'sa.created_at', 'sa.program_id',
                DB::raw('(pay.id is not null) as fee_paid'),
                'pay.paid_at as paid_at',
                'p.short_name', 'p.full_name', 'p.samarth_code', 'p.total_semesters',
                'fr.receipt_no', 'fr.is_verified as receipt_verified',
                'a.status as admission_status', 'a.admission_no'
            )
            ->orderBy('sa.id')
            ->get()
            ->map(function ($row) {
                $row->fee_paid = (bool) $row->fee_paid; // normalize driver bool/'t'/'f'
                return $row;
            });
    }

    public static function cycleOf(int $semester): ?string
    {
        static $cycles = null;
        $cycles ??= DB::table('semester_masters')->pluck('academic_cycle', 'semester_num')->all();
        $c = $cycles[$semester] ?? ($semester % 2 ? 'JUL_DEC' : 'JAN_JUN');
        return self::CYCLE_SHORT[$c] ?? $c;
    }

    /** One step label for an application. */
    public static function stage(object $a): array
    {
        if ($a->admission_status === 'active') return ['admitted', 'Admitted'];
        if ($a->admission_status === 'cancelled' || $a->status === 'cancelled') return ['cancelled', 'Cancelled'];
        return match ($a->status) {
            'draft' => ['draft', $a->application_type === 'back_paper' ? 'Form In Progress / Fee Due' : 'Form In Progress'],
            'submitted', 'under_review' => ['review', 'Under Verification'],
            'on_hold' => ['hold', 'On Hold'],
            'rejected' => ['rejected', 'Rejected'],
            'approved' => $a->fee_paid ? ['fee_paid', 'Fee Paid — Receipt Verification'] : ['pay', 'Approved — Pay Fee'],
            default => ['other', ucfirst((string) $a->status)],
        };
    }

    /**
     * Semester cards 1..total_semesters for the student's course.
     * @return array{program: ?array, semesters: array}
     */
    public static function cards(object $user, ?int $fallbackProgramId = null): array
    {
        $apps = self::applications($user);
        $main = $apps->whereIn('application_type', ['regular', 'semester_upgrade']);
        $programId = $main->last()->program_id ?? $fallbackProgramId;
        if (!$programId) {
            return ['program' => null, 'semesters' => []];
        }
        $program = DB::table('programs')->where('id', $programId)->first(['id', 'short_name', 'full_name', 'samarth_code', 'total_semesters']);
        $total = max(1, (int) ($program->total_semesters ?? 1));

        $cards = [];
        $reached = 0; // highest semester with an application
        for ($n = 1; $n <= $total; $n++) {
            $app = $main->where('program_id', $programId)->where('semester_no', $n)
                ->reject(fn($a) => in_array($a->status, ['cancelled'], true))->last()
                ?? $main->where('program_id', $programId)->where('semester_no', $n)->last();
            $backPapers = $apps->where('application_type', 'back_paper')->where('semester_no', $n)
                ->map(fn($b) => [
                    'application_id' => $b->id,
                    'application_no' => $b->application_no,
                    'session' => $b->academic_year,
                    'stage' => self::stage($b)[1],
                    'fee_paid' => (bool) $b->fee_paid,
                ])->values()->all();

            if ($app) {
                $reached = $n;
                [$key, $label] = self::stage($app);
                $cards[] = [
                    'semester' => $n,
                    'academic_cycle' => self::cycleOf($n),
                    'state' => $key,
                    'label' => $label,
                    'application_id' => $app->id,
                    'application_no' => $app->application_no,
                    'application_type' => $app->application_type,
                    'session' => $app->academic_year,
                    'fee_paid' => (bool) $app->fee_paid,
                    'paid_at' => $app->paid_at,
                    'receipt_no' => $app->receipt_no,
                    'admission_no' => $app->admission_no,
                    'applied_at' => $app->created_at,
                    'back_papers' => $backPapers,
                ];
            } else {
                $cards[] = [
                    'semester' => $n,
                    'academic_cycle' => self::cycleOf($n),
                    // The next semester after the last one applied for is
                    // the one that opens next; later ones are upcoming.
                    'state' => $n === $reached + 1 ? 'next' : 'upcoming',
                    'label' => $n === 1 ? 'Not Started' : ($n === $reached + 1 ? 'Opens After Previous Semester' : 'Upcoming'),
                    'application_id' => null,
                    'back_papers' => $backPapers,
                ];
            }
        }

        return [
            'program' => $program ? (array) $program : null,
            'semesters' => $cards,
        ];
    }

    /** Educational History rows: every semester / back paper the fee was paid for. */
    public static function history(object $user): array
    {
        $roll = DB::table('students')->where('user_id', $user->id)->value('university_roll_no');
        $ordinal = fn(int $n) => $n . (in_array($n % 100, [11, 12, 13], true) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th'));

        return self::applications($user)
            ->filter(fn($a) => ($a->fee_paid || $a->admission_status === 'active')
                && !in_array($a->status, ['rejected', 'cancelled'], true)
                && $a->admission_status !== 'cancelled')
            ->sortBy(fn($a) => sprintf('%s-%02d-%08d', $a->academic_year, $a->semester_no, $a->id))
            ->map(fn($a) => [
                'application_id' => $a->id,
                'course' => trim(($a->samarth_code ? $a->samarth_code . '-' : '') . $a->short_name),
                'semester' => (int) $a->semester_no,
                'semester_label' => $ordinal((int) $a->semester_no),
                'academic_cycle' => self::cycleOf((int) $a->semester_no),
                'session' => $a->academic_year,
                'exam_type' => $a->application_type === 'back_paper' ? 'Back Paper' : 'Regular',
                'roll_no' => $roll,
                'application_no' => $a->application_no,
                'confirmed' => $a->admission_status === 'active' || ($a->application_type === 'back_paper' && $a->fee_paid),
                // Filled by the exam module.
                'cgpa' => null,
                'full_marks' => null,
                'result' => null,
                'division' => null,
            ])
            ->values()
            ->all();
    }
}
