<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * The academic session the college is in today, as "YYYY-YYYY".
 *
 * One rule for the whole backend, matching the frontend
 * (src/lib/yearSystem.tsx): the session starts in June. Before this, the
 * fallbacks disagreed — calendar year in some controllers, April or July in
 * others — so a request without an explicit session could land in a
 * different session than the screen showed.
 */
class AcademicSession
{
    /** First month of a session (6 = June). Keep in step with SESSION_START_MONTH in yearSystem.tsx. */
    public const START_MONTH = 6;

    public static function startYear(?Carbon $date = null): int
    {
        $date ??= Carbon::now();
        return $date->month >= self::START_MONTH ? $date->year : $date->year - 1;
    }

    public static function current(?Carbon $date = null): string
    {
        $start = self::startYear($date);
        return $start . '-' . ($start + 1);
    }
}
