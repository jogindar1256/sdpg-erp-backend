<?php

namespace App\Console\Commands;

use App\Models\AcademicStaff;
use App\Support\OrgBrand;
use App\Support\StaffImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off / repeatable import of the "Academic Staff Data" sheet.
 *
 *   php artisan staff:import --dry-run     # parse + report, writes nothing
 *   php artisan staff:import               # import storage/app/imports/academic_staff.csv
 *   php artisan staff:import path/to/other.csv --org=1
 *
 * Deliberately NOT part of DatabaseSeeder — real people's Aadhaar/PAN/bank data
 * must never be seeded into every fresh database. Re-running is safe: rows are
 * matched on (organization, Employee ID = hHRMS ID) and updated, not duplicated.
 */
class ImportAcademicStaff extends Command
{
    protected $signature = 'staff:import {file? : CSV exported from the sheet (default storage/app/imports/academic_staff.csv)}
                            {--org= : organization id (default: the active college)}
                            {--dry-run : validate and report only}';

    protected $description = 'Import academic staff from the Academic Staff Data sheet (CSV export)';

    public function handle(): int
    {
        $file = $this->argument('file') ?: storage_path('app/imports/academic_staff.csv');
        if (!is_file($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        $orgId = $this->option('org') ?: (OrgBrand::current()->id ?? null);
        if (!$orgId || !DB::table('organizations')->where('id', $orgId)->exists()) {
            $this->error('No organization found. Save the Institute Profile first or pass --org=<id>.');
            return self::FAILURE;
        }

        // ── read ────────────────────────────────────────────────────────
        $fh = fopen($file, 'r');
        $head = fgetcsv($fh);
        if (!$head) { $this->error('Empty file.'); return self::FAILURE; }
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $head[0]);
        $rows = [];
        while (($r = fgetcsv($fh)) !== false) {
            if (count(array_filter($r, fn ($v) => trim((string) $v) !== '')) === 0) continue;
            $rows[] = array_combine($head, array_pad(array_slice($r, 0, count($head)), count($head), ''));
        }
        fclose($fh);

        foreach (['hHRMS ID', 'Name', 'Designation', 'Department'] as $need) {
            if (!in_array($need, $head, true)) { $this->error("Column '{$need}' missing in the file."); return self::FAILURE; }
        }

        $desig = StaffImporter::canonicalCase(array_column($rows, 'Designation'));
        $dept = StaffImporter::canonicalCase(array_column($rows, 'Department'));

        // ── normalise ───────────────────────────────────────────────────
        $records = []; $warnings = []; $errors = []; $seen = [];
        foreach ($rows as $i => $raw) {
            $line = $i + 2;
            ['data' => $d, 'warnings' => $w] = StaffImporter::normalise($raw, $desig, $dept);
            foreach ($w as $x) $warnings[] = $x;
            foreach (['employee_id', 'name', 'designation', 'department'] as $req) {
                if (empty($d[$req])) { $errors[] = "line {$line}: {$req} is empty — row skipped"; continue 2; }
            }
            if (isset($seen[$d['employee_id']])) { $errors[] = "line {$line}: duplicate Employee ID {$d['employee_id']} — row skipped"; continue; }
            $seen[$d['employee_id']] = true;
            if ($d['aadhar_no'] && collect($records)->contains(fn ($r) => $r['aadhar_no'] === $d['aadhar_no'])) {
                $warnings[] = "{$d['name']} ({$d['employee_id']}): Aadhaar already used by another row — left empty";
                $d['aadhar_no'] = null;
            }
            $records[] = $d;
        }

        // ── write ───────────────────────────────────────────────────────
        $created = $updated = 0;
        if (!$this->option('dry-run')) {
            DB::transaction(function () use ($records, $orgId, &$created, &$updated) {
                foreach ($records as $d) {
                    $m = AcademicStaff::withTrashed()->where('organization_id', $orgId)->where('employee_id', $d['employee_id'])->first();
                    if ($m) { if ($m->trashed()) $m->restore(); $m->update($d); $updated++; }
                    else { AcademicStaff::create($d + ['organization_id' => $orgId]); $created++; }
                }
            });
        }

        // ── report ──────────────────────────────────────────────────────
        $this->info(sprintf('%s: %d rows read, %d valid%s', $this->option('dry-run') ? 'DRY RUN' : 'Imported', count($rows), count($records),
            $this->option('dry-run') ? '' : " ({$created} created, {$updated} updated)"));
        foreach ($errors as $e) $this->error($e);
        if ($warnings) {
            $this->warn(count($warnings) . ' warning(s) — fix these on the Academic Staff page:');
            foreach ($warnings as $x) $this->line('  • ' . $x);
        }
        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
