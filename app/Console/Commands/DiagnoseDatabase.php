<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only diagnostic snapshot of the live database, built to answer the
 * open questions from this engagement's recent work — none of it writes
 * anything. Run it, then hand the JSON file it writes back over (it lands
 * inside the project, in the connected workspace folder, so it can just be
 * read directly without you pasting anything).
 **/
class DiagnoseDatabase extends Command
{
    protected $signature = 'diagnose:db {--out=storage/app/db-diagnostic-report.json : Where to write the JSON report}';

    protected $description = 'Read-only snapshot of live DB state for the current engagement\'s open questions. Writes nothing.';

    public function handle(): int
    {
        $report = [
            'generated_at' => now()->toDateTimeString(),
            'migrations' => $this->migrations(),
            'sms_templates' => $this->smsTemplates(),
            'student_applications' => $this->studentApplications(),
            'semester_registrations' => $this->semesterRegistrations(),
            'direct_registrations' => $this->directRegistrations(),
            'admissions' => $this->admissions(),
            'students' => $this->students(),
            'fee_receipts' => $this->feeReceipts(),
            'subject_selections' => $this->subjectSelections(),
        ];

        $path = $this->option('out');
        $fullPath = str_starts_with($path, '/') ? $path : base_path($path);
        @mkdir(dirname($fullPath), 0775, true);
        file_put_contents($fullPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("Report written to: {$fullPath}");
        $this->newLine();
        $this->printSummary($report);

        return self::SUCCESS;
    }

    private function migrations(): array
    {
        $rows = DB::table('migrations')
            ->where('migration', 'like', '2026_09%')
            ->orWhere('migration', 'like', '2026_10%')
            ->orderBy('migration')
            ->get(['migration', 'batch']);

        return [
            'recent_sept_oct_migrations_applied' => $rows->pluck('batch', 'migration'),
            'rename_fresh_to_regular_applied' => $rows->contains('migration', '2026_09_30_100000_rename_fresh_to_regular'),
            'identity_unique_constraints_applied' => $rows->contains('migration', '2026_09_29_100000_add_identity_unique_constraints'),
        ];
    }

    private function smsTemplates(): array
    {
        $rows = DB::table('sms_templates')->get(['organization_id', 'event_trigger', 'is_active', 'dlt_template_id']);
        $watched = ['registration_success', 'application_approved', 'application_rejected', 'application_hold'];

        $byOrg = [];
        foreach ($rows->groupBy('organization_id') as $orgId => $orgRows) {
            $present = $orgRows->pluck('event_trigger')->all();
            $byOrg[$orgId] = [
                'total_templates' => $orgRows->count(),
                'watched_triggers_status' => collect($watched)->mapWithKeys(function ($trigger) use ($orgRows) {
                    $row = $orgRows->firstWhere('event_trigger', $trigger);
                    return [$trigger => $row
                        ? ['exists' => true, 'is_active' => (bool) $row->is_active, 'has_dlt_id' => !empty($row->dlt_template_id)]
                        : ['exists' => false]];
                }),
            ];
        }

        return ['by_organization' => $byOrg];
    }

    private function studentApplications(): array
    {
        $byType = DB::table('student_applications')->whereNull('deleted_at')
            ->select('application_type', DB::raw('count(*) as n'))->groupBy('application_type')->pluck('n', 'application_type');
        $byStatus = DB::table('student_applications')->whereNull('deleted_at')
            ->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
        $leftoverFreshOrLateral = DB::table('student_applications')->whereNull('deleted_at')
            ->whereIn('application_type', ['fresh', 'lateral'])->count();
        $studentIdNullByStatus = DB::table('student_applications')->whereNull('deleted_at')
            ->whereNull('student_id')
            ->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        // The smoking-gun check: fee marked paid but no admissions row exists
        // for that application — should always be zero given the
        // transaction wrapping in doApplicationPayVerify().
        $paidButNoAdmission = DB::table('student_applications as sa')
            ->whereNull('sa.deleted_at')
            ->where('sa.fee_paid', true)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('admissions as a')->whereColumn('a.application_id', 'sa.id');
            })
            ->count();

