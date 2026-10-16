<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per semester (1..10) — the single source of truth for the semester
 * list and its ODD/EVEN parity. Seeded by SemesterSeeder; read-only in the UI.
 *
 * Display label used across the whole app: "Semester 1 (ODD)".
 */
class SemesterMaster extends Model
{
    protected $fillable = ['semester_num', 'semester_name', 'academic_cycle', 'research_paper', 'status'];

    /** academic_cycle values and their labels. */
    public const ACADEMIC_CYCLES = [
        'JUL_DEC' => 'July-December (ODD)',
        'JAN_JUN' => 'January-June (EVEN)',
    ];

    protected $casts = ['semester_num' => 'integer', 'research_paper' => 'boolean'];

    protected $appends = ['label'];

    /**
     * Canonical display label. Never build this string by hand elsewhere.
     */
    public function getLabelAttribute(): string
    {
        return "Semester {$this->semester_num} ({$this->semester_name})";
    }

    /**
     * Parity for a semester number, without a DB round-trip.
     */
    public static function parityFor(int $semesterNo): string
    {
        return $semesterNo % 2 === 1 ? 'ODD' : 'EVEN';
    }

    /**
     * Active semesters, ordered. Used by the settings API and every dropdown.
     */
    /** Does U.G. Part 6 offer a Research Paper in this semester? */
    public static function researchAllowed($semesterNo): bool
    {
        return (bool) self::where('semester_num', (int) $semesterNo)->value('research_paper');
    }

    public static function active()
    {
        return self::where('status', 'Active')->orderBy('semester_num')->get();
    }
}
