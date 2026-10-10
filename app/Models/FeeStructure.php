<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One row = one (organization, program, semester, academic_year,
 * admission_type, CATEGORY, sdpgc_student, ddu_affiliated). Category is a
 * real row-level column — one program/semester/session/admission-type
 * combination has up to 5 rows: gen, obc, sc, st, ews. `amount_json` on
 * each row holds every fee particular's amount for every GENDER within
 * that category:
 *
 *   { "male": { "<fee_head_id>": 1850, ... },
 *     "female": { "<fee_head_id>": 1850, ... },
 *     "transgender": { "<fee_head_id>": 1850, ... } }
 *
 * keyed by the real fee_heads.id so every amount stays genuinely traceable
 * to one specific particular. See the 2026_09_16_090000 migration header
 * for the full history/rationale.
 *
 * There is no `total_amount` column — a rollup number isn't useful here.
 * Every consumer computes a student's actual required fee live via
 * requiredFeeFor()/breakdownFor() below: filter to the student's own
 * category (which ROW) then read amount_json[gender] (which KEY) and sum
 * across fee heads.
 *
 * fee_ref_id is one per row = one per (course + category), e.g. "BAGEN001"
 * — no gender component, since a row spans every gender in its category.
 */
class FeeStructure extends Model
{
    protected $fillable = [
        'organization_id', 'program_id', 'semester_no',
        'academic_year', 'admission_type', 'category', 'amount_json',
        'term', 'sdpgc_student', 'ddu_affiliated',
        'late_fine_per_day', 'due_date', 'is_active', 'fee_ref_id',
    ];

    protected $casts = [
        'due_date'          => 'date',
        'is_active'         => 'boolean',
        'sdpgc_student'     => 'boolean',
        'ddu_affiliated'    => 'boolean',
        'late_fine_per_day' => 'decimal:2',
        'amount_json'       => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    // No feeHead() relation — fee_head_id is not a column on this table
    // (every fee particular lives inside amount_json, keyed by
    // fee_heads.id as a string). Look fee-head names up via
    // fee_heads::whereIn('id', array_keys($row->amount_json['male'] ?? []))
    // ->pluck('name', 'id') or similar, per caller's need.

    /** Map a student's own category onto fee_structures' row-level spelling. 'general' -> 'gen'; everything else passes through lowercased. */
    public static function normalizeCategory(?string $category): string
    {
        $category = strtolower((string) ($category ?? 'general'));
        return $category === 'general' ? 'gen' : $category;
    }

    /** Map a student's own gender onto amount_json's key spelling. 'other' -> 'transgender'; everything else passes through lowercased. */
    public static function normalizeGender(?string $gender): string
    {
        $gender = strtolower((string) ($gender ?? 'male'));
        return $gender === 'other' ? 'transgender' : $gender;
    }

    /**
     * Sum one gender's amount across every fee particular in a set of
     * decoded amount_json blobs (each blob = one row's amount_json, already
     * scoped to one category by the caller). Multiple blobs happen when
     * more than one row matches a lookup (e.g. distinct sdpgc_student/
     * ddu_affiliated variants for the same program+semester+admission_type
     * +category) — their amounts are summed, same additive semantics the
     * old SQL SUM() had.
     */
    public static function sumFromGenderBlobs(array $blobs, string $gender): float
    {
        $total = 0.0;
        foreach ($blobs as $blob) {
            $heads = (array) ($blob[$gender] ?? []);
            foreach ($heads as $amount) {
                $total += (float) $amount;
            }
        }
        return $total;
    }

    /**
     * A single student's required fee for one program/session/admission-type
     * /category, across the given semester numbers (callers typically pass
     * [0, actual] — semester_no 0 means "applies to every semester").
     * Category picks WHICH row(s); gender then picks the amount_json key
     * within them.
     */
    public static function requiredFeeFor(
        int $programId,
        string $academicYear,
        array $semesterNos,
        string $admissionType,
        string $gender,
        string $category
    ): float {
        $blobs = DB::table('fee_structures')
            ->where('program_id', $programId)
            ->where('academic_year', $academicYear)
            ->whereIn('semester_no', $semesterNos)
            ->where('admission_type', $admissionType)
            ->where('category', $category)
            ->where('is_active', true)
            ->pluck('amount_json')
            ->map(fn ($j) => $j ? (is_string($j) ? json_decode($j, true) : $j) : [])
            ->all();

        return self::sumFromGenderBlobs($blobs, $gender);
    }

    /**
     * Fee-particular breakdown for one student's category+gender, across
     * every row matching the given program/session/semesters/admission-type
     * /category. Optionally restricted to a specific set of fee_head_ids
     * (e.g. only the back-paper subjects a student selected); pass null for
     * every particular in the row(s). Particulars with a zero amount for
     * this gender are omitted.
     */
    public static function breakdownFor(
        int $programId,
        string $academicYear,
        array $semesterNos,
        string $admissionType,
        string $gender,
        string $category,
        ?array $feeHeadIds = null
    ): array {
        $rows = DB::table('fee_structures')
            ->where('program_id', $programId)
            ->where('academic_year', $academicYear)
            ->whereIn('semester_no', $semesterNos)
            ->where('admission_type', $admissionType)
            ->where('category', $category)
            ->where('is_active', true)
            ->get(['amount_json']);

        $breakdown = [];
        $total = 0.0;

        foreach ($rows as $row) {
            $amountJson = $row->amount_json ? json_decode($row->amount_json, true) : [];
            $heads = (array) (((array) $amountJson)[$gender] ?? []);
            foreach ($heads as $feeHeadId => $amount) {
                $feeHeadId = (int) $feeHeadId;
                if ($feeHeadIds !== null && !in_array($feeHeadId, $feeHeadIds, true)) {
                    continue;
                }
                $amount = (float) $amount;
                if ($amount <= 0) {
                    continue;
                }
                $breakdown[] = ['fee_head_id' => $feeHeadId, 'amount' => $amount];
                $total += $amount;
            }
        }

        return ['total' => $total, 'breakdown' => $breakdown];
    }

    /**
     * Bulk-fetch every active row's decoded amount_json for one academic
     * year (optionally scoped to a program), grouped by
     * "program_id|semester_no|admission_type|category" — for aggregate
     * reports (financial summary, by-class/by-semester breakdowns) that
     * need many students' required fees without one query per student.
     * Each blob is still gender-keyed; sum with sumFromGenderBlobs().
     */
    public static function requiredFeeBlobMap(string $academicYear, ?int $programId = null): array
    {
        $map = [];
        DB::table('fee_structures')
            ->where('academic_year', $academicYear)
            ->where('is_active', true)
            ->when($programId, fn ($q) => $q->where('program_id', $programId))
            ->get(['program_id', 'semester_no', 'admission_type', 'category', 'amount_json'])
            ->each(function ($row) use (&$map) {
                $key = $row->program_id . '|' . $row->semester_no . '|' . $row->admission_type . '|' . $row->category;
                $map[$key][] = $row->amount_json ? json_decode($row->amount_json, true) : [];
            });

        return $map;
    }
}
