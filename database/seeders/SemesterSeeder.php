<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds semesters 1..10 with their parity.
 *
 * This table is the ONLY place semester numbers and ODD/EVEN live. Every
 * dropdown in the app reads it via GET /settings/course/semesters, so the
 * list is not hardcoded anywhere in PHP or TSX.
 *
 * Idempotent (upsert on semester_num) — safe on both `migrate:fresh --seed`
 * and a re-run of `db:seed` against a populated database.
 */
class SemesterSeeder extends Seeder
{
    public const MAX_SEMESTER = 10;

    public function run(): void
    {
        $now  = now();
        $rows = [];

        for ($n = 1; $n <= self::MAX_SEMESTER; $n++) {
            $rows[] = [
                'semester_num'  => $n,
                'semester_name' => $n % 2 === 1 ? 'ODD' : 'EVEN',
                'status'        => 'Active',
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        DB::table('semester_masters')->upsert(
            $rows,
            ['semester_num'],                       // conflict target
            ['semester_name', 'status', 'updated_at'] // columns to refresh
        );
    }
}
