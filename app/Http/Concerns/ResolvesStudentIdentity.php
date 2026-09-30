<?php

namespace App\Http\Concerns;

use Illuminate\Support\Facades\DB;

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
     * 'regular'). Use with leftJoinSub(..., 'la', 'la.student_id', 's.id')
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
