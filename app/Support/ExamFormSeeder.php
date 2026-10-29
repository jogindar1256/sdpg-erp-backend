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

    /**
     * Seed every active admission of a session / course / semester that has
     * no exam form yet — as a batch: the forms in one INSERT, every part_6
     * and paper lookup in one query each, all papers in one INSERT (was ~6
     * queries + one INSERT per paper for each of up to 500 admissions, on
     * an Accept Exam Form page load). If the batch fails, falls back to one
     * admission at a time so one bad row can't block the rest.
     */
    public static function syncFor(string $session, ?int $programId, ?string $semester): int
    {
        $admissions = DB::table('admissions as a')
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
            ->get(['a.id', 'a.academic_year', 'a.semester_no', 'a.program_id', 'a.application_id']);

        if ($admissions->isEmpty()) {
            return 0;
        }

        try {
            return DB::transaction(fn () => self::seedBatch($admissions));
        } catch (\Throwable $e) {
            Log::warning('ExamFormSeeder: batch failed, seeding one by one: ' . $e->getMessage());
            $n = 0;
            foreach ($admissions as $a) {
                if (self::ensureForAdmission((int) $a->id)) {
                    $n++;
                }
            }
            return $n;
        }
    }

    /** Same rows ensureForAdmission() + seedPapers() write, for many admissions at once. */
    private static function seedBatch($admissions): int
    {
        $now = now();
        // None of these has a Regular form for the session (the query above),
        // so each gets a new one — as ensureForAdmission() would.
        DB::table('exam_forms')->insert($admissions->map(fn ($a) => [
            'admission_id' => $a->id,
            'session_year' => $a->academic_year,
            'semester_no' => (string) ($a->semester_no ?: 1),
            'exam_type' => 'Regular',
            'status' => 'Pending',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
        $formIds = DB::table('exam_forms')
            ->whereIn('admission_id', $admissions->pluck('id'))
            ->where('exam_type', 'Regular')
            ->orderBy('id')
            ->get(['id', 'admission_id', 'session_year', 'semester_no'])
            ->keyBy(fn ($f) => "{$f->admission_id}|{$f->session_year}|{$f->semester_no}");

        $part6s = DB::table('student_applications')
            ->whereIn('id', $admissions->pluck('application_id')->filter()->unique()->values())
            ->pluck('part_6', 'id')
            ->map(fn ($p) => is_string($p) ? (json_decode($p, true) ?: []) : (array) $p);

        // What each admission needs: PG / B.Ed. explicit paper ids, else UG
        // subject ids (all papers of those subjects for its course/session/sem).
        $plans = [];
        $allPaperIds = [];
        $allSubjectIds = [];
        foreach ($admissions as $a) {
            $part6 = $a->application_id ? ($part6s[$a->application_id] ?? []) : [];
            $paperIds = collect([$part6['compulsory_paper_ids'] ?? [], $part6['optional_paper_ids'] ?? [], $part6['teaching_paper_ids'] ?? []])
                ->flatten()->filter()->map(fn($v) => (int) $v)->unique()->values()->all();
            $subjectIds = $paperIds ? [] : Part6Rules::subjectSlots($part6);
            $plans[$a->id] = [$paperIds, $subjectIds];
            array_push($allPaperIds, ...$paperIds);
            array_push($allSubjectIds, ...$subjectIds);
        }

        $papersById = $allPaperIds
            ? DB::table('subject_papers')->whereIn('id', array_unique($allPaperIds))->get(['id', 'subject_id', 'paper_code'])->keyBy('id')
            : collect();
        $subjectPapers = $allSubjectIds
            ? DB::table('subject_papers')
                ->whereIn('subject_id', array_unique($allSubjectIds))
                ->whereIn('program_id', $admissions->pluck('program_id')->unique()->values())
                ->where('session_year', $admissions->first()->academic_year)
                ->orderBy('id')
                ->get(['subject_id', 'paper_code', 'program_id', 'semester_no'])
            : collect();

        $paperRows = [];
        $seeded = 0;
        foreach ($admissions as $a) {
            $sem = (string) ($a->semester_no ?: 1);
            $form = $formIds["{$a->id}|{$a->academic_year}|{$sem}"] ?? null;
            if (!$form) {
                continue;
            }
            $seeded++;
            [$paperIds, $subjectIds] = $plans[$a->id];
            $rows = $paperIds
                ? collect($paperIds)->map(fn ($id) => $papersById[$id] ?? null)->filter()
                : $subjectPapers->filter(fn ($p) => in_array($p->subject_id, $subjectIds)
                    && (string) $p->program_id === (string) $a->program_id
                    && (string) $p->semester_no === $sem);
            foreach ($rows->filter(fn($r) => !empty($r->paper_code))->unique('paper_code') as $r) {
                $paperRows[] = [
                    'exam_form_id' => $form->id,
                    'subject_id' => $r->subject_id,
                    'paper_code' => $r->paper_code,
                    'exam_type' => 'Regular',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($paperRows, 500) as $chunk) {
            DB::table('exam_form_papers')->insert($chunk);
        }

        return $seeded;
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

        // One INSERT for all the form's papers.
        $now = now();
        $insert = $rows->filter(fn($r) => !empty($r->paper_code))->unique('paper_code')
            ->map(fn ($r) => [
                'exam_form_id' => $formId,
                'subject_id' => $r->subject_id,
                'paper_code' => $r->paper_code,
                'exam_type' => 'Regular',
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all();
        if ($insert) {
            DB::table('exam_form_papers')->insert($insert);
        }
    }
}
