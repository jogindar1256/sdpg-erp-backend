<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds the bank_branches lookup catalog from database/seeders/data/bank_branches.csv
 * (~91k rows: the RBI bank-branch list + all UTTAR PRADESH GRAMIN BANK branches).
 *
 * FULL REPLACE, not a merge: the table is truncated and reloaded inside one
 * transaction, so a failure part-way leaves the previous data intact. That is
 * safe because bank_branches is reference data only — nothing foreign-keys to
 * it (applications store denormalized bank_ifsc / bank_branch copies).
 *
 * IFSC is NOT unique here: UTTAR PRADESH GRAMIN BANK uses one shared IFSC
 * (BARB0BUPGBX) for ~4.3k branches. Uniqueness is (ifsc_code, branch_name, city).
 *
 * Run:  php artisan db:seed --class=BankBranchSeeder
 */
class BankBranchSeeder extends Seeder
{
    private const CSV_PATH   = 'seeders/data/bank_branches.csv';
    private const CHUNK_SIZE = 500; // 11 cols x 500 = 5.5k bind params, well under Postgres' 65,535 cap

    private const COLUMNS = [
        'bank_name', 'ifsc_code', 'micr_code', 'branch_name',
        'address', 'city', 'district', 'state', 'phone',
    ];

    public function run(): void
    {
        $path = database_path(self::CSV_PATH);

        if (!is_file($path)) {
            throw new RuntimeException("Bank branch CSV not found at {$path}");
        }

        $handle = fopen($path, 'r');

        $header = fgetcsv($handle);
        // Strip a UTF-8 BOM if the file was re-saved from Excel.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        if ($header !== self::COLUMNS) {
            fclose($handle);
            throw new RuntimeException(
                'Unexpected CSV header. Expected: ' . implode(',', self::COLUMNS)
                . ' — got: ' . implode(',', $header)
            );
        }

        $now   = now()->toDateTimeString(); // plain string, not a Carbon object per row
        $total = 0;

        $connection = DB::connection();
        $connection->disableQueryLog();
        $connection->flushQueryLog();
        $dispatcher = $connection->getEventDispatcher();
        $connection->unsetEventDispatcher();

        try {
            DB::transaction(function () use ($handle, $now, &$total) {
                // RESTART IDENTITY resets the id sequence so reseeding starts at 1.
                DB::statement('TRUNCATE TABLE bank_branches RESTART IDENTITY');

                $batch = [];

                while (($row = fgetcsv($handle)) !== false) {
                    if ($row === [null] || count($row) !== count(self::COLUMNS)) {
                        continue; // blank or malformed line
                    }

                    $record = array_combine(self::COLUMNS, $row);

                    // Empty string -> NULL for nullable columns.
                    foreach (['micr_code', 'address', 'city', 'district', 'phone'] as $col) {
                        if (trim((string) $record[$col]) === '') {
                            $record[$col] = null;
                        }
                    }

                    $record['ifsc_code']  = strtoupper(trim($record['ifsc_code']));
                    $record['created_at'] = $now;
                    $record['updated_at'] = $now;

                    $batch[] = $record;

                    if (count($batch) >= self::CHUNK_SIZE) {
                        DB::table('bank_branches')->insert($batch);
                        $total += count($batch);
                        $batch = [];

                        if ($total % 10000 === 0) {
                            gc_collect_cycles();
                            $this->command?->line(sprintf(
                                '  %s rows  |  memory %.1f MB',
                                number_format($total),
                                memory_get_usage(true) / 1048576
                            ));
                        }
                    }
                }

                if ($batch) {
                    DB::table('bank_branches')->insert($batch);
                    $total += count($batch);
                }
            });
        } finally {
            fclose($handle);
            if ($dispatcher) {
                $connection->setEventDispatcher($dispatcher);
            }
        }

        $this->command?->info("bank_branches seeded: {$total} rows.");
    }
}
