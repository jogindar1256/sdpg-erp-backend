<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\ApplicationController;
use App\Support\Part6Rules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only: explains why an application's fee is what it is, in particular the
 * practical-fee lines. Usage: php artisan fees:explain 2627020001
 */
class ExplainFee extends Command
{
    protected $signature = 'fees:explain {registration_no : Registration number of the student}';
    protected $description = 'Read-only: show the chosen subjects, their practical settings and the computed fee lines';

    public function handle(): int
    {
        $regNo = (string) $this->argument('registration_no');
        $reg = DB::table('direct_registrations')->where('registration_no', $regNo)->first();
        if (!$reg) {
            $this->error("No registration {$regNo}.");
            return self::FAILURE;
        }
        $sa = DB::table('student_applications')->where('direct_registration_id', $reg->id)->orderByDesc('id')->first();
        if (!$sa) {
            $this->error('Registration has no application yet.');
            return self::FAILURE;
        }
        foreach (['part_1', 'part_4', 'part_6'] as $p) {
            if (is_string($sa->{$p} ?? null)) {
                $sa->{$p} = json_decode($sa->{$p}, true) ?: [];
            }
        }
        $this->info("Application {$sa->application_no} — program {$sa->program_id}, session {$sa->academic_year}, semester " . ($sa->semester_no ?? 'null'));

        $ids = array_map('intval', Part6Rules::subjectSlots($sa->part_6 ?? []));
        $this->line('Subject ids in application part_6: ' . ($ids ? implode(', ', $ids) : '(none)'));
        $regIds = array_map('intval', Part6Rules::subjectSlots($reg));
        $this->line('Subject ids in registration:       ' . ($regIds ? implode(', ', $regIds) : '(none)'));

        $rows = [];
        foreach (array_unique(array_merge($ids, $regIds)) as $id) {
            $s = DB::table('subjects')->where('id', $id)->first();
            $allotted = DB::table('allotted_subjects')->where('program_id', $sa->program_id)->where('subject_id', $id)->exists();
            $paperTypes = DB::table('subject_papers')->where('subject_id', $id)->pluck('paper_type')->unique()->implode('/');
            $rows[] = [
                $id, $s->name ?? '?',
                $s ? ($s->has_practical ? 'yes' : 'NO') : '?',
                $s->practical_fee ?? '?',
                $allotted ? 'yes' : 'NO',
                $paperTypes ?: '-',
            ];
        }
        $this->table(['id', 'subject', 'has_practical', 'practical_fee', 'allotted to program', 'subject_papers types'], $rows);
        $this->line('A practical fee line needs: has_practical = yes, practical_fee > 0 and allotted to the program.');

        $ctl = app(ApplicationController::class);
        $m = new \ReflectionMethod($ctl, 'computeApplicationFee');
        $m->setAccessible(true);
        $fee = $m->invoke($ctl, $sa);
        $this->line('Fee lines (online):');
        foreach ($fee['breakdown'] as $b) {
            $this->line(sprintf('  %-45s %10.2f%s', $b['label'] ?? ('head ' . ($b['fee_head_id'] ?? '?')), $b['amount'], !empty($b['misc']) ? '  (misc)' : ''));
        }
        foreach ($fee['college_breakdown'] as $b) {
            $this->line(sprintf('  %-45s %10.2f  (paid at counselling centre)', 'head ' . ($b['fee_head_id'] ?? '?'), $b['amount']));
        }
        $this->line('Total online: ' . number_format($fee['total'], 2));
        return self::SUCCESS;
    }
}
