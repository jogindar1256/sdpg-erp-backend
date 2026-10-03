<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'short_name', 'code', 'level',
        'duration_years', 'total_semesters', 'semester_type', 'description', 'is_active',
        // Real columns added by later migrations that were never added here —
        // meant these fields silently dropped out of every mass-assignment
        // (Program::create()/update()) even though the columns existed.
        'full_name', 'course_code', 'is_self_finance', 'approval_type', 'exam_mode',
        // SAMARTH portal's numeric course code — unique among live programs.
        'samarth_code',
    ];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * Display label "<SAMARTH code> : <name>" (e.g. "202 : Master of Arts in
     * Sociology"), or the bare name when the course has no code yet.
     * Accepts an Eloquent Program or a raw DB::table('programs') row, since
     * the PDF views receive both. $kind: 'full' | 'short'.
     */
    public static function label($program, string $kind = 'full'): ?string
    {
        if (!$program) {
            return null;
        }
        $name = $kind === 'short'
            ? ($program->short_name ?? $program->full_name ?? $program->name ?? null)
            : ($program->full_name ?? $program->name ?? $program->short_name ?? null);
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $code = trim((string) ($program->samarth_code ?? ''));
        return $code !== '' ? "{$code} : {$name}" : $name;
    }

    /** File-name-safe form, e.g. "202-B.A." (no colon / slashes). */
    public static function fileSlug($program): string
    {
        $code = trim((string) ($program->samarth_code ?? ''));
        $name = trim((string) ($program->short_name ?? $program->full_name ?? $program->name ?? ''));
        $slug = implode('-', array_filter([$code, $name], fn ($v) => $v !== ''));
        return preg_replace('#[\\\\/:*?"<>|]+#', '-', $slug) ?: 'Class';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    public function feeStructures(): HasMany
    {
        return $this->hasMany(FeeStructure::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(StudentApplication::class);
    }

    // Get subjects for a specific semester
    public function semesterSubjects(int $semesterNo): HasMany
    {
        return $this->subjects()->where('semester_no', $semesterNo);
    }
}
