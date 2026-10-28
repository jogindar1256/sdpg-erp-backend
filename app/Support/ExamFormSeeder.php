<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Puts a verified (active) admission in front of the examination office:
 * one Regular exam form (status Pending) per admission + session + semester,
 * with the papers of the subjects the student chose. Accept Exam Form lists
 * these; nothing here touches students / admissions.
 *
 * Existing tables only: exam_forms, exam_form_papers (migration
 * 2026_07_20_110000_create_examination_tables).
 */
class ExamFormSeeder
{
    /** Create the exam form for one admission if it has none. Never throws. */
    public static function ensureForAdmission(int $admissionId): ?int
    {
        try {
            $a = DB::table('admissions')->where('id', $admissionId)->whereNull('deleted_at')->first();
            if (!$a || $a->status !== 'active' || !$a->student_id || !$a->academic_year) {
                return null;
            }
            $sem = (string) ($a->semester_no ?: 1);

            $formId = DB::table('exam_forms')
                ->where('admission_id', $a->id)
                ->where('session_year', $a->academic_year)
                ->where('semester_no', $sem)
                ->where('exam_type', 'Regular')
                ->value('id');

            if (!$formId) {
                $formId = DB::table('exam_forms')->insertGetId([
                    'admission_id' => $a->id,
                    'session_year' => $a->academic_year,
                    'semester_no' => $sem,
                    'exam_type' => 'Regular',
                    'status' => 'Pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (!DB::table('exam_form_papers')->where('exam_form_id', $formId)->exists()) {
                self::seedPapers((int) $formId, $a, $sem);
            }

            return (int) $formId;
        } catch (\Throwable $e) {
            Log::warning("ExamFormSeeder: admission {$admissionId}: " . $e->getMessage());
            return null;
        }
    }

    /** Seed every active admission of a session / course / semester that has no exam form yet. */
    public static function syncFor(string $session, ?int $programId, ?string $semester): int
    {
        $ids = DB::table('admissions as a')
            ->whereNull('a.deleted_at')
            ->where('a.status', 'active')
            ->whereNotNull('a.student_id')
            ->where('a.academic_year', $session)
            ->when($programId, fn($q) => $q->where('a.program_id', $programId))
            ->when($semester, fn($q) => $q->where('a.semester_no', (int) $semester))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('exam_forms as ef')
                    ->whereColumn('ef.admission_id', 'a.id')
                    ->whereColumn('ef.session_year', 'a.academic_year')
                    ->where('ef.exam_type', 'Regular');
            })
            ->limit(500)
            ->pluck('a.id');

        $n = 0;
        foreach ($ids as $id) {
            if (self::ensureForAdmission((int) $id)) {
                $n++;
            }
        }
        return $n;
    }

    private static function seedPapers(int $formId, object $a, string $sem): void
    {
        $part6 = $a->application_id
            ? DB::table('student_applications')->where('id', $a->application_id)->value('part_6')
            : null;
        $part6 = is_string($part6) ? (json_decode($part6, true) ?: []) : (array) $part6;

        $rows = collect();
        $paperIds = collect([$part6['compulsory_paper_ids'] ?? [], $part6['optional_paper_ids'] ?? [], $part6['teaching_paper_ids'] ?? []])
            ->flatten()->filter()->map(fn($v) => (int) $v)->unique()->all();

        if ($paperIds) {                       // PG / B.Ed.: explicit papers
            $rows = DB::table('subject_papers')->whereIn('id', $paperIds)->get(['subject_id', 'paper_code']);
        } else {                               // UG: all papers of the chosen subjects
            $subjectIds = Part6Rules::subjectSlots($part6);
            if ($subjectIds) {
                $rows = DB::table('subject_papers')
                    ->whereIn('subject_id', $subjectIds)
                    ->where('program_id', $a->program_id)
                    ->where('session_year', $a->academic_year)
                    ->where('semester_no', $sem)
                    ->get(['subject_id', 'paper_code']);
            }
        }

        foreach ($rows->filter(fn($r) => !empty($r->paper_code))->unique('paper_code') as $r) {
            DB::table('exam_form_papers')->insert([
                'exam_form_id' => $formId,
                'subject_id' => $r->subject_id,
                'paper_code' => $r->paper_code,
                'exam_type' => 'Regular',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
