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
    protected $fillable = ['semester_num', 'semester_name', 'status'];

    protected $casts = ['semester_num' => 'integer'];

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
    public static function active()
    {
        return self::where('status', 'Active')->orderBy('semester_num')->get();
    }
}
