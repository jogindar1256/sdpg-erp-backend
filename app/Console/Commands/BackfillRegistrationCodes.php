<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\StudentRegistrationController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off backfill for the &code= gate added after direct_registrations and
 * student_applications already had real data in them:
 *
 *   1. Any direct_registrations row with unique_code still NULL gets one
 *      generated now, via the exact same format StudentRegistrationController
 *      ::generateUniqueCode() uses for brand-new registrations.
 *   2. Any student_applications row with direct_registration_id still NULL
 *      gets matched (best-effort, by user_id + program_id + academic_year)
 *      to its owning registration and linked.
 *
 * Without this, every pre-existing registration/application predates the
 * code column entirely — ApplicationController::rejectIfCodeInvalid() treats
 * "no linked registration" as "nothing to check", which quietly exempts old
 * records from the gate forever instead of actually protecting them.
 *
 * Usage:
 *   php artisan registrations:backfill-codes            (apply changes)
 *   php artisan registrations:backfill-codes --dry-run   (report only)
 */
class BackfillRegistrationCodes extends Command
{
    protected $signature = 'registrations:backfill-codes {--dry-run : Report what would change without writing anything}';

    protected $description = 'Backfill unique_code on old direct_registrations rows and link old student_applications rows to their registration.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->backfillUniqueCodes($dryRun);
        $this->linkApplications($dryRun);

        if ($dryRun) {
            $this->warn('Dry run — nothing was written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    private function backfillUniqueCodes(bool $dryRun): void
    {
        $missing = DB::table('direct_registrations')->whereNull('unique_code')->get();
        $this->info("direct_registrations missing unique_code: {$missing->count()}");

        $done = 0;
        $updates = [];
        $used = [];
        foreach ($missing as $reg) {
            // Codes are written in one batch at the end, so also avoid codes
            // already handed out in this run (the generator only checks the DB).
            do {
                $code = StudentRegistrationController::generateUniqueCode(
                    $reg->reg_type,
                    $reg->program_id,
                    $reg->session_year,
                );
            } while (isset($used[$code]));
            $used[$code] = true;

            $this->line(" - #{$reg->id} ({$reg->registration_no}, {$reg->session_year} {$reg->reg_type}) -> {$code}");

            $updates[] = ['id' => $reg->id, 'unique_code' => $code];
            $done++;
        }
        if (!$dryRun) {
            \App\Support\BulkWrite::updateMany('direct_registrations', ['id'], $updates);
        }

        $this->info("unique_code backfilled on {$done} row(s).");
    }

    private function linkApplications(bool $dryRun): void
    {
        $unlinked = DB::table('student_applications as sa')
            ->join('students as s', 's.id', 'sa.student_id')
            ->whereNull('sa.direct_registration_id')
            ->whereNull('sa.deleted_at')
            ->select('sa.id', 's.user_id', 'sa.program_id', 'sa.academic_year')
            ->get();

        $this->info("student_applications missing direct_registration_id: {$unlinked->count()}");

        // Same matching rule ApplicationController::store() uses for new
        // applications — active (non-cancelled) registration for this
        // exact user + program + session_year, most recent if several.
        // One query for all of them, matched in PHP.
        $regs = DB::table('direct_registrations')
            ->whereIn('user_id', $unlinked->pluck('user_id')->filter()->unique()->values())
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn ($r) => "{$r->user_id}|{$r->program_id}|{$r->session_year}")
            ->map->first();

        $linked = 0;
        $skipped = 0;
        $updates = [];
        foreach ($unlinked as $app) {
            $reg = $regs["{$app->user_id}|{$app->program_id}|{$app->academic_year}"] ?? null;

            if (!$reg) {
                $this->line(" - application #{$app->id}: no matching registration found — left unlinked, stays code-exempt.");
                $skipped++;
                continue;
            }

            $this->line(" - application #{$app->id} -> registration #{$reg->id} ({$reg->unique_code})");
            $updates[] = ['id' => $app->id, 'direct_registration_id' => $reg->id];
            $linked++;
        }
        if (!$dryRun) {
            \App\Support\BulkWrite::updateMany('student_applications', ['id'], $updates);
        }

        $this->info("Linked: {$linked}. Left unlinked (no match found — will keep opening without a code check): {$skipped}.");
    }
}
