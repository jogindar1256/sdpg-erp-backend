<?php

namespace App\Http\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * `students` only has first_name/middle_name/last_name (+ date_of_birth,
 * permanent_*) — it has no name/father_name/mother_name/dob/spouse_name/
 * address columns. Those identity fields (given at self-registration) live
 * on `direct_registrations` instead. Every controller that used to select
 * `s.name`/`s.father_name`/etc. directly off `students` was querying columns
 * that don't exist and would throw `column s.name does not exist` the
 * moment that code path was hit.
 *
 * This trait centralizes the fix: a reusable subquery to join the latest
 * direct_registrations row per user, and a helper to compose a fallback
 * name from first/middle/last when no registration snapshot matched.
 */
trait ResolvesStudentIdentity
{
    /**
     * Subquery: latest (non-deleted) direct_registrations row per user_id.
     * Use with leftJoinSub(..., 'lr', 'lr.user_id', 's.user_id') then
     * leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id').
     */
    protected function latestRegistrationSub()
    {
        return DB::table('direct_registrations')
            ->select('user_id', DB::raw('MAX(id) as reg_id'))
            ->whereNull('deleted_at')
            ->groupBy('user_id');
    }

    /**
     * Subquery: latest (non-deleted) student_applications row per
     * student_id, optionally filtered to one application_type (e.g.
     * 'fresh'). Use with leftJoinSub(..., 'la', 'la.student_id', 's.id')
     * then leftJoin('student_applications as sa', 'sa.id', 'la.app_id').
     */
    protected function latestApplicationSub(?string $applicationType = null)
    {
        return DB::table('student_applications')
            ->select('student_id', DB::raw('MAX(id) as app_id'))
            ->when($applicationType, fn($q) => $q->where('application_type', $applicationType))
            ->whereNull('deleted_at')
            ->groupBy('student_id');
    }

    /**
     * Fill in `$row->name` from first_name/middle_name/last_name when the
     * direct_registrations join didn't match (row->name came back null).
     * Call after selecting s.first_name, s.middle_name, s.last_name, dr.name.
     */
    protected function withComposedName($row)
    {
        if (empty($row->name)) {
            $row->name = trim(implode(' ', array_filter([
                $row->first_name ?? null,
                $row->middle_name ?? null,
                $row->last_name ?? null,
            ])));
        }
        return $row;
    }
}
