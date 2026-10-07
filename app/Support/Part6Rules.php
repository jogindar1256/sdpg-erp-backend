<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "which Part 6 (Subject & Paper Selection) form
 * does this course use, and what does a complete selection look like".
 *
 * Replaces three hand-copied versions of the same level/short-name checks
 * (ApplicationController::part6FormType, MasterSettingsController::
 * programIsBed / allowedSelectionTypes) and the slot constants. The
 * frontend mirror is src/lib/programKind.ts — keep the two in step.
 *
 * Form types: 'BED' (B.Ed.), 'PG' (P.G.), null (U.G. and everything else).
 */
class Part6Rules
{
    public const SELECTION_TYPES = ['Compulsory', 'Optional', 'Teaching Subject'];
    public const OPTIONAL_SLOTS = ['PG' => 1, 'BED' => 2];
    public const TEACHING_SLOTS = 2;
    public const TEACHING_FROM_SEMESTER = 3;

    public static function isBed($program): bool
    {
        if (!$program) return false;
        if (strcasecmp((string) ($program->level ?? ''), 'BEd') === 0) return true;
        $norm = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($program->short_name ?? '')));
        return str_contains($norm, 'BED');
    }

    public static function isPg($program): bool
    {
        return $program && !self::isBed($program) && strcasecmp((string) ($program->level ?? ''), 'PG') === 0;
    }

    /** 'BED' | 'PG' | null (null = U.G. form). */
    public static function formType($program): ?string
    {
        if (self::isBed($program)) return 'BED';
        if (self::isPg($program)) return 'PG';
        return null;
    }

    /** Selection types a course may use on Subject Paper Master — empty = not applicable. */
    public static function allowedSelectionTypes($program): array
    {
        if (self::isBed($program)) return self::SELECTION_TYPES;
        if (self::isPg($program)) return ['Compulsory', 'Optional'];
        return [];
    }

    public static function teachingAllowed(?string $formType, $semesterNo): bool
    {
        return $formType === 'BED' && (int) $semesterNo >= self::TEACHING_FROM_SEMESTER;
    }

    /** Papers on offer for this application (PG: only the chosen subject's). */
    private static function papersFor(object $app, ?string $formType, ?int $subjectId)
    {
        return DB::table('subject_papers')
            ->where('program_id', $app->program_id)
            ->where('semester_no', (string) $app->semester_no)
            ->when(!empty($app->academic_year), fn($q) => $q->where('session_year', $app->academic_year))
            ->when($formType === 'PG', fn($q) => $q->where('subject_id', $subjectId ?? 0))
            ->orderBy('id')
            ->get(['id', 'subject_id', 'selection_type']);
    }

    private static function ids($v): array
    {
        return is_array($v) ? array_values(array_unique(array_map('intval', array_filter($v, fn($x) => $x !== null && $x !== '')))) : [];
    }

    /**
     * Is Part 6 genuinely complete? "part_6 exists" is not enough — a Save
     * Draft with nothing picked still writes an object.
     *
     * Only applies to fresh ('regular') applications; back-paper / semester-
     * upgrade applications are filled by the office in a different shape, so
     * for them "something saved" stays the bar (unchanged behaviour).
     *
     * Required counts come from what is actually CONFIGURED for the
     * course/semester/session, so a course whose papers aren't set up yet
     * never leaves an applicant permanently stuck.
     */
    public static function isComplete(object $app, $program, array $part6): bool
    {
        if (empty($part6)) return false;
        if (($app->application_type ?? 'regular') !== 'regular') return true;

        $formType = self::formType($program);

        if ($formType === null) {
            return !empty($part6['major_subject_1'])
                && !empty($part6['major_subject_2'])
                && !empty($part6['minor_subject_1']);
        }

        $subjectId = null;
        if ($formType === 'PG') {
            $subjectId = !empty($part6['subject_id']) ? (int) $part6['subject_id'] : null;
            if (!$subjectId) return false;
        }

        $papers = self::papersFor($app, $formType, $subjectId);
        $idsOf = fn(string $t) => $papers->where('selection_type', $t)->pluck('id')->map(fn($v) => (int) $v)->all();

        $compulsory = $idsOf('Compulsory');
        if (count(array_diff($compulsory, self::ids($part6['compulsory_paper_ids'] ?? []))) > 0) return false;

        $optional = $idsOf('Optional');
        $needOptional = min(self::OPTIONAL_SLOTS[$formType], count($optional));
        if (count(array_intersect(self::ids($part6['optional_paper_ids'] ?? []), $optional)) < $needOptional) return false;

        if (self::teachingAllowed($formType, $app->semester_no)) {
            $teaching = $idsOf('Teaching Subject');
            $needTeaching = min(self::TEACHING_SLOTS, count($teaching));
            if (count(array_intersect(self::ids($part6['teaching_paper_ids'] ?? []), $teaching)) < $needTeaching) return false;
        }

        return true;
    }

    /**
     * Office save of a P.G./B.Ed. Part 6. The office may pick any row
     * (including compulsory papers and the P.G. subject) but every id must be
     * a real paper of the right type for THIS course/semester/session, within
     * the slot limits. Returns [normalisedData, null] or [null, errorMessage].
     * Non-P.G./B.Ed. courses are passed through untouched.
     */
    public static function validateOffice(object $app, $program, array $data): array
    {
        $formType = self::formType($program);
        if (!$formType) return [$data, null];

        $reg = !empty($app->direct_registration_id)
            ? DB::table('direct_registrations')->where('id', $app->direct_registration_id)->first()
            : null;

        $subjectId = null;
        if ($formType === 'PG') {
            $subjectId = $data['subject_id'] ?? ($reg->subject_id ?? $reg->major_subject_1 ?? null);
            $subjectId = $subjectId ? (int) $subjectId : null;
            if (!empty($data['subject_id']) && !DB::table('subjects')->where('id', $subjectId)->where('program_id', $app->program_id)->exists()) {
                return [null, 'The selected subject does not belong to this course.'];
            }
        }

        $papers = self::papersFor($app, $formType, $subjectId);
        $idsOf = fn(string $t) => $papers->where('selection_type', $t)->pluck('id')->map(fn($v) => (int) $v)->all();

        $groups = [
            'compulsory_paper_ids' => ['Compulsory', 'Compulsory', PHP_INT_MAX],
            'optional_paper_ids' => ['Optional', 'Optional', self::OPTIONAL_SLOTS[$formType]],
            'teaching_paper_ids' => ['Teaching Subject', 'Teaching Subject', self::teachingAllowed($formType, $app->semester_no) ? self::TEACHING_SLOTS : 0],
        ];

        $clean = [];
        foreach ($groups as $key => [$label, $type, $max]) {
            $submitted = self::ids($data[$key] ?? []);
            $allowed = $idsOf($type);
            $bad = array_diff($submitted, $allowed);
            if ($bad) {
                return [null, "Invalid {$label} paper selection: paper id(s) " . implode(', ', $bad) . ' are not available for this course, semester and session.'];
            }
            if (count($submitted) > $max) {
                return [null, $max === 0
                    ? "{$label} papers are not allowed for this course/semester."
                    : "At most {$max} {$label} paper(s) can be selected."];
            }
            $clean[$key] = $submitted;
        }

        $all = array_values(array_merge($clean['compulsory_paper_ids'], $clean['optional_paper_ids'], $clean['teaching_paper_ids']));
        $selectedSubjects = $formType === 'PG'
            ? ($subjectId ? [$subjectId] : [])
            : $papers->whereIn('id', $all)->pluck('subject_id')->unique()->map(fn($v) => (int) $v)->values()->all();

        return [array_merge($data, [
            'form_type' => $formType,
            'subject_id' => $subjectId,
            'stream' => $formType === 'BED' ? ($data['stream'] ?? $reg->stream ?? null) : null,
            'compulsory_paper_ids' => $clean['compulsory_paper_ids'],
            'optional_paper_ids' => $clean['optional_paper_ids'],
            'teaching_paper_ids' => $clean['teaching_paper_ids'],
            'selected_papers' => $all,
            'selected_subjects' => $selectedSubjects,
        ]), null];
    }
}