        return [
            'by_application_type' => $byType,
            'by_status' => $byStatus,
            'leftover_fresh_or_lateral_values' => $leftoverFreshOrLateral,
            'student_id_null_by_status' => $studentIdNullByStatus,
            'RED_FLAG_fee_paid_but_no_admission_row' => $paidButNoAdmission,
        ];
    }

    private function semesterRegistrations(): array
    {
        $byType = DB::table('semester_registrations')
            ->select('registration_type', DB::raw('count(*) as n'))->groupBy('registration_type')->pluck('n', 'registration_type');
        $exStudentLeftover = DB::table('semester_registrations')->where('registration_type', 'ex_student')->count();

        return [
            'by_registration_type' => $byType,
            'leftover_ex_student_values' => $exStudentLeftover,
        ];
    }

    private function directRegistrations(): array
    {
        $byStatus = DB::table('direct_registrations')->whereNull('deleted_at')
            ->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
        $nullOrg = DB::table('direct_registrations')->whereNull('deleted_at')->whereNull('organization_id')->count();
        $nullCode = DB::table('direct_registrations')->whereNull('deleted_at')->whereNull('unique_code')->count();

        return [
            'by_status' => $byStatus,
            'null_organization_id' => $nullOrg,
            'null_unique_code' => $nullCode,
        ];
    }

    private function admissions(): array
    {
        $byYear = DB::table('admissions')->whereNull('deleted_at')
            ->select('academic_year', DB::raw('count(*) as n'))->groupBy('academic_year')->orderBy('academic_year')->pluck('n', 'academic_year');
        $total = DB::table('admissions')->whereNull('deleted_at')->count();
        $nullStudentId = DB::table('admissions')->whereNull('deleted_at')->whereNull('student_id')->count();
        $orphanedApplicationRef = DB::table('admissions as a')->whereNull('a.deleted_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('student_applications as sa')->whereColumn('sa.id', 'a.application_id');
            })->count();

        return [
            'total' => $total,
            'by_academic_year' => $byYear,
            'RED_FLAG_null_student_id' => $nullStudentId,
            'RED_FLAG_orphaned_application_id' => $orphanedApplicationRef,
        ];
    }

    private function students(): array
    {
        $total = DB::table('students')->whereNull('deleted_at')->count();
        $nullUserId = DB::table('students')->whereNull('deleted_at')->whereNull('user_id')->count();
        $noAdmissionAtAll = DB::table('students as s')->whereNull('s.deleted_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('admissions as a')->whereColumn('a.student_id', 's.id')->whereNull('a.deleted_at');
            })->count();

        return [
            'total' => $total,
            'null_user_id' => $nullUserId,
            'students_with_zero_admissions' => $noAdmissionAtAll,
        ];
    }

    private function feeReceipts(): array
    {
        $total = DB::table('fee_receipts')->count();
        $nullStudentId = DB::table('fee_receipts')->whereNull('student_id')->count();
        $byType = DB::table('fee_receipts')->select('receipt_type', DB::raw('count(*) as n'))->groupBy('receipt_type')->pluck('n', 'receipt_type');

        return [
            'total' => $total,
            'RED_FLAG_null_student_id' => $nullStudentId,
            'by_receipt_type' => $byType,
        ];
    }

    private function subjectSelections(): array
    {
        // Subjects sitting in more than one group for the same program+semester
        // — not a bug (the check was deliberately removed), just visibility
        // into whether it's actually being used that way yet.
        $multiGroup = DB::table('subject_selections')
            ->select('program_id', 'semester_no', 'subject_id', DB::raw('count(DISTINCT group_label) as group_count'))
            ->groupBy('program_id', 'semester_no', 'subject_id')
            ->havingRaw('count(DISTINCT group_label) > 1')
            ->get();

        return [
            'subjects_in_multiple_groups' => $multiGroup->count(),
            'detail' => $multiGroup,
        ];
    }

    private function printSummary(array $report): void
    {
        $this->line('<fg=yellow>── Migrations ──</>');
        $this->line('  rename_fresh_to_regular applied: ' . ($report['migrations']['rename_fresh_to_regular_applied'] ? 'YES' : 'NO'));
        $this->line('  identity_unique_constraints applied: ' . ($report['migrations']['identity_unique_constraints_applied'] ? 'YES' : 'NO'));

        $this->newLine();
        $this->line('<fg=yellow>── SMS Templates (watched triggers) ──</>');
        foreach ($report['sms_templates']['by_organization'] as $orgId => $data) {
            $this->line("  Org {$orgId}:");
            foreach ($data['watched_triggers_status'] as $trigger => $status) {
                $mark = ($status['exists'] ?? false) && ($status['is_active'] ?? false) ? 'OK' : 'MISSING/INACTIVE';
                $this->line("    {$trigger}: {$mark}");
            }
        }

        $this->newLine();
        $this->line('<fg=yellow>── Red flags ──</>');
        $this->line('  student_applications fee_paid but no admission row: ' . $report['student_applications']['RED_FLAG_fee_paid_but_no_admission_row']);
        $this->line('  admissions with null student_id: ' . $report['admissions']['RED_FLAG_null_student_id']);
        $this->line('  admissions with orphaned application_id: ' . $report['admissions']['RED_FLAG_orphaned_application_id']);
        $this->line('  fee_receipts with null student_id: ' . $report['fee_receipts']['RED_FLAG_null_student_id']);
        $this->line('  leftover fresh/lateral application_type values: ' . $report['student_applications']['leftover_fresh_or_lateral_values']);
        $this->line('  leftover ex_student registration_type values: ' . $report['semester_registrations']['leftover_ex_student_values']);

        $this->newLine();
        $this->info('Full detail in the JSON file above.');
    }
}
